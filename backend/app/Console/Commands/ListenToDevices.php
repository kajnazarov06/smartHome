<?php

namespace App\Console\Commands;

use App\Devices\Listener\DeviceListener;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('devices:listen')]
#[Description('Hold a WebSocket to every paired device; store readings and broadcast live state (long-running)')]
class ListenToDevices extends Command
{
    public function handle(DeviceListener $listener): int
    {
        $this->components->info('Device listener started. Press Ctrl+C to stop.');
        $listener->run(fn (string $line) => $this->line('['.now()->format('H:i:s')."] {$line}"));

        return self::SUCCESS;
    }
}
