<?php

namespace App\Devices\Exceptions;

class InvalidPairingCodeException extends DeviceException
{
    public function __construct(public readonly ?int $attemptsLeft = null)
    {
        parent::__construct('The pairing code is wrong or has expired.');
    }

    public function errorCode(): string
    {
        return 'invalid_pairing_code';
    }

    public function status(): int
    {
        return 422;
    }
}
