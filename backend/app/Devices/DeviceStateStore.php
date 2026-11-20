<?php

namespace App\Devices;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Last known live state of each device (from WebSocket pushes or polling),
 * shared between the listener process and the web process through the cache.
 */
class DeviceStateStore
{
    public function __construct(private readonly Cache $cache) {}

    public function put(string $deviceId, array $state): array
    {
        unset($state['type'], $state['api_version']);
        $state['received_at'] = CarbonImmutable::now()->toIso8601String();
        $this->cache->forever($this->key($deviceId), $state);

        return $state;
    }

    public function get(string $deviceId): ?array
    {
        return $this->cache->get($this->key($deviceId));
    }

    /** @param list<string> $deviceIds @return array<string, array|null> */
    public function many(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $values = $this->cache->many(array_map($this->key(...), $deviceIds));

        return array_combine($deviceIds, array_values($values));
    }

    public function forget(string $deviceId): void
    {
        $this->cache->forget($this->key($deviceId));
    }

    private function key(string $deviceId): string
    {
        return "devices.{$deviceId}.state";
    }
}
