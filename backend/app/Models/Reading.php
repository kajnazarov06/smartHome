<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
#[Fillable(['device_id', 'ts', 'temperature_c', 'humidity_pct'])]
class Reading extends Model
{
    use MassPrunable;

    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'temperature_c' => 'float',
            'humidity_pct' => 'float',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Readings older than the retention window are removed by `model:prune` (scheduled daily). */
    public function prunable(): Builder
    {
        return static::where('ts', '<', now()->subDays(config('smarthome.history.retention_days')));
    }
}
