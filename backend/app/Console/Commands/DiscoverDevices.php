<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Devices\Discovery\DiscoveredDevice;
use App\Devices\Discovery\DiscoveryService;
use App\Models\Device;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('devices:discover {--subnet= : Also probe /api/info across this IPv4 CIDR, e.g. 192.168.1.0/24} {--watch : Keep scanning every smarthome.discovery.watch_interval seconds}')]
#[Description('Find smart home devices on the LAN (mDNS _smartclock._tcp, optional subnet probe)')]
class DiscoverDevices extends Command
{
    public function handle(DiscoveryService $discovery): int
    {
        do {
            $found = $discovery->scan($this->option('subnet') ?: null);
            $known = Device::query()->whereIn('id', array_column($found, 'id'))->pluck('name', 'id');

            $this->table(
                ['ID', 'Host', 'Type', 'FW', 'Device says paired', 'Paired here', 'Source'],
                array_map(fn (DiscoveredDevice $d) => [
                    $d->id, $d->host, $d->type, $d->fw ?? '-', $d->paired ? 'yes' : 'no',
                    $known[$d->id] ?? '-', $d->source,
                ], $found),
            );
            $this->components->info(count($found).' device(s) found.');

            if ($this->option('watch')) {
                sleep(max(5, (int) config('smarthome.discovery.watch_interval')));
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }
}
