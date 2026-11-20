<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Live reading pushed to the owner's browser (Echo: `.device.state` on `users.{id}`). */
class DeviceStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly array $state,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'device.state';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'state' => $this->state];
    }
}
