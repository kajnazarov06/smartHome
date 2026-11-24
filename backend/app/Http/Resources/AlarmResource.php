<?php

namespace App\Http\Resources;

use App\Models\Alarm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `sync_status`: synced (the clock has it), pending (saved, waiting for the clock),
 * failed (the clock refused it; `sync_error` says why).
 *
 * @mixin Alarm
 */
class AlarmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'time' => $this->time,
            'days' => $this->days,
            'enabled' => $this->enabled,
            'label' => $this->label,
            ...($this->extra ?? []),
            'sync_status' => $this->sync_status->value,
            'sync_error' => $this->sync_error,
            'synced_at' => $this->synced_at?->toIso8601String(),
        ];
    }
}
