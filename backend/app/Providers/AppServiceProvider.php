<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch lazy loading, silently discarded attributes and missing attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Named limiters for `throttle:<name>` (limits in config/smarthome.php `rate_limits`).
        foreach (['register', 'pairing', 'discovery_scan', 'discovery_probe'] as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute(config("smarthome.rate_limits.{$name}"))
                ->by($request->user()?->id ?: $request->ip()));
        }
    }
}
