<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A background settings push finished (Echo: `.device.settings` on `users.{id}`). */
class DeviceSettingsUpdated implements ShouldBroadcastNow
{
    use BroadcastsToUser, Dispatchable;

    public readonly int $userId;

    public function __construct(public readonly Device $device)
    {
        $this->userId = $device->user_id;
    }

    public function broadcastAs(): string
    {
        return 'device.settings';
    }

    /** Same fields as DeviceResource, so the UI can merge it straight into its cache. */
    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'name' => $this->device->name,
            ...DeviceResource::make($this->device)->settingsFields(),
        ];
    }
}
