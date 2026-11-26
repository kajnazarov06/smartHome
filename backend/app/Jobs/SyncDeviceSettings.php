<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Devices\Exceptions\DeviceException;
use App\Events\DeviceSettingsUpdated;
use App\Models\Device;
use App\Services\DeviceSettingsService;
use App\Support\SafeBroadcast;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pushes one device's pending settings to the clock (see DeviceSettingsService). Dispatched when the
 * clock comes online and by `settings:sync` every minute. Not retried by the queue: an unreachable
 * clock leaves the settings pending and the next trigger tries again.
 */
class SyncDeviceSettings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $deviceId) {}

    public function uniqueId(): string
    {
        return $this->deviceId;
    }

    public function handle(DeviceSettingsService $settings): void
    {
        $device = Device::query()->find($this->deviceId);
        if ($device === null) {
            return;
        }

        try {
            if (! $settings->sync($device)) {
                return; // nothing pending, or another push holds the lock
            }
        } catch (DeviceException $e) {
            Log::info('Settings sync postponed', ['device' => $device->id, 'code' => $e->errorCode(), 'error' => $e->getMessage()]);

            return; // still pending: nothing changed for the UI
        }

        SafeBroadcast::dispatch(new DeviceSettingsUpdated($device->refresh()));
    }
}
