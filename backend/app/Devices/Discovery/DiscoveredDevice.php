<?php

declare(strict_types=1);

namespace App\Devices\Discovery;

use App\Enums\DeviceType;
use Carbon\CarbonImmutable;

/**
 * A device found on the LAN (via mDNS, subnet probe or manual add-by-IP).
 * `type` stays the raw string the device announced; `supported` says whether it maps to a DeviceType.
 */
final readonly class DiscoveredDevice
{
    /** Assumed when a device doesn't announce a type (v1 firmware is always a clock). */
    public const DEFAULT_TYPE = DeviceType::Clock->value;

    public function __construct(
        public string $id,
        public string $host,
        public string $type = self::DEFAULT_TYPE,
        public ?string $name = null,
        public ?string $fw = null,
        public bool $paired = false,
        public ?string $hostname = null,
        public string $source = 'mdns',
        public ?CarbonImmutable $seenAt = null,
    ) {}

    /** Builds a device from an /api/info response. */
    public static function fromInfo(array $info, string $host, string $source): ?self
    {
        if (! is_string($info['id'] ?? null) || $info['id'] === '') {
            return null;
        }

        return new self(
            id: $info['id'],
            host: $host,
            type: (string) ($info['type'] ?? self::DEFAULT_TYPE),
            name: $info['name'] ?? null,
            fw: isset($info['fw']) ? (string) $info['fw'] : null,
            paired: filter_var($info['paired'] ?? false, FILTER_VALIDATE_BOOL),
            source: $source,
            seenAt: CarbonImmutable::now(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'type' => $this->type,
            'supported' => DeviceType::tryFrom($this->type) !== null,
            'name' => $this->name,
            'fw' => $this->fw,
            'paired' => $this->paired,
            'hostname' => $this->hostname,
            'source' => $this->source,
            'seen_at' => ($this->seenAt ?? CarbonImmutable::now())->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            host: $data['host'],
            type: $data['type'] ?? self::DEFAULT_TYPE,
            name: $data['name'] ?? null,
            fw: $data['fw'] ?? null,
            paired: (bool) ($data['paired'] ?? false),
            hostname: $data['hostname'] ?? null,
            source: $data['source'] ?? 'mdns',
            seenAt: isset($data['seen_at']) ? CarbonImmutable::parse($data['seen_at']) : null,
        );
    }
}
