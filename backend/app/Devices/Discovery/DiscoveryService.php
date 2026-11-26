<?php

declare(strict_types=1);

namespace App\Devices\Discovery;

use App\Devices\DeviceClient;
use App\Devices\Exceptions\DeviceRequestException;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Runs discovery and keeps the list of recently seen devices in the shared cache, so that the
 * web process can read what the (host-networked) worker found. Also refreshes the IP address
 * of already paired devices when DHCP hands them a new one.
 */
class DiscoveryService
{
    private const CACHE_KEY = 'discovery.devices';

    private const SCANNED_AT_KEY = 'discovery.scanned_at';

    public function __construct(
        private readonly MdnsBrowser $mdns,
        private readonly SubnetProber $prober,
        private readonly DeviceClient $client,
        private readonly Cache $cache,
    ) {}

    /** @return list<DiscoveredDevice> */
    public function scan(?string $subnet = null): array
    {
        $found = [];

        if (config('smarthome.discovery.mdns_enabled')) {
            foreach ($this->mdns->browse(config('smarthome.discovery.service'), config('smarthome.discovery.mdns_timeout')) as $device) {
                $found[$device->id] = $device;
            }
        }

        $subnet ??= config('smarthome.discovery.probe_subnet');
        if ($subnet) {
            $probed = $this->prober->probe(
                $subnet,
                config('smarthome.discovery.probe_timeout'),
                config('smarthome.discovery.probe_concurrency'),
            );
            foreach ($probed as $device) {
                $found[$device->id] ??= $device; // mDNS result wins: it carries the hostname
            }
        }

        $this->remember(array_values($found));
        $this->cache->forever(self::SCANNED_AT_KEY, CarbonImmutable::now()->toIso8601String());

        return array_values($found);
    }

    /** Manual "add by IP": asks the device for its info directly. */
    public function probeHost(string $host): DiscoveredDevice
    {
        $device = DiscoveredDevice::fromInfo($this->client->info($host), $host, 'manual');
        if ($device === null) {
            throw new DeviceRequestException(502, [], 'The address answered, but not like a smart home device.');
        }

        $this->remember([$device]);

        return $device;
    }

    /**
     * Merges devices into the cached list and updates paired devices' addresses.
     *
     * @param  list<DiscoveredDevice>  $devices
     */
    public function remember(array $devices): void
    {
        $list = $this->all();
        foreach ($devices as $device) {
            $list[$device->id] = $device;
        }

        $cutoff = CarbonImmutable::now()->subSeconds(config('smarthome.discovery.ttl'));
        $list = array_filter($list, fn (DiscoveredDevice $d) => $d->seenAt === null || $d->seenAt->gte($cutoff));

        $this->cache->forever(self::CACHE_KEY, array_map(fn (DiscoveredDevice $d) => $d->toArray(), array_values($list)));

        $this->refreshPairedDevices($devices);
    }

    /** @return array<string, DiscoveredDevice> keyed by device ID */
    public function all(): array
    {
        $list = [];
        foreach ($this->cache->get(self::CACHE_KEY, []) as $row) {
            $device = DiscoveredDevice::fromArray($row);
            $list[$device->id] = $device;
        }

        return $list;
    }

    /** Devices seen on the network that this backend has not paired. */
    public function unpaired(): array
    {
        $list = $this->all();
        $known = Device::query()->whereIn('id', array_keys($list))->pluck('id')->all();

        return array_values(array_diff_key($list, array_flip($known)));
    }

    public function scannedAt(): ?string
    {
        return $this->cache->get(self::SCANNED_AT_KEY);
    }

    /** @param list<DiscoveredDevice> $devices */
    private function refreshPairedDevices(array $devices): void
    {
        if ($devices === []) {
            return;
        }

        $byId = collect($devices)->keyBy('id');
        Device::query()->whereIn('id', $byId->keys())->each(function (Device $device) use ($byId) {
            $seen = $byId[$device->id];
            $device->last_ip = $seen->host;
            $device->fw_version = $seen->fw ?? $device->fw_version;
            $device->save(); // no-op when nothing changed
        });
    }
}
