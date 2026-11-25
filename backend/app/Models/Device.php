<?php

namespace App\Models;

use App\Enums\DeviceType;
use App\Enums\SyncStatus;
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
 * @property array{tz?:string,brightness?:int,snooze_min?:int}|null $settings desired settings (with `name`, pushed by DeviceSettingsService)
 * @property SyncStatus $settings_sync_status
 * @property array{code:string,message:string}|null $settings_sync_error
 */
#[Table(keyType: 'string', incrementing: false)]
#[Fillable(['id', 'user_id', 'type', 'name', 'token', 'last_ip', 'fw_version', 'mac', 'capabilities', 'settings', 'last_seen_at'])]
#[Hidden(['token'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $attributes = ['settings_sync_status' => 'synced', 'settings_revision' => 1];

    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'token' => 'encrypted',
            'capabilities' => 'array',
            'settings' => 'array',
            'last_seen_at' => 'datetime',
            'settings_sync_status' => SyncStatus::class,
            'settings_sync_error' => 'array',
            'settings_revision' => 'integer',
            'settings_synced_at' => 'datetime',
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

    /** The settings the clock should hold, in the device API shape (`PUT /api/settings`). */
    public function toSettingsPayload(): array
    {
        return ['name' => $this->name, ...($this->settings ?? [])];
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
