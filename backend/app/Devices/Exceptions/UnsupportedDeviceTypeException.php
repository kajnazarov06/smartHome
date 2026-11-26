<?php

declare(strict_types=1);

namespace App\Devices\Exceptions;

/** The device reports a `type` that has no App\Enums\DeviceType case. */
class UnsupportedDeviceTypeException extends DeviceException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Devices of type \"{$type}\" are not supported yet.");
    }

    public function errorCode(): string
    {
        return 'unsupported_device_type';
    }

    public function status(): int
    {
        return 422;
    }
}
