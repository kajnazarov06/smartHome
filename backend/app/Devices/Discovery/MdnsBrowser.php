<?php

namespace App\Devices\Discovery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Browses a DNS-SD service over mDNS using "legacy unicast" queries: the query is sent
 * from an ephemeral port to 224.0.0.251:5353 and responders answer us directly, so no
 * multicast group membership (and no ext-sockets) is needed.
 *
 * Multicast does not cross Docker's default bridge network: run this from a container
 * with host networking, or natively on the laptop.
 */
class MdnsBrowser
{
    private const MDNS_ADDRESS = '224.0.0.251:5353';

    /** @return list<DiscoveredDevice> */
    public function browse(string $service, float $timeout = 3.0): array
    {
        $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            Log::warning('mDNS: cannot open UDP socket', ['error' => $errstr]);

            return [];
        }

        stream_set_blocking($socket, false);
        $query = DnsMessage::query($service);
        $records = [];
        $sources = []; // record owner name => responder IP (fallback when no A record is sent)

        $deadline = microtime(true) + $timeout;
        $nextSend = 0.0;

        while (($now = microtime(true)) < $deadline) {
            if ($now >= $nextSend) { // re-send once per second; mDNS is lossy
                @stream_socket_sendto($socket, $query, 0, self::MDNS_ADDRESS);
                $nextSend = $now + 1.0;
            }

            $read = [$socket];
            $write = $except = null;
            $wait = min($deadline, $nextSend) - microtime(true);
            if ($wait <= 0 || @stream_select($read, $write, $except, 0, (int) ($wait * 1_000_000)) < 1) {
                continue;
            }

            $packet = stream_socket_recvfrom($socket, 9000, 0, $peer);
            if ($packet === false || $packet === '') {
                continue;
            }

            $peerIp = $peer ? preg_replace('/:\d+$/', '', $peer) : null;
            foreach (DnsMessage::parseRecords($packet) as $record) {
                $records[] = $record;
                if ($peerIp) {
                    $sources[$record['name']] ??= $peerIp;
                }
            }
        }

        fclose($socket);

        return $this->assemble(strtolower($service), $records, $sources);
    }

    /**
     * Joins PTR -> SRV/TXT -> A records into devices.
     *
     * @param  list<array{name:string,type:int,ttl:int,data:mixed}>  $records
     * @param  array<string,string>  $sources
     * @return list<DiscoveredDevice>
     */
    public function assemble(string $service, array $records, array $sources = []): array
    {
        $byType = [];
        foreach ($records as $r) {
            $byType[$r['type']][$r['name']][] = $r['data'];
        }

        $instances = array_unique($byType[DnsMessage::TYPE_PTR][$service] ?? []);
        $devices = [];

        foreach ($instances as $label) {
            $instance = strtolower($label); // DNS names are case-insensitive; record owners are lowercased
            $srv = $byType[DnsMessage::TYPE_SRV][$instance][0] ?? null;
            $txt = array_merge([], ...($byType[DnsMessage::TYPE_TXT][$instance] ?? []));
            $hostname = $srv['target'] ?? null;

            $ip = ($hostname ? ($byType[DnsMessage::TYPE_A][$hostname][0] ?? null) : null)
                ?? $sources[$instance]
                ?? ($hostname ? $sources[$hostname] ?? null : null);

            $id = $txt['id'] ?? null;
            if (! $id || ! $ip) {
                continue;
            }

            // The contract fixes the port to 80 (config smarthome.device.port), so SRV's port is not used.
            $devices[$id] = new DiscoveredDevice(
                id: $id,
                host: $ip,
                type: $txt['type'] ?? DiscoveredDevice::DEFAULT_TYPE,
                name: $this->instanceLabel($label, $service),
                fw: $txt['fw'] ?? null,
                paired: ($txt['paired'] ?? '0') === '1',
                hostname: $hostname,
                source: 'mdns',
                seenAt: CarbonImmutable::now(),
            );
        }

        return array_values($devices);
    }

    private function instanceLabel(string $instance, string $service): string
    {
        return str_ends_with(strtolower($instance), '.'.$service) ? substr($instance, 0, -strlen($service) - 1) : $instance;
    }
}
