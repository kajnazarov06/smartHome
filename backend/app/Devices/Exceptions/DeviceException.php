<?php

namespace App\Devices\Exceptions;

use RuntimeException;

abstract class DeviceException extends RuntimeException
{
    /** Machine-readable error code returned to API clients. */
    abstract public function errorCode(): string;

    /** HTTP status the backend answers with. */
    abstract public function status(): int;

    /** Worth retrying later (the clock was unreachable, busy, or failed internally), unlike a refusal. */
    public function isTransient(): bool
    {
        return false;
    }
}
