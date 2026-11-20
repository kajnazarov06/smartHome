<?php

namespace Tests\Feature;

use App\Models\CachedAlarm;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlarmTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = Device::factory()->create(['last_ip' => '192.168.1.50']);
    }

    public function test_list_comes_from_device_and_refreshes_cache(): void
    {
        CachedAlarm::query()->create([
            'device_id' => $this->device->id, 'alarm_id' => 9, 'time' => '05:00', 'days' => [], 'enabled' => true, 'synced_at' => now(),
        ]);
        Http::fake(['http://192.168.1.50/api/alarms' => Http::response([
            'api_version' => 1,
            'alarms' => [['id' => 1, 'time' => '07:00', 'days' => [5, 1, 2], 'enabled' => true, 'label' => 'Work']],
        ])]);

        $this->actingAs($this->device->user)->getJson("/api/devices/{$this->device->id}/alarms")
            ->assertOk()
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('data.0.days', [1, 2, 5]);

        $this->assertSame([1], $this->device->cachedAlarms()->pluck('alarm_id')->all(), 'alarm deleted on the device is dropped from the cache');
    }

    public function test_list_falls_back_to_cache_when_offline(): void
    {
        CachedAlarm::query()->create([
            'device_id' => $this->device->id, 'alarm_id' => 3, 'time' => '06:30', 'days' => [6, 7], 'enabled' => false,
            'label' => 'Weekend', 'synced_at' => now(),
        ]);
        Http::fake(['*' => Http::failedConnection()]);

        $this->actingAs($this->device->user)->getJson("/api/devices/{$this->device->id}/alarms")
            ->assertOk()
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('data.0.id', 3)
            ->assertJsonPath('data.0.label', 'Weekend');
    }

    public function test_create_is_proxied_to_device(): void
    {
        Http::fake(['http://192.168.1.50/api/alarms' => Http::response([
            'api_version' => 1, 'id' => 4, 'time' => '07:15', 'days' => [1, 2, 3, 4, 5], 'enabled' => true, 'label' => 'Gym',
        ], 201)]);

        $this->actingAs($this->device->user)->postJson("/api/devices/{$this->device->id}/alarms", [
            'time' => '07:15', 'days' => [1, 2, 3, 4, 5], 'label' => 'Gym',
        ])->assertCreated()->assertJsonPath('data.id', 4);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['time'] === '07:15' && $r['enabled'] === true);
        $this->assertDatabaseHas('alarms_cache', ['device_id' => $this->device->id, 'alarm_id' => 4, 'label' => 'Gym']);
    }

    public function test_create_validates_input(): void
    {
        Http::fake();

        $this->actingAs($this->device->user)->postJson("/api/devices/{$this->device->id}/alarms", [
            'time' => '25:00', 'days' => [0, 8],
        ])->assertJsonValidationErrors(['time', 'days.0', 'days.1']);

        Http::assertNothingSent();
    }

    public function test_create_is_refused_at_ten_alarms(): void
    {
        foreach (range(1, 10) as $id) {
            CachedAlarm::query()->create([
                'device_id' => $this->device->id, 'alarm_id' => $id, 'time' => '07:00', 'days' => [], 'enabled' => true, 'synced_at' => now(),
            ]);
        }
        Http::fake();

        $this->actingAs($this->device->user)->postJson("/api/devices/{$this->device->id}/alarms", ['time' => '07:00', 'days' => []])
            ->assertUnprocessable();
    }

    public function test_update_and_delete(): void
    {
        CachedAlarm::query()->create([
            'device_id' => $this->device->id, 'alarm_id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => true, 'synced_at' => now(),
        ]);
        Http::fake([
            'http://192.168.1.50/api/alarms/2' => Http::sequence()
                ->push(['api_version' => 1, 'id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => false])
                ->push(['api_version' => 1]),
        ]);

        $this->actingAs($this->device->user)->putJson("/api/devices/{$this->device->id}/alarms/2", ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse(CachedAlarm::query()->where('alarm_id', 2)->first()->enabled);

        $this->actingAs($this->device->user)->deleteJson("/api/devices/{$this->device->id}/alarms/2")->assertNoContent();
        $this->assertDatabaseCount('alarms_cache', 0);
    }

    public function test_device_rejection_is_reported(): void
    {
        Http::fake(['*' => Http::response(['error' => 'alarm limit reached'], 400)]);

        $this->actingAs($this->device->user)->postJson("/api/devices/{$this->device->id}/alarms", ['time' => '07:00', 'days' => []])
            ->assertUnprocessable()
            ->assertJson(['code' => 'device_rejected', 'message' => 'alarm limit reached']);
    }
}
