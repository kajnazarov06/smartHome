<?php

namespace Tests\Feature;

use App\Devices\Listener\DeviceEventHandler;
use App\Enums\SyncStatus;
use App\Events\DeviceSettingsUpdated;
use App\Jobs\SyncDeviceSettings;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeviceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CLOCK = 'http://192.168.1.50/api/settings';

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->device = Device::factory()->create([
            'last_ip' => '192.168.1.50', 'name' => 'Bedroom', 'settings' => ['tz' => 'Europe/Paris', 'brightness' => 80],
        ]);
    }

    private function url(): string
    {
        return "/api/devices/{$this->device->id}/settings";
    }

    public function test_the_full_desired_state_is_pushed(): void
    {
        Http::fake([self::CLOCK => Http::response(['api_version' => 1])]);

        $this->actingAs($this->device->user)->putJson($this->url(), ['brightness' => 40])
            ->assertOk()
            ->assertJsonPath('data.settings', ['tz' => 'Europe/Paris', 'brightness' => 40])
            ->assertJsonPath('data.settings_sync_status', 'synced');

        Http::assertSent(fn (Request $r) => $r->data() === ['name' => 'Bedroom', 'tz' => 'Europe/Paris', 'brightness' => 40]);
        $this->assertNotNull($this->device->fresh()->settings_synced_at);
    }

    public function test_changes_are_saved_as_pending_while_the_clock_is_unreachable(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->actingAs($this->device->user)->putJson($this->url(), ['brightness' => 10, 'name' => 'Kids'])
            ->assertAccepted()
            ->assertJsonPath('data.name', 'Kids')
            ->assertJsonPath('data.settings.brightness', 10)
            ->assertJsonPath('data.settings_sync_status', 'pending');
    }

    public function test_an_offline_clock_is_not_called_at_all(): void
    {
        $this->device->update(['last_seen_at' => now()->subHour()]);
        Http::fake();

        $this->actingAs($this->device->user)->putJson($this->url(), ['snooze_min' => 5])->assertAccepted();

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Pending, $this->device->fresh()->settings_sync_status);
    }

    public function test_a_refused_change_is_rolled_back_and_reported(): void
    {
        Http::fake([self::CLOCK => Http::response(['error' => 'unknown tz'], 400)]);

        $this->actingAs($this->device->user)->putJson($this->url(), ['tz' => 'Asia/Tokyo', 'name' => 'New'])
            ->assertStatus(422)->assertJsonPath('code', 'device_rejected');

        $fresh = $this->device->fresh();
        $this->assertSame('Bedroom', $fresh->name);
        $this->assertSame('Europe/Paris', $fresh->settings['tz']);
        $this->assertSame(SyncStatus::Synced, $fresh->settings_sync_status);
    }

    public function test_sync_pushes_pending_settings_when_the_clock_is_back(): void
    {
        Event::fake([DeviceSettingsUpdated::class]);
        $this->device->forceFill(['settings_sync_status' => SyncStatus::Pending, 'settings_revision' => 3])->save();
        Http::fake([self::CLOCK => Http::response(['api_version' => 1])]);

        SyncDeviceSettings::dispatchSync($this->device->id);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['brightness'] === 80 && $r['name'] === 'Bedroom');
        $this->assertSame(SyncStatus::Synced, $this->device->fresh()->settings_sync_status);
        Event::assertDispatched(DeviceSettingsUpdated::class, fn ($e) => $e->broadcastWith()['settings_sync_status'] === 'synced');
    }

    public function test_sync_does_nothing_without_pending_settings(): void
    {
        Http::fake();

        SyncDeviceSettings::dispatchSync($this->device->id);

        Http::assertNothingSent();
    }

    public function test_sync_marks_a_refused_change_failed(): void
    {
        $this->device->forceFill(['settings_sync_status' => SyncStatus::Pending])->save();
        Http::fake([self::CLOCK => Http::response(['error' => 'bad brightness'], 422)]);

        SyncDeviceSettings::dispatchSync($this->device->id);

        $fresh = $this->device->fresh();
        $this->assertSame(SyncStatus::Failed, $fresh->settings_sync_status);
        $this->assertSame(['code' => 'device_rejected', 'message' => 'bad brightness'], $fresh->settings_sync_error);
    }

    public function test_sync_leaves_settings_pending_while_the_clock_is_unreachable(): void
    {
        $this->device->forceFill(['settings_sync_status' => SyncStatus::Pending])->save();
        Http::fake(['*' => Http::failedConnection()]);

        SyncDeviceSettings::dispatchSync($this->device->id);

        $this->assertSame(SyncStatus::Pending, $this->device->fresh()->settings_sync_status);
    }

    public function test_sync_does_not_mark_a_newer_user_change_synced(): void
    {
        $this->device->forceFill(['settings_sync_status' => SyncStatus::Pending])->save();
        Http::fake([self::CLOCK => function () {
            // The user changes the settings while the clock is answering the sync's PUT.
            Device::query()->whereKey($this->device->id)->update(['settings_revision' => 5]);

            return Http::response(['api_version' => 1]);
        }]);

        SyncDeviceSettings::dispatchSync($this->device->id);

        $this->assertSame(SyncStatus::Pending, $this->device->fresh()->settings_sync_status);
    }

    public function test_command_queues_online_devices_with_pending_settings(): void
    {
        Queue::fake();
        $this->device->forceFill(['settings_sync_status' => SyncStatus::Pending])->save();
        Device::factory()->offline()->create()->forceFill(['settings_sync_status' => SyncStatus::Pending])->save();
        Device::factory()->create(); // synced

        $this->artisan('settings:sync')->assertSuccessful();

        Queue::assertPushed(SyncDeviceSettings::class, 1);
        Queue::assertPushed(SyncDeviceSettings::class, fn ($job) => $job->deviceId === $this->device->id);
    }

    public function test_coming_online_queues_a_settings_sync(): void
    {
        Queue::fake();
        Event::fake();
        $device = Device::factory()->offline()->create();

        app(DeviceEventHandler::class)->touch($device);

        Queue::assertPushed(SyncDeviceSettings::class, fn ($job) => $job->deviceId === $device->id);
    }
}
