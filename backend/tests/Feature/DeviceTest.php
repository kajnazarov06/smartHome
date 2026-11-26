<?php

namespace Tests\Feature;

use App\Devices\DeviceStateStore;
use App\Models\Device;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_own_devices_with_live_state(): void
    {
        $user = User::factory()->create();
        $mine = Device::factory()->for($user)->create(['id' => 'clock-0001']);
        Device::factory()->create(['id' => 'clock-0002']);
        app(DeviceStateStore::class)->put($mine->id, ['type' => 'state', 'temperature_c' => 22.1, 'next_alarm' => '07:00']);

        $this->actingAs($user)->getJson('/api/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'clock-0001')
            ->assertJsonPath('data.0.type', 'clock')
            ->assertJsonPath('data.0.type_label', 'Clock station')
            ->assertJsonPath('data.0.online', true)
            ->assertJsonPath('data.0.state.temperature_c', 22.1)
            ->assertJsonPath('data.0.next_alarm', '07:00')
            ->assertJsonMissingPath('data.0.token');
    }

    public function test_device_goes_offline_after_threshold(): void
    {
        $user = User::factory()->create();
        Device::factory()->for($user)->create(['last_seen_at' => now()->subSeconds(config('smarthome.device.offline_after') + 1)]);

        $this->actingAs($user)->getJson('/api/devices')->assertJsonPath('data.0.online', false);
    }

    public function test_other_users_devices_are_not_found(): void
    {
        $other = Device::factory()->create();

        $this->actingAs(User::factory()->create())->getJson("/api/devices/{$other->id}")->assertNotFound();
        $this->actingAs(User::factory()->create())->deleteJson("/api/devices/{$other->id}")->assertNotFound();
    }

    public function test_rename_saves_locally_and_pushes_to_device(): void
    {
        $device = Device::factory()->create(['last_ip' => '192.168.1.50']);
        Http::fake(['http://192.168.1.50/api/settings' => Http::response(['api_version' => 1])]);

        $this->actingAs($device->user)->patchJson("/api/devices/{$device->id}", ['name' => 'Bedroom clock'])
            ->assertOk()->assertJsonPath('data.name', 'Bedroom clock');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['name'] === 'Bedroom clock');
    }

    public function test_rename_works_while_device_is_offline(): void
    {
        $device = Device::factory()->create();
        Http::fake(['*' => Http::failedConnection()]);

        $this->actingAs($device->user)->patchJson("/api/devices/{$device->id}", ['name' => 'Kitchen'])
            ->assertAccepted()->assertJsonPath('data.settings_sync_status', 'pending');
        $this->assertSame('Kitchen', $device->fresh()->name);
    }

    public function test_settings_are_proxied_and_remembered(): void
    {
        $device = Device::factory()->create(['last_ip' => '192.168.1.50']);
        Http::fake(['http://192.168.1.50/api/settings' => Http::response(['api_version' => 1])]);

        $this->actingAs($device->user)->putJson("/api/devices/{$device->id}/settings", [
            'tz' => 'Asia/Ho_Chi_Minh', 'brightness' => 40, 'snooze_min' => 9,
        ])->assertOk()->assertJsonPath('data.settings.brightness', 40)->assertJsonPath('data.settings_sync_status', 'synced');

        $this->actingAs($device->user)->putJson("/api/devices/{$device->id}/settings", ['tz' => 'Mars/Base'])
            ->assertJsonValidationErrors('tz');
    }

    public function test_live_state_endpoint(): void
    {
        $device = Device::factory()->create(['last_ip' => '192.168.1.50']);
        Http::fake(['http://192.168.1.50/api/state' => Http::response([
            'api_version' => 1, 'time' => '2026-11-19T21:00:00+07:00', 'temperature_c' => 23.4, 'humidity_pct' => 51,
        ])]);

        $this->actingAs($device->user)->getJson("/api/devices/{$device->id}/state")
            ->assertOk()
            ->assertJsonPath('data.temperature_c', 23.4)
            ->assertJsonMissingPath('data.api_version');
    }

    public function test_a_busy_clock_is_not_reported_as_a_pairing_lockout(): void
    {
        $device = Device::factory()->create(['last_ip' => '192.168.1.50']);
        Http::fake(['http://192.168.1.50/api/state' => Http::response(['error' => 'busy'], 429)]);

        $this->actingAs($device->user)->getJson("/api/devices/{$device->id}/state")
            ->assertStatus(429)
            ->assertJson(['code' => 'device_rate_limited']);
    }

    public function test_remove_unpairs_the_device_then_deletes_the_record(): void
    {
        $device = Device::factory()->create(['last_ip' => '192.168.1.50', 'token' => 'tok']);
        Reading::query()->create(['device_id' => $device->id, 'ts' => now(), 'temperature_c' => 20, 'humidity_pct' => 50]);
        Http::fake(['http://192.168.1.50/api/unpair' => Http::response(['api_version' => 1])]);

        $this->actingAs($device->user)->deleteJson("/api/devices/{$device->id}")->assertNoContent();

        Http::assertSent(fn ($r) => $r->url() === 'http://192.168.1.50/api/unpair' && $r->hasHeader('Authorization', 'Bearer tok'));
        $this->assertModelMissing($device);
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_remove_of_unreachable_device_needs_force(): void
    {
        $device = Device::factory()->create();
        Http::fake(['*' => Http::failedConnection()]);

        $this->actingAs($device->user)->deleteJson("/api/devices/{$device->id}")->assertStatus(503);
        $this->assertModelExists($device);

        $this->actingAs($device->user)->deleteJson("/api/devices/{$device->id}?force=1")->assertNoContent();
        $this->assertModelMissing($device);
    }

    public function test_remove_succeeds_when_device_was_factory_reset(): void
    {
        $device = Device::factory()->create();
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->actingAs($device->user)->deleteJson("/api/devices/{$device->id}")->assertNoContent();
        $this->assertModelMissing($device);
    }

    public function test_readings_history_is_bucketed(): void
    {
        $device = Device::factory()->create();
        $this->travelTo(now()->startOfHour());
        foreach ([[-600, 20.0, 50], [-590, 22.0, 52], [-60, 25.0, 60], [-90_000, 10.0, 10]] as [$offset, $t, $h]) {
            Reading::query()->create(['device_id' => $device->id, 'ts' => now()->addSeconds($offset), 'temperature_c' => $t, 'humidity_pct' => $h]);
        }

        $response = $this->actingAs($device->user)->getJson("/api/devices/{$device->id}/readings?range=24h")->assertOk();

        $points = $response->json('data.points');
        $this->assertCount(2, $points, 'the 25 h old reading is outside the window');
        $this->assertSame(21.0, (float) $points[0]['temperature_c']);
        $this->assertSame(51.0, (float) $points[0]['humidity_pct']);
        $this->assertSame(300, $response->json('data.bucket_s'));

        $this->actingAs($device->user)->getJson("/api/devices/{$device->id}/readings?range=7d")->assertJsonCount(2, 'data.points'); // 30-min buckets merge the recent three
        $this->actingAs($device->user)->getJson("/api/devices/{$device->id}/readings?range=1y")->assertJsonValidationErrors('range');
    }
}
