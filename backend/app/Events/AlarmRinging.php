<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** An alarm started ringing on a device (Echo: `.alarm.ringing` on `users.{id}`). */
class AlarmRinging implements ShouldBroadcastNow
{
    use BroadcastsToUser, Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly ?int $alarmId, // null if the clock rings an alarm the backend doesn't know yet
    ) {}

    public function broadcastAs(): string
    {
        return 'alarm.ringing';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'alarm_id' => $this->alarmId];
    }
}
