<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PairingTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = '192.168.1.50';

    private const INFO = [
        'api_version' => 1, 'id' => 'clock-ab12', 'type' => 'clock', 'name' => 'ClockStation-AB12',
        'fw' => '1.0.0', 'mac' => 'AA:BB:CC:DD:AB:12', 'paired' => false,
        'capabilities' => ['temperature', 'humidity', 'alarm', 'clock'],
    ];

    public function test_start_asks_the_device_to_show_a_code(): void
    {
        Http::fake([
            'http://192.168.1.50/api/info' => Http::response(self::INFO),
            'http://192.168.1.50/api/pair/start' => Http::response(['api_version' => 1, 'expires_in' => 120]),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/pairing/start', ['host' => self::HOST])
            ->assertOk()
            ->assertJson(['status' => 'code_displayed', 'expires_in' => 120]);
    }

    public function test_correct_code_pairs_the_device_and_stores_the_token_encrypted(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'http://192.168.1.50/api/pair/confirm' => Http::response(['api_version' => 1, 'token' => 'device-secret-token']),
            'http://192.168.1.50/api/info' => Http::response(self::INFO),
            'http://192.168.1.50/api/settings' => Http::response(['api_version' => 1]),
            'http://192.168.1.50/api/state' => Http::response(['api_version' => 1, 'temperature_c' => 24.5, 'humidity_pct' => 55]),
            'http://192.168.1.50/api/alarms' => Http::response(['api_version' => 1, 'alarms' => [
                ['id' => 1, 'time' => '07:00', 'days' => [1, 2, 3, 4, 5], 'enabled' => true, 'label' => 'Work'],
            ]]),
        ]);

        $this->actingAs($user)
            ->postJson('/api/pairing/confirm', ['host' => self::HOST, 'code' => '123456', 'name' => 'Bedroom clock'])
            ->assertCreated()
            ->assertJsonPath('data.id', 'clock-ab12')
            ->assertJsonPath('data.name', 'Bedroom clock')
            ->assertJsonPath('data.state.temperature_c', 24.5)
            ->assertJsonMissingPath('data.token');

        $device = Device::query()->findOrFail('clock-ab12');
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame(DeviceType::Clock, $device->type);
        $this->assertSame('device-secret-token', $device->token);
        $this->assertNotSame('device-secret-token', DB::table('devices')->value('token'), 'token must be encrypted at rest');
        $this->assertSame(1, $device->cachedAlarms()->count());

        Http::assertSent(fn ($request) => $request->url() === 'http://192.168.1.50/api/settings'
            && $request['name'] === 'Bedroom clock'
            && $request->hasHeader('Authorization', 'Bearer device-secret-token'));
    }

    public function test_unsupported_device_type_is_refused_before_a_code_or_token_is_issued(): void
    {
        Http::fake(['http://192.168.1.50/api/info' => Http::response(['type' => 'toaster'] + self::INFO)]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/pairing/start', ['host' => self::HOST])
            ->assertUnprocessable()
            ->assertJson(['code' => 'unsupported_device_type']);
        $this->actingAs($user)->postJson('/api/pairing/confirm', ['host' => self::HOST, 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJson(['code' => 'unsupported_device_type']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/pair/'));
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_wrong_code_fails(): void
    {
        Http::fake([
            'http://192.168.1.50/api/info' => Http::response(self::INFO),
            'http://192.168.1.50/api/pair/confirm' => Http::response(['error' => 'bad code', 'attempts_left' => 3], 401),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/pairing/confirm', ['host' => self::HOST, 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJson(['code' => 'invalid_pairing_code', 'attempts_left' => 3]);

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_device_lockout_is_reported(): void
    {
        Http::fake([
            'http://192.168.1.50/api/info' => Http::response(self::INFO),
            'http://192.168.1.50/api/pair/confirm' => Http::response(['error' => 'locked', 'retry_after' => 300], 429),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/pairing/confirm', ['host' => self::HOST, 'code' => '000000'])
            ->assertStatus(429)
            ->assertHeader('Retry-After', '300')
            ->assertJson(['code' => 'pairing_locked', 'retry_after' => 300]);
    }

    public function test_unreachable_device_returns_503(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/pairing/start', ['host' => self::HOST])
            ->assertStatus(503)
            ->assertJson(['code' => 'device_unreachable']);
    }

    public function test_only_lan_hosts_are_accepted(): void
    {
        Http::fake();
        $user = User::factory()->create();

        foreach (['8.8.8.8', 'example.com', 'http://192.168.1.5'] as $host) {
            $this->actingAs($user)->postJson('/api/pairing/start', ['host' => $host])->assertJsonValidationErrors('host');
        }
        $this->actingAs($user)->postJson('/api/pairing/confirm', ['host' => self::HOST, 'code' => '12ab'])->assertJsonValidationErrors('code');

        Http::assertNothingSent();
    }
}
