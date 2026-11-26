<?php

declare(strict_types=1);

namespace App\Devices\Exceptions;

/** The clock refuses pairing for a while after too many wrong codes (HTTP 429 on /api/pair/*). */
class PairingLockedException extends DeviceException
{
    public function __construct(public readonly ?int $retryAfter = null)
    {
        parent::__construct('Too many wrong pairing codes. Try again later.');
    }

    public function errorCode(): string
    {
        return 'pairing_locked';
    }

    public function status(): int
    {
        return 429;
    }
}
