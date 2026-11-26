<?php

namespace App\Services;

use App\Devices\DeviceClient;
use App\Devices\DeviceLock;
use App\Devices\Exceptions\DeviceException;
use App\Enums\SyncStatus;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Offline-first device settings (name, tz, brightness, snooze_min), like AlarmService.
 * A user change is saved on the device row first (`name` + `settings` = desired state), then pushed
 * to the clock right away. If the clock can't be reached it stays `pending` and SyncDeviceSettings
 * pushes it when the clock is back (online transition in the listener, `settings:sync` every minute).
 *
 * The whole desired state is sent on every push (`PUT /api/settings` is idempotent), so several
 * changes made while the clock was offline collapse into one request.
 *
 * A refusal (4xx) is never retried: during a request it is rolled back and the error is returned;
 * in the background the device becomes `failed` with the error for the UI. Status writes are
 * conditional on `settings_revision`, so a push never marks a newer user change as synced.
 */
class DeviceSettingsService
{
    private const LOCK_TTL = 30;

    public function __construct(private readonly DeviceClient $client) {}

    /** @param array{name?:string,tz?:string,brightness?:int,snooze_min?:int} $changes */
    public function update(Device $device, array $changes): Device
    {
        $original = $device->only(['name', 'settings', 'settings_sync_status', 'settings_sync_error']);

        $device->forceFill([
            'name' => $changes['name'] ?? $device->name,
            'settings' => [...($device->settings ?? []), ...array_diff_key($changes, ['name' => true])],
            'settings_sync_status' => SyncStatus::Pending,
            'settings_sync_error' => null,
            'settings_revision' => $device->settings_revision + 1,
        ])->save();

        if ($device->isOnline()) {
            try {
                // If a background push holds the lock, the next one picks this change up.
                DeviceLock::run($this->lockKey($device), self::LOCK_TTL, DeviceLock::REQUEST_WAIT, fn () => $this->push($device));
            } catch (DeviceException $e) {
                if (! $e->isTransient()) {
                    $device->forceFill($original)->save();
                    throw $e;
                }
                Log::info('Settings saved; clock unreachable, will sync later', ['device' => $device->id]);
            }
        }

        return $device->refresh();
    }

    /**
     * Pushes pending settings, holding the device lock. Returns false when there was nothing to do
     * or another push holds the lock for longer than `$wait` seconds.
     *
     * @throws DeviceException when the clock can't be reached; the settings stay pending
     */
    public function sync(Device $device, int $wait = 10): bool
    {
        return DeviceLock::run($this->lockKey($device), self::LOCK_TTL, $wait, function () use ($device) {
            $device->refresh();
            if ($device->settings_sync_status !== SyncStatus::Pending) {
                return false;
            }

            try {
                $this->push($device);
            } catch (DeviceException $e) {
                if ($e->isTransient()) {
                    throw $e;
                }
                $this->markFailed($device, $e);
            }

            return true;
        });
    }

    private function push(Device $device): void
    {
        $revision = $device->settings_revision;
        $this->client->updateSettings($device, $device->toSettingsPayload());

        Device::query()->whereKey($device->id)->where('settings_revision', $revision)->update([
            'settings_sync_status' => SyncStatus::Synced,
            'settings_sync_error' => null,
            'settings_synced_at' => now(),
        ]);
    }

    private function markFailed(Device $device, DeviceException $e): void
    {
        $changed = Device::query()->whereKey($device->id)->where('settings_revision', $device->settings_revision)->update([
            'settings_sync_status' => SyncStatus::Failed,
            'settings_sync_error' => json_encode($e->toArray()),
        ]);

        if ($changed) {
            Log::warning('Clock refused a settings change', ['device' => $device->id, 'code' => $e->errorCode()]);
        }
    }

    private function lockKey(Device $device): string
    {
        return "device-settings:{$device->id}";
    }
}
