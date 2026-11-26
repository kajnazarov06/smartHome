<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A device went online or offline (Echo: `.device.status` on `users.{id}`). */
class DeviceStatusChanged implements ShouldBroadcastNow
{
    use BroadcastsToUser, Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly bool $online,
        public readonly ?string $lastSeenAt,
    ) {}

    public function broadcastAs(): string
    {
        return 'device.status';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'online' => $this->online, 'last_seen_at' => $this->lastSeenAt];
    }
}
