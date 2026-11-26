<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Devices\Exceptions\DeviceException;
use App\Events\AlarmsUpdated;
use App\Http\Resources\AlarmResource;
use App\Models\Device;
use App\Services\AlarmService;
use App\Support\SafeBroadcast;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles one device's alarms with the clock (see AlarmService). Dispatched when the clock comes
 * online and by `alarms:sync` every minute. It is not retried by the queue: an unreachable clock
 * leaves the rows pending, and the next trigger tries again.
 *
 * Unique until processing starts, so a change saved while a sync runs still gets its own sync.
 */
class SyncAlarms implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $deviceId) {}

    public function uniqueId(): string
    {
        return $this->deviceId;
    }

    public function handle(AlarmService $alarms): void
    {
        $device = Device::query()->find($this->deviceId);
        if ($device === null) {
            return;
        }

        try {
            if (! $alarms->sync($device)) {
                return; // another sync holds the lock
            }
        } catch (DeviceException $e) {
            Log::info('Alarm sync postponed', ['device' => $device->id, 'code' => $e->errorCode(), 'error' => $e->getMessage()]);
        }

        // Also after a failure: rows may have become `failed`.
        SafeBroadcast::dispatch(new AlarmsUpdated(
            $device->user_id,
            $device->id,
            AlarmResource::collection($device->alarms()->get())->resolve(),
        ));
    }
}
