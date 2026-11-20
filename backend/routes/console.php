<?php

use App\Models\Reading;
use Illuminate\Support\Facades\Schedule;

// Run by `php artisan schedule:work` in the worker container (host networking, so mDNS works).

// Keeps discovered devices fresh and updates paired devices' IPs after DHCP changes.
Schedule::command('devices:discover')->everyMinute()->withoutOverlapping()->runInBackground();

// History retention (smarthome.history.retention_days, default 30).
Schedule::command('model:prune', ['--model' => [Reading::class]])->daily();
