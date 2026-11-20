<?php

namespace App\Devices\Discovery;

use App\Devices\DeviceClient;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Fallback discovery: GET /api/info on every host of an IPv4 subnet (max /22).
 * Used when mDNS can't reach the LAN, e.g. Docker Desktop without host networking.
 */
class SubnetProber
{
    public function __construct(private readonly Http $http) {}

    /** @return list<DiscoveredDevice> */
    public function probe(string $cidr, float $timeout = 0.8, int $concurrency = 32): array
    {
        $found = [];

        foreach (array_chunk(self::hosts($cidr), max(1, $concurrency)) as $chunk) {
            $responses = $this->http->pool(fn (Pool $pool) => array_map(
                fn (string $ip) => $pool->as($ip)
                    ->acceptJson()
                    ->connectTimeout($timeout)
                    ->timeout($timeout)
                    ->get(DeviceClient::baseUrl($ip).'/api/info'),
                $chunk,
            ));

            foreach ($responses as $ip => $response) {
                if (! $response instanceof Response || ! $response->successful() || ! is_array($response->json())) {
                    continue;
                }
                $device = DiscoveredDevice::fromInfo($response->json(), (string) $ip, 'probe');
                if ($device) {
                    $found[$device->id] = $device;
                }
            }
        }

        return array_values($found);
    }

    /** @return list<string> usable host addresses of the subnet */
    public static function hosts(string $cidr): array
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($cidr), $m) || ip2long($m[1]) === false) {
            throw new InvalidArgumentException("Invalid IPv4 CIDR: {$cidr}");
        }

        $bits = (int) $m[2];
        if ($bits < 22 || $bits > 32) {
            throw new InvalidArgumentException('Subnet must be between /22 and /32.');
        }

        $mask = $bits === 32 ? 0xFFFFFFFF : (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
        $network = ip2long($m[1]) & $mask;
        $size = 1 << (32 - $bits);

        if ($size <= 2) {
            return array_map(fn ($i) => long2ip($network + $i), range(0, $size - 1));
        }

        return array_map(fn ($i) => long2ip($network + $i), range(1, $size - 2));
    }
}
