<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A scan requested by a user finished (Echo: `.discovery.finished` on `users.{id}`). */
class DiscoveryFinished implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param list<array<string,mixed>> $devices unpaired devices */
    public function __construct(
        public readonly int $userId,
        public readonly array $devices,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'discovery.finished';
    }

    public function broadcastWith(): array
    {
        return ['devices' => $this->devices];
    }
}
