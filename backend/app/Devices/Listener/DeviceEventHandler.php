<?php

namespace App\Devices\Listener;

use App\Devices\DeviceStateStore;
use App\Events\AlarmRinging;
use App\Events\DeviceStateUpdated;
use App\Events\DeviceStatusChanged;
use App\Jobs\SyncAlarms;
use App\Models\Alarm;
use App\Models\Device;
use App\Models\Reading;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything the listener does with data coming from a device, kept free of any
 * event-loop code so it can be unit tested.
 */
class DeviceEventHandler
{
    /** Minimum seconds between `last_seen_at` writes per device. */
    private const TOUCH_WRITE_INTERVAL = 5;

    /** @var array<string, int> */
    private array $lastTouchWrite = [];

    /** @var array<string, int> unix time of the last stored reading */
    private array $lastReadingAt = [];

    /** @var array<string, bool> last broadcast online status */
    private array $online = [];

    public function __construct(
        private readonly DeviceStateStore $store,
        private readonly Dispatcher $events,
        private readonly Bus $bus,
    ) {}

    public function handleMessage(Device $device, string $raw): void
    {
        $message = json_decode($raw, true);
        if (! is_array($message)) {
            Log::debug('Device sent a non-JSON WebSocket message', ['device' => $device->id]);

            return;
        }

        match ($message['type'] ?? null) {
            'state' => $this->handleState($device, $message),
            'alarm_ringing' => $this->handleAlarmRinging($device, $message),
            default => $this->touch($device),
        };
    }

    public function handleState(Device $device, array $state): void
    {
        $this->touch($device);
        $state = $this->store->put($device->id, $state);
        $this->recordReadingIfDue($device, $state);
        $this->broadcast(new DeviceStateUpdated($device->user_id, $device->id, $state));
    }

    public function handleAlarmRinging(Device $device, array $message): void
    {
        $this->touch($device);
        if (isset($message['id'])) {
            // The clock reports its own alarm ID; the API exposes ours.
            $alarmId = Alarm::query()
                ->where('device_id', $device->id)
                ->where('device_alarm_id', (int) $message['id'])
                ->value('id');
            $this->broadcast(new AlarmRinging($device->user_id, $device->id, $alarmId));
        }
    }

    /** Marks the device as seen; broadcasts an "online" transition. */
    public function touch(Device $device): void
    {
        $now = now();
        $device->last_seen_at = $now;

        if ($now->getTimestamp() - ($this->lastTouchWrite[$device->id] ?? 0) >= self::TOUCH_WRITE_INTERVAL) {
            Device::query()->whereKey($device->id)->update(['last_seen_at' => $now]);
            $this->lastTouchWrite[$device->id] = $now->getTimestamp();
        }

        if (($this->online[$device->id] ?? null) !== true) {
            $this->online[$device->id] = true;
            $this->broadcast(new DeviceStatusChanged($device->user_id, $device->id, true, $now->toIso8601String()));
            $this->queueAlarmSync($device);
        }
    }

    /** @param iterable<Device> $devices */
    public function checkOffline(iterable $devices): void
    {
        foreach ($devices as $device) {
            if (! $device->isOnline() && ($this->online[$device->id] ?? null) !== false) {
                $this->online[$device->id] = false;
                $this->broadcast(new DeviceStatusChanged(
                    $device->user_id, $device->id, false, $device->last_seen_at?->toIso8601String(),
                ));
            }
        }
    }

    public function forget(string $deviceId): void
    {
        unset($this->lastTouchWrite[$deviceId], $this->lastReadingAt[$deviceId], $this->online[$deviceId]);
    }

    private function recordReadingIfDue(Device $device, array $state): void
    {
        $temperature = $state['temperature_c'] ?? null;
        $humidity = $state['humidity_pct'] ?? null;
        if (! is_numeric($temperature) && ! is_numeric($humidity)) {
            return;
        }

        if (! isset($this->lastReadingAt[$device->id])) {
            $latest = Reading::query()->where('device_id', $device->id)->max('ts');
            $this->lastReadingAt[$device->id] = $latest ? (int) strtotime((string) $latest) : 0;
        }

        $now = now();
        if ($now->getTimestamp() - $this->lastReadingAt[$device->id] < config('smarthome.history.interval')) {
            return;
        }

        Reading::query()->create([
            'device_id' => $device->id,
            'ts' => $now,
            'temperature_c' => is_numeric($temperature) ? (float) $temperature : null,
            'humidity_pct' => is_numeric($humidity) ? (float) $humidity : null,
        ]);
        $this->lastReadingAt[$device->id] = $now->getTimestamp();
    }

    /** Back online (or listener start): push alarm changes saved meanwhile and pick up the clock's own. */
    private function queueAlarmSync(Device $device): void
    {
        if (! $device->hasCapability('alarm')) {
            return;
        }

        try {
            $this->bus->dispatch(new SyncAlarms($device->id));
        } catch (Throwable $e) {
            Log::warning('Could not queue alarm sync', ['device' => $device->id, 'error' => $e->getMessage()]);
        }
    }

    /** Broadcasting must never take the listener down (e.g. while Reverb restarts). */
    private function broadcast(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            Log::warning('Broadcast failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }
}
