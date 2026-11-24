<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A background sync finished for a device (Echo: `.alarms.updated` on `users.{id}`).
 * Carries the full list (at most 10 alarms) with each alarm's `sync_status`.
 */
class AlarmsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param list<array> $alarms AlarmResource arrays */
    public function __construct(
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly array $alarms,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'alarms.updated';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'alarms' => $this->alarms];
    }
}
