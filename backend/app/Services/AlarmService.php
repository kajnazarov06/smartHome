<?php

namespace App\Services;

use App\Devices\DeviceClient;
use App\Devices\Exceptions\DeviceUnreachableException;
use App\Models\CachedAlarm;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Alarm CRUD proxied to the device. The device is the source of truth; every successful
 * call refreshes the local alarms_cache so the UI can still list alarms while it is offline.
 */
class AlarmService
{
    /** Hardware-dependent fields passed through when present. */
    public const EXTRA_FIELDS = ['snooze_min', 'sound', 'volume'];

    public function __construct(private readonly DeviceClient $client) {}

    /** @return array{alarms: list<array>, stale: bool} */
    public function list(Device $device): array
    {
        try {
            $alarms = array_map($this->normalize(...), $this->client->alarms($device));
            $this->sync($device, $alarms);

            return ['alarms' => $alarms, 'stale' => false];
        } catch (DeviceUnreachableException) {
            return [
                'alarms' => $device->cachedAlarms()->get()->map->toDeviceArray()->all(),
                'stale' => true,
            ];
        }
    }

    public function create(Device $device, array $data): array
    {
        $alarm = $this->normalize($this->client->createAlarm($device, $data) + $data);
        $this->cache($device, $alarm);

        return $alarm;
    }

    public function update(Device $device, int $alarmId, array $data): array
    {
        $alarm = $this->normalize(['id' => $alarmId] + $this->client->updateAlarm($device, $alarmId, $data) + $data);
        $this->cache($device, $alarm);

        return $alarm;
    }

    public function delete(Device $device, int $alarmId): void
    {
        $this->client->deleteAlarm($device, $alarmId);
        $device->cachedAlarms()->where('alarm_id', $alarmId)->delete();
    }

    /** Best-effort refresh used after pairing; ignores an unreachable device. */
    public function refresh(Device $device): void
    {
        try {
            $this->sync($device, array_map($this->normalize(...), $this->client->alarms($device)));
        } catch (DeviceUnreachableException) {
        }
    }

    /** @param list<array> $alarms normalized alarms */
    public function sync(Device $device, array $alarms): void
    {
        DB::transaction(function () use ($device, $alarms) {
            $device->cachedAlarms()->whereNotIn('alarm_id', array_column($alarms, 'id'))->delete();
            foreach ($alarms as $alarm) {
                $this->cache($device, $alarm);
            }
        });
    }

    private function cache(Device $device, array $alarm): void
    {
        CachedAlarm::query()->updateOrCreate(
            ['device_id' => $device->id, 'alarm_id' => $alarm['id']],
            [
                'time' => $alarm['time'],
                'days' => $alarm['days'],
                'enabled' => $alarm['enabled'],
                'label' => $alarm['label'],
                'extra' => array_intersect_key($alarm, array_flip(self::EXTRA_FIELDS)) ?: null,
                'synced_at' => now(),
            ],
        );
    }

    /** Coerces a device alarm into the canonical shape. */
    public function normalize(array $alarm): array
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
}
