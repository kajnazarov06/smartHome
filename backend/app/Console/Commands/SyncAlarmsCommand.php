<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\SyncAlarms;
use App\Models\Device;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alarms:sync {--all : Sync every online device, not only those with pending changes}')]
#[Description('Queue an alarm sync for online devices that have alarm changes the clock has not confirmed')]
class SyncAlarmsCommand extends Command
{
    public function handle(): int
    {
        $devices = Device::query()
            ->online()
            ->unless($this->option('all'), fn ($query) => $query->whereHas(
                'alarms',
                fn ($alarms) => $alarms->withTrashed()->where('sync_status', SyncStatus::Pending),
            ))
            ->pluck('id');

        foreach ($devices as $id) {
            SyncAlarms::dispatch($id);
        }

        $this->components->info(count($devices).' device(s) queued.');

        return self::SUCCESS;
    }
}
