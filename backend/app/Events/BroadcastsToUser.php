<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Every device event goes to its owner's private channel (`users.{id}`, authorized in
 * routes/channels.php). The using class provides `$userId`.
 */
trait BroadcastsToUser
{
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }
}
