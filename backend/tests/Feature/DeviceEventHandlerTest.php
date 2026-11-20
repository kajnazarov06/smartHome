<?php

namespace Tests\Feature;

use App\Devices\DeviceStateStore;
use App\Devices\Listener\DeviceEventHandler;
use App\Events\AlarmRinging;
use App\Events\DeviceStateUpdated;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Models\Reading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeviceEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_message_is_stored_recorded_and_broadcast(): void
    {
        Event::fake();
        $device = Device::factory()->offline()->create();
        $handler = app(DeviceEventHandler::class);

        $handler->handleMessage($device, json_encode(['type' => 'state', 'temperature_c' => 24.2, 'humidity_pct' => 58]));

        $this->assertSame(24.2, app(DeviceStateStore::class)->get($device->id)['temperature_c']);
        $this->assertTrue($device->fresh()->isOnline());
        $this->assertSame(1, Reading::query()->count());
        Event::assertDispatched(DeviceStateUpdated::class, fn ($e) => $e->deviceId === $device->id && $e->userId === $device->user_id);
        Event::assertDispatched(DeviceStatusChanged::class, fn ($e) => $e->online === true);
    }

    public function test_readings_are_stored_at_most_once_per_interval(): void
    {
        Event::fake();
        $device = Device::factory()->create();
        $handler = app(DeviceEventHandler::class);

        $handler->handleState($device, ['temperature_c' => 20]);
        $this->travel(30)->seconds();
        $handler->handleState($device, ['temperature_c' => 21]);
        $this->travel(31)->seconds();
        $handler->handleState($device, ['temperature_c' => 22]);

        $this->assertSame([20.0, 22.0], Reading::query()->orderBy('ts')->pluck('temperature_c')->map(fn ($v) => (float) $v)->all());
    }

    public function test_alarm_ringing_is_broadcast(): void
    {
        Event::fake();
        $device = Device::factory()->create();

        app(DeviceEventHandler::class)->handleMessage($device, '{"type":"alarm_ringing","id":3}');

        Event::assertDispatched(AlarmRinging::class, fn ($e) => $e->alarmId === 3);
    }

    public function test_offline_transition_is_broadcast_once(): void
    {
        Event::fake();
        $device = Device::factory()->create();
        $handler = app(DeviceEventHandler::class);
        $handler->touch($device);

        $this->travel(config('smarthome.device.offline_after') + 1)->seconds();
        $handler->checkOffline([$device]);
        $handler->checkOffline([$device]);

        Event::assertDispatchedTimes(DeviceStatusChanged::class, 2); // online once, offline once
        Event::assertDispatched(DeviceStatusChanged::class, fn ($e) => $e->online === false);
    }
}
