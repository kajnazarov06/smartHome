<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\SyncDeviceSettings;
use App\Models\Device;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('settings:sync')]
#[Description('Queue a settings push for online devices whose settings the clock has not confirmed')]
class SyncDeviceSettingsCommand extends Command
{
    public function handle(): int
    {
        $devices = Device::query()
            ->where('settings_sync_status', SyncStatus::Pending)
            ->online()
            ->pluck('id');

        foreach ($devices as $id) {
            SyncDeviceSettings::dispatch($id);
        }

        $this->components->info(count($devices).' device(s) queued.');

        return self::SUCCESS;
    }
}
