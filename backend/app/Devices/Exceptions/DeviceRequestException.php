<?php

namespace App\Devices\Exceptions;

/** The device answered with a non-2xx status. */
class DeviceRequestException extends DeviceException
{
    public function __construct(
        public readonly int $deviceStatus,
        public readonly array $body = [],
        string $message = '',
    ) {
        parent::__construct($message ?: ($body['error'] ?? $body['message'] ?? "Device responded with HTTP {$deviceStatus}."));
    }

    public function errorCode(): string
    {
        return match (true) {
            $this->deviceStatus === 401, $this->deviceStatus === 403 => 'device_unauthorized',
            $this->deviceStatus === 404 => 'device_not_found',
            $this->deviceStatus === 429 => 'device_rate_limited',
            $this->deviceStatus >= 400 && $this->deviceStatus < 500 => 'device_rejected',
            default => 'device_error',
        };
    }

    public function status(): int
    {
        return match (true) {
            // The backend's stored token no longer works: a gateway problem, not the user's session.
            $this->deviceStatus === 401, $this->deviceStatus === 403 => 502,
            $this->deviceStatus === 404 => 404,
            $this->deviceStatus === 429 => 429,
            $this->deviceStatus >= 400 && $this->deviceStatus < 500 => 422,
            default => 502,
        };
    }

    public function retryAfter(): ?int
    {
        $value = $this->body['retry_after'] ?? $this->body['retry_after_s'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
