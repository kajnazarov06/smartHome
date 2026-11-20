<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Local copy of an alarm stored on a device. The device stays the source of truth;
 * this table only lets the UI show alarms while the device is offline.
 */
#[Table(name: 'alarms_cache', timestamps: false)]
#[Fillable(['device_id', 'alarm_id', 'time', 'days', 'enabled', 'label', 'extra', 'synced_at'])]
class CachedAlarm extends Model
{
    protected function casts(): array
    {
        return [
            'alarm_id' => 'integer',
            'days' => 'array',
            'enabled' => 'boolean',
            'extra' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** The alarm in the device API shape. */
    public function toDeviceArray(): array
    {
        return [
            'id' => $this->alarm_id,
            'time' => $this->time,
            'days' => $this->days,
            'enabled' => $this->enabled,
            'label' => $this->label,
            ...($this->extra ?? []),
        ];
    }
}
