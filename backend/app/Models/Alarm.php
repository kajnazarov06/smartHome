<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An alarm as the user wants it. The database is the source of truth for the user's changes;
 * AlarmService pushes them to the clock (right away, or once it is reachable again).
 * `device_alarm_id` is the clock's own ID; `id` is the one the API exposes.
 *
 * A soft-deleted row is a deletion the clock has not confirmed yet.
 *
 * @property SyncStatus $sync_status
 * @property array{code:string,message:string}|null $sync_error
 */
#[Fillable(['device_id', 'device_alarm_id', 'time', 'days', 'enabled', 'label', 'extra', 'sync_status', 'sync_error', 'revision', 'synced_at'])]
class Alarm extends Model
{
    use SoftDeletes;

    protected $attributes = ['sync_status' => 'pending', 'revision' => 1];

    protected function casts(): array
    {
        return [
            'device_alarm_id' => 'integer',
            'days' => 'array',
            'enabled' => 'boolean',
            'extra' => 'array',
            'sync_status' => SyncStatus::class,
            'sync_error' => 'array',
            'revision' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** The fields the clock stores, in the device API shape (without the ID). */
    public function toDevicePayload(): array
    {
        return [
            'time' => $this->time,
            'days' => $this->days,
            'enabled' => $this->enabled,
            'label' => $this->label,
            ...($this->extra ?? []),
        ];
    }
}
