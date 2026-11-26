<?php

declare(strict_types=1);

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

    /** The clock rejected the stored token (e.g. it was factory-reset). */
    public function isUnauthorized(): bool
    {
        return $this->deviceStatus === 401 || $this->deviceStatus === 403;
    }

    public function errorCode(): string
    {
        return $this->mapped()[0];
    }

    public function status(): int
    {
        return $this->mapped()[1];
    }

    public function isTransient(): bool
    {
        return $this->deviceStatus >= 500 || $this->deviceStatus === 429 || $this->deviceStatus === 408;
    }

    /** Seconds the device asks us to wait (`retry_after` in its error body), if any. */
    public function retryAfter(): ?int
    {
        $value = $this->body['retry_after'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array{string, int} API error code and the HTTP status the backend answers with */
    private function mapped(): array
    {
        return match (true) {
            // The backend's stored token no longer works: a gateway problem, not the user's session.
            $this->isUnauthorized() => ['device_unauthorized', 502],
            $this->deviceStatus === 404 => ['device_not_found', 404],
            $this->deviceStatus === 429 => ['device_rate_limited', 429],
            $this->deviceStatus >= 400 && $this->deviceStatus < 500 => ['device_rejected', 422],
            default => ['device_error', 502],
        };
    }
}
