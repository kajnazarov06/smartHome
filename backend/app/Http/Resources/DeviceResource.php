<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Devices\DeviceStateStore;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generic device shape: the UI renders a card from `type` + `capabilities`, so a new
 * device type (plug, light, sensor) needs no new endpoint or table.
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = app(DeviceStateStore::class)->get($this->id);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'capabilities' => $this->capabilities ?? [],
            'fw_version' => $this->fw_version,
            'mac' => $this->mac,
            'last_ip' => $this->last_ip,
            'online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            ...$this->settingsFields(),
            'state' => $state,
            'next_alarm' => $state['next_alarm'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Desired settings and their sync status; also broadcast as `.device.settings`. */
    public function settingsFields(): array
    {
        return [
            'settings' => $this->settings ?? (object) [],
            // synced / pending (waiting for the clock) / failed (refused; see settings_sync_error)
            'settings_sync_status' => $this->settings_sync_status->value,
            'settings_sync_error' => $this->settings_sync_error,
            'settings_synced_at' => $this->settings_synced_at?->toIso8601String(),
        ];
    }
}
