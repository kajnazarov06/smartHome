<?php

declare(strict_types=1);

namespace App\Devices;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Per-device cache lock shared by the web process and the worker, so they never push the same
 * kind of change to a clock concurrently (AlarmService, DeviceSettingsService).
 */
final class DeviceLock
{
    /** Seconds a user request waits for a running sync before leaving its change to the background. */
    public const REQUEST_WAIT = 5;

    /**
     * Runs `$callback` holding the lock and returns its result, or false when another holder kept
     * the lock for longer than `$wait` seconds. The lock is released even if the callback throws.
     */
    public static function run(string $key, int $ttl, int $wait, Closure $callback): mixed
    {
        try {
            return Cache::lock($key, $ttl)->block($wait, $callback);
        } catch (LockTimeoutException) {
            return false;
        }
    }
}
