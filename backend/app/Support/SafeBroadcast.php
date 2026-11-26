<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/** A broadcast failure (e.g. Reverb restarting) must never break the action that caused it. */
final class SafeBroadcast
{
    public static function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Broadcast failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }
}
