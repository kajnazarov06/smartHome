<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A background settings push finished (Echo: `.device.settings` on `users.{id}`). */
class DeviceSettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly Device $device) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->device->user_id}");
    }

    public function broadcastAs(): string
    {
        return 'device.settings';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'name' => $this->device->name,
            'settings' => $this->device->settings ?? (object) [],
            'settings_sync_status' => $this->device->settings_sync_status->value,
            'settings_sync_error' => $this->device->settings_sync_error,
            'settings_synced_at' => $this->device->settings_synced_at?->toIso8601String(),
        ];
    }
}
