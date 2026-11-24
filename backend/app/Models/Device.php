<?php

namespace App\Models;

use App\Enums\DeviceType;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paired device. The primary key is the device's own ID, not an auto-increment.
 *
 * @property string $id
 * @property DeviceType $type
 * @property string $token Plain device token (decrypted by the cast). Never serialise it.
 * @property list<string> $capabilities
 */
#[Table(keyType: 'string', incrementing: false)]
#[Fillable(['id', 'user_id', 'type', 'name', 'token', 'last_ip', 'fw_version', 'mac', 'capabilities', 'settings', 'last_seen_at'])]
#[Hidden(['token'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'token' => 'encrypted',
            'capabilities' => 'array',
            'settings' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Reading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    /** @return HasMany<Alarm, $this> */
    public function alarms(): HasMany
    {
        return $this->hasMany(Alarm::class)->orderBy('time')->orderBy('id');
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(config('smarthome.device.offline_after')));
    }
}
