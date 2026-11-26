<?php

declare(strict_types=1);

namespace App\Services;

use App\Devices\DeviceClient;
use App\Devices\DeviceLock;
use App\Devices\Exceptions\DeviceException;
use App\Devices\Exceptions\DeviceRequestException;
use App\Enums\SyncStatus;
use App\Models\Alarm;
use App\Models\Device;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Offline-first alarms. A user change is saved in the `alarms` table first, then pushed to the
 * clock right away. If the clock can't be reached, the change stays `pending` and SyncAlarms
 * pushes it when the clock is back (online transition in the listener, `alarms:sync` every minute).
 *
 * Conflict rules:
 * - Pending rows (unconfirmed user changes) win over the clock.
 * - Synced rows follow the clock, so changes the clock makes itself (a one-shot alarm switching
 *   itself off, an alarm deleted or added on the clock) flow back into the database.
 * - A change the clock refuses (4xx) is never retried: during a request it is rolled back and the
 *   error is returned; in the background the row becomes `failed` with the error for the UI.
 *
 * Every device write runs under a per-device lock so the web process and the worker never push
 * concurrently, and every status write is conditional on `revision` so a sync never overwrites a
 * newer user change.
 */
class AlarmService
{
    /** Hardware-dependent fields passed through when present. */
    public const EXTRA_FIELDS = ['snooze_min', 'sound', 'volume'];

    /** Upper bound on one sync holding the lock (11 alarms × 3 s timeout fits). */
    private const LOCK_TTL = 60;

    public function __construct(private readonly DeviceClient $client) {}

    // ---- API operations -------------------------------------------------------------------------

    /**
     * The stored alarms. When the clock is online they are reconciled with it first.
     *
     * @return array{alarms: Collection<int, Alarm>, stale: bool} stale = the clock could not be asked
     */
    public function list(Device $device): array
    {
        $stale = ! $device->isOnline();

        if (! $stale) {
            try {
                // Don't wait: if a sync is running, the database is about to be current anyway.
                $this->sync($device, wait: 0);
            } catch (DeviceException $e) {
                $stale = true;
                Log::info('Alarm list served from the database', ['device' => $device->id, 'error' => $e->getMessage()]);
            }
        }

        return ['alarms' => $device->alarms()->get(), 'stale' => $stale];
    }

    public function create(Device $device, array $data): Alarm
    {
        $alarm = $device->alarms()->create($this->normalizedFields($data));

        return $this->pushNow($device, $alarm, revert: fn () => $alarm->forceDelete());
    }

    public function update(Device $device, Alarm $alarm, array $data): Alarm
    {
        $original = $alarm->only(['time', 'days', 'enabled', 'label', 'extra', 'sync_status', 'sync_error']);
        $current = $this->deviceShape($alarm);

        $alarm->forceFill([
            ...$this->normalizedFields([...$current, ...$data]),
            'sync_status' => SyncStatus::Pending,
            'sync_error' => null,
            'revision' => $alarm->revision + 1,
        ])->save();

        return $this->pushNow($device, $alarm, revert: fn () => $alarm->forceFill($original)->save());
    }

    /** Soft-deletes the alarm until the clock has deleted it too. Returns true when that already happened. */
    public function delete(Device $device, Alarm $alarm): bool
    {
        $original = $alarm->only(['sync_status', 'sync_error']);

        $alarm->forceFill([
            'sync_status' => SyncStatus::Pending,
            'sync_error' => null,
            'revision' => $alarm->revision + 1,
        ])->save();
        $alarm->delete();

        $this->pushNow($device, $alarm, revert: function () use ($alarm, $original) {
            $alarm->restore();
            $alarm->forceFill($original)->save();
        });

        return ! Alarm::withTrashed()->whereKey($alarm->id)->exists();
    }

    // ---- Synchronisation ------------------------------------------------------------------------

    /**
     * Full two-way reconciliation with the clock, holding the device lock.
     * Returns false when another sync holds the lock for longer than `$wait` seconds.
     *
     * @throws DeviceException when the clock can't be reached; pending rows stay pending
     */
    public function sync(Device $device, int $wait = 10): bool
    {
        return DeviceLock::run($this->lockKey($device), self::LOCK_TTL, $wait, function () use ($device) {
            $this->reconcile($device);

            return true;
        });
    }

    private function reconcile(Device $device): void
    {
        /** @var Collection<int, array> $remote clock alarms keyed by their device ID */
        $remote = collect($this->client->alarms($device))->map($this->normalize(...))->keyBy('id');

        $locals = Alarm::withTrashed()->where('device_id', $device->id)->orderBy('id')->get();
        $claimed = $locals->pluck('device_alarm_id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($locals as $alarm) {
            // A create whose answer got lost (timeout after the clock saved it): adopt the copy
            // instead of creating a duplicate.
            if ($alarm->device_alarm_id === null && ! $alarm->trashed()) {
                $copy = $remote->first(fn (array $r) => ! in_array($r['id'], $claimed, true) && $this->sameContent($r, $alarm));
                if ($copy !== null) {
                    $alarm->device_alarm_id = $copy['id'];
                    Alarm::withTrashed()->whereKey($alarm->id)->whereNull('device_alarm_id')->update(['device_alarm_id' => $copy['id']]);
                    $claimed[] = $copy['id'];
                }
            }

            $onClock = $alarm->device_alarm_id !== null ? $remote->get($alarm->device_alarm_id) : null;

            match ($alarm->sync_status) {
                SyncStatus::Pending => $this->pushInSync($device, $alarm),
                SyncStatus::Synced => $this->followClock($alarm, $onClock),
                SyncStatus::Failed => null, // waits for the user to edit or delete it
            };
        }

        // Alarms created on the clock itself.
        foreach ($remote as $id => $alarm) {
            if (! in_array($id, $claimed, true)) {
                Alarm::query()->create([
                    ...$this->normalizedFields($alarm),
                    'device_id' => $device->id,
                    'device_alarm_id' => $id,
                    'sync_status' => SyncStatus::Synced,
                    'synced_at' => now(),
                ]);
            }
        }
    }

    /** A synced row mirrors the clock. Writes are conditional, so a user edit made meanwhile wins. */
    private function followClock(Alarm $alarm, ?array $onClock): void
    {
        $unchanged = Alarm::query()->whereKey($alarm->id)
            ->where('revision', $alarm->revision)
            ->where('sync_status', SyncStatus::Synced);

        if ($onClock === null) {
            $unchanged->forceDelete(); // deleted on the clock

            return;
        }

        $fields = $this->normalizedFields($onClock);
        if ($fields != $alarm->only(array_keys($fields))) {
            $unchanged->update([
                ...$fields,
                'days' => json_encode($fields['days']),
                'extra' => $fields['extra'] === null ? null : json_encode($fields['extra']),
                'synced_at' => now(),
            ]);
        }
    }

    /** Pushes a pending row during a sync; a refusal marks it failed, an outage aborts the sync. */
    private function pushInSync(Device $device, Alarm $alarm): void
    {
        try {
            $this->push($device, $alarm);
        } catch (DeviceException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            $this->markFailed($alarm, $e);
        }
    }

    // ---- Pushing one alarm --------------------------------------------------------------------

    /**
     * Tries to push a just-saved change while the user waits. Outage → stays pending (202);
     * refusal → `$revert` and rethrow so the user sees the clock's error, as before.
     */
    private function pushNow(Device $device, Alarm $alarm, Closure $revert): Alarm
    {
        if ($device->isOnline()) {
            try {
                // If a sync holds the lock, it (or the next one) picks the change up.
                DeviceLock::run($this->lockKey($device), self::LOCK_TTL, DeviceLock::REQUEST_WAIT, fn () => $this->push($device, $alarm));
            } catch (DeviceException $e) {
                if (! $e->isTransient()) {
                    $revert();
                    throw $e;
                }
                Log::info('Alarm change saved; clock unreachable, will sync later', ['device' => $device->id, 'alarm' => $alarm->id]);
            }
        }

        if (Alarm::withTrashed()->whereKey($alarm->id)->exists()) {
            $alarm->refresh();
        }

        return $alarm;
    }

    /** Sends one row's desired state to the clock and marks it synced if nobody changed it meanwhile. */
    private function push(Device $device, Alarm $alarm): void
    {
        $revision = $alarm->revision;

        if ($alarm->trashed()) {
            if ($alarm->device_alarm_id !== null) {
                try {
                    $this->client->deleteAlarm($device, $alarm->device_alarm_id);
                } catch (DeviceRequestException $e) {
                    if ($e->deviceStatus !== 404) {
                        throw $e; // 404 = already gone, which is what we wanted
                    }
                }
            }
            Alarm::withTrashed()->whereKey($alarm->id)->where('revision', $revision)->forceDelete();

            return;
        }

        $sent = false;
        if ($alarm->device_alarm_id !== null) {
            try {
                $this->client->updateAlarm($device, $alarm->device_alarm_id, $alarm->toDevicePayload());
                $sent = true;
            } catch (DeviceRequestException $e) {
                if ($e->deviceStatus !== 404) {
                    throw $e;
                }
                // The clock lost it: the database is the source of truth, so create it again.
            }
        }

        if (! $sent) {
            $body = $this->client->createAlarm($device, $alarm->toDevicePayload());
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                // Treated as transient: the next sync adopts the alarm if the clock did save it.
                throw new DeviceRequestException(502, $body, 'Device did not return an alarm id.');
            }
            // The clock's ID is identity, not desired state: store it even if the row changed meanwhile.
            $alarm->device_alarm_id = $id;
            Alarm::withTrashed()->whereKey($alarm->id)->update(['device_alarm_id' => $id]);
        }

        Alarm::withTrashed()->whereKey($alarm->id)->where('revision', $revision)->update([
            'sync_status' => SyncStatus::Synced,
            'sync_error' => null,
            'synced_at' => now(),
        ]);
    }

    private function markFailed(Alarm $alarm, DeviceException $e): void
    {
        $changed = Alarm::withTrashed()->whereKey($alarm->id)->where('revision', $alarm->revision)->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => json_encode($e->toArray()),
            // A refused delete must become visible again, or the alarm would ring with no way to see it.
            'deleted_at' => null,
        ]);

        if ($changed) {
            Log::warning('Clock refused an alarm change', ['device' => $alarm->device_id, 'alarm' => $alarm->id, 'code' => $e->errorCode()]);
        }
    }

    private function lockKey(Device $device): string
    {
        return "device-alarms:{$device->id}";
    }

    // ---- Shapes ---------------------------------------------------------------------------------

    /** Coerces a device alarm into the canonical shape. */
    private function normalize(array $alarm): array
    {
        $days = array_values(array_unique(array_map('intval', (array) ($alarm['days'] ?? []))));
        sort($days);

        return [
            'id' => (int) ($alarm['id'] ?? 0),
            'time' => (string) ($alarm['time'] ?? '00:00'),
            'days' => $days,
            'enabled' => filter_var($alarm['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'label' => isset($alarm['label']) && $alarm['label'] !== '' ? (string) $alarm['label'] : null,
            ...array_intersect_key($alarm, array_flip(self::EXTRA_FIELDS)),
        ];
    }

    /** Column values for an alarm in the device/API shape. */
    private function normalizedFields(array $alarm): array
    {
        $normalized = $this->normalize($alarm);

        return [
            'time' => $normalized['time'],
            'days' => $normalized['days'],
            'enabled' => $normalized['enabled'],
            'label' => $normalized['label'],
            'extra' => array_intersect_key($normalized, array_flip(self::EXTRA_FIELDS)) ?: null,
        ];
    }

    private function deviceShape(Alarm $alarm): array
    {
        return ['id' => $alarm->device_alarm_id, ...$alarm->toDevicePayload()];
    }

    /** Same time, days, enabled and label (hardware extras ignored), compared like followClock(). */
    private function sameContent(array $remote, Alarm $alarm): bool
    {
        $fields = Arr::except($this->normalizedFields($remote), 'extra');

        return $fields == $alarm->only(array_keys($fields));
    }
}
