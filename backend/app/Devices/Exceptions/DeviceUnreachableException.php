<?php

namespace App\Devices\Exceptions;

use Throwable;

/** The device did not answer (offline, wrong IP, timeout). */
class DeviceUnreachableException extends DeviceException
{
    public static function forHost(string $host, ?Throwable $previous = null): self
    {
        return new self("Device at {$host} is not reachable.", 0, $previous);
    }

    public function errorCode(): string
    {
        return 'device_unreachable';
    }

    public function status(): int
    {
        return 503;
    }
}
