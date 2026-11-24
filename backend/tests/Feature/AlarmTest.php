<?php

namespace Tests\Feature;

use App\Enums\AlarmSyncStatus;
use App\Events\AlarmsUpdated;
use App\Jobs\SyncAlarms;
use App\Models\Alarm;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlarmTest extends TestCase
{
    use RefreshDatabase;

    private const CLOCK = 'http://192.168.1.50';

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->device = Device::factory()->create(['last_ip' => '192.168.1.50']);
    }

    private function alarm(array $attributes = []): Alarm
    {
        return Alarm::query()->create([
            'device_id' => $this->device->id, 'device_alarm_id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => true,
            'sync_status' => AlarmSyncStatus::Synced, 'synced_at' => now(), ...$attributes,
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/devices/{$this->device->id}/alarms{$path}";
    }

    // ---- List -----------------------------------------------------------------------------------

    public function test_list_reconciles_with_the_clock(): void
    {
        $this->alarm(['device_alarm_id' => 9, 'time' => '05:00']); // deleted on the clock
        $changed = $this->alarm(['device_alarm_id' => 1, 'time' => '07:00', 'days' => [1, 2, 5], 'label' => 'Work']);
        Http::fake([self::CLOCK.'/api/alarms' => Http::response([
            'api_version' => 1,
            'alarms' => [
                ['id' => 1, 'time' => '07:00', 'days' => [5, 1, 2], 'enabled' => false, 'label' => 'Work'], // switched off on the clock
                ['id' => 3, 'time' => '09:30', 'days' => [], 'enabled' => true], // created on the clock
            ],
        ])]);

        $this->actingAs($this->device->user)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('meta.stale', false)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $changed->id)
            ->assertJsonPath('data.0.enabled', false)
            ->assertJsonPath('data.0.sync_status', 'synced')
            ->assertJsonPath('data.1.time', '09:30');

        $this->assertSame([1, 3], Alarm::query()->orderBy('device_alarm_id')->pluck('device_alarm_id')->all());
    }

    public function test_list_comes_from_the_database_when_the_clock_is_unreachable(): void
    {
        $alarm = $this->alarm(['time' => '06:30', 'days' => [6, 7], 'enabled' => false, 'label' => 'Weekend']);
        Http::fake(['*' => Http::failedConnection()]);

        $this->actingAs($this->device->user)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('data.0.id', $alarm->id)
            ->assertJsonPath('data.0.label', 'Weekend');
    }

    public function test_list_does_not_call_an_offline_clock(): void
    {
        $this->device->update(['last_seen_at' => now()->subHour()]);
        $this->alarm();
        Http::fake();

        $this->actingAs($this->device->user)->getJson($this->url())->assertOk()->assertJsonPath('meta.stale', true);

        Http::assertNothingSent();
    }

    // ---- Writes while the clock is reachable -----------------------------------------------------

    public function test_create_is_pushed_to_the_clock(): void
    {
        Http::fake([self::CLOCK.'/api/alarms' => Http::response([
            'api_version' => 1, 'id' => 4, 'time' => '07:15', 'days' => [1, 2, 3, 4, 5], 'enabled' => true, 'label' => 'Gym',
        ], 201)]);

        $id = $this->actingAs($this->device->user)->postJson($this->url(), [
            'time' => '07:15', 'days' => [1, 2, 3, 4, 5], 'label' => 'Gym',
        ])->assertCreated()->assertJsonPath('data.sync_status', 'synced')->json('data.id');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['time'] === '07:15' && $r['enabled'] === true);
        $this->assertDatabaseHas('alarms', ['id' => $id, 'device_alarm_id' => 4, 'label' => 'Gym', 'sync_status' => 'synced']);
    }

    public function test_create_validates_input(): void
    {
        Http::fake();

        $this->actingAs($this->device->user)->postJson($this->url(), ['time' => '25:00', 'days' => [0, 8]])
            ->assertJsonValidationErrors(['time', 'days.0', 'days.1']);

        Http::assertNothingSent();
    }

    public function test_create_is_refused_at_ten_alarms(): void
    {
        foreach (range(1, 10) as $id) {
            $this->alarm(['device_alarm_id' => $id]);
        }
        Http::fake();

        $this->actingAs($this->device->user)->postJson($this->url(), ['time' => '07:00', 'days' => []])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_update_and_delete(): void
    {
        $alarm = $this->alarm();
        Http::fake([
            self::CLOCK.'/api/alarms/2' => Http::sequence()
                ->push(['api_version' => 1, 'id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => false])
                ->push(['api_version' => 1]),
        ]);

        $this->actingAs($this->device->user)->putJson($this->url("/{$alarm->id}"), ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.sync_status', 'synced');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['enabled'] === false && $r['time'] === '07:00');

        $this->actingAs($this->device->user)->deleteJson($this->url("/{$alarm->id}"))->assertNoContent();
        $this->assertDatabaseCount('alarms', 0);
    }

    public function test_update_recreates_an_alarm_the_clock_lost(): void
    {
        $alarm = $this->alarm();
        Http::fake([
            self::CLOCK.'/api/alarms/2' => Http::response(['error' => 'no such alarm'], 404),
            self::CLOCK.'/api/alarms' => Http::response(['id' => 7, 'time' => '08:00', 'days' => [1], 'enabled' => true], 201),
        ]);

        $this->actingAs($this->device->user)->putJson($this->url("/{$alarm->id}"), ['time' => '08:00'])->assertOk();

        $this->assertSame(7, $alarm->fresh()->device_alarm_id);
    }

    public function test_a_refused_change_is_rolled_back_and_reported(): void
    {
        $alarm = $this->alarm();
        Http::fake(['*' => Http::response(['error' => 'unsupported sound'], 400)]);

        $this->actingAs($this->device->user)->putJson($this->url("/{$alarm->id}"), ['sound' => 'gong'])
            ->assertUnprocessable()
            ->assertJson(['code' => 'device_rejected', 'message' => 'unsupported sound']);
        $this->assertNull($alarm->fresh()->extra);
        $this->assertSame(AlarmSyncStatus::Synced, $alarm->fresh()->sync_status);

        $this->actingAs($this->device->user)->postJson($this->url(), ['time' => '07:00', 'days' => []])->assertUnprocessable();
        $this->assertDatabaseCount('alarms', 1);
    }

    public function test_an_alarm_of_another_device_is_not_found(): void
    {
        $other = Device::factory()->create(['user_id' => $this->device->user_id]);
        $alarm = $this->alarm(['device_id' => $other->id]);
        Http::fake();

        $this->actingAs($this->device->user)->putJson($this->url("/{$alarm->id}"), ['enabled' => false])->assertNotFound();
    }

    // ---- Writes while the clock is unreachable ---------------------------------------------------

    public function test_changes_are_saved_as_pending_while_the_clock_is_unreachable(): void
    {
        $alarm = $this->alarm();
        Http::fake(['*' => Http::failedConnection()]);

        $created = $this->actingAs($this->device->user)->postJson($this->url(), ['time' => '06:00', 'days' => [6]])
            ->assertAccepted()->assertJsonPath('data.sync_status', 'pending')->json('data.id');
        $this->actingAs($this->device->user)->putJson($this->url("/{$alarm->id}"), ['time' => '07:30'])
            ->assertAccepted()->assertJsonPath('data.time', '07:30')->assertJsonPath('data.sync_status', 'pending');

        $this->assertDatabaseHas('alarms', ['id' => $created, 'device_alarm_id' => null, 'sync_status' => 'pending']);
    }

    public function test_an_offline_clock_is_not_called_at_all(): void
    {
        $this->device->update(['last_seen_at' => now()->subHour()]);
        $alarm = $this->alarm();
        Http::fake();

        $this->actingAs($this->device->user)->postJson($this->url(), ['time' => '06:00', 'days' => []])->assertAccepted();
        $this->actingAs($this->device->user)->deleteJson($this->url("/{$alarm->id}"))->assertAccepted();

        Http::assertNothingSent();
        $this->assertSoftDeleted($alarm);
        $this->actingAs($this->device->user)->getJson($this->url())->assertJsonCount(1, 'data');
    }

    public function test_sync_pushes_pending_changes_when_the_clock_is_back(): void
    {
        Event::fake([AlarmsUpdated::class]);
        $create = $this->alarm(['device_alarm_id' => null, 'time' => '06:00', 'sync_status' => AlarmSyncStatus::Pending]);
        $update = $this->alarm(['device_alarm_id' => 2, 'time' => '07:30', 'sync_status' => AlarmSyncStatus::Pending, 'revision' => 2]);
        $delete = $this->alarm(['device_alarm_id' => 3, 'sync_status' => AlarmSyncStatus::Pending]);
        $delete->delete();
        Http::fake([
            self::CLOCK.'/api/alarms/2' => Http::response(['id' => 2]),
            self::CLOCK.'/api/alarms/3' => Http::response([]),
            self::CLOCK.'/api/alarms' => function (Request $r) {
                return $r->method() === 'GET'
                    ? Http::response(['alarms' => [
                        ['id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => true],
                        ['id' => 3, 'time' => '07:00', 'days' => [1], 'enabled' => true],
                    ]])
                    : Http::response(['id' => 8], 201);
            },
        ]);

        SyncAlarms::dispatchSync($this->device->id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['time'] === '06:00');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['time'] === '07:30');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/alarms/3'));
        $this->assertSame(8, $create->fresh()->device_alarm_id);
        $this->assertSame(AlarmSyncStatus::Synced, $create->fresh()->sync_status);
        $this->assertSame('07:30', $update->fresh()->time, 'a pending change wins over the clock');
        $this->assertSame(AlarmSyncStatus::Synced, $update->fresh()->sync_status);
        $this->assertDatabaseMissing('alarms', ['id' => $delete->id]);
        Event::assertDispatched(AlarmsUpdated::class, fn ($e) => $e->deviceId === $this->device->id && count($e->alarms) === 2);
    }

    public function test_sync_adopts_a_create_whose_answer_was_lost(): void
    {
        $alarm = $this->alarm(['device_alarm_id' => null, 'time' => '06:00', 'days' => [], 'sync_status' => AlarmSyncStatus::Pending]);
        Http::fake([
            self::CLOCK.'/api/alarms/5' => Http::response(['id' => 5]),
            self::CLOCK.'/api/alarms' => Http::response(['alarms' => [['id' => 5, 'time' => '06:00', 'days' => [], 'enabled' => true]]]),
        ]);

        SyncAlarms::dispatchSync($this->device->id);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame(5, $alarm->fresh()->device_alarm_id);
        $this->assertDatabaseCount('alarms', 1);
    }

    public function test_sync_marks_a_refused_change_failed_and_keeps_going(): void
    {
        $refused = $this->alarm(['device_alarm_id' => 2, 'sync_status' => AlarmSyncStatus::Pending]);
        $refusedDelete = $this->alarm(['device_alarm_id' => 3, 'sync_status' => AlarmSyncStatus::Pending]);
        $refusedDelete->delete();
        $fine = $this->alarm(['device_alarm_id' => 4, 'time' => '09:00', 'sync_status' => AlarmSyncStatus::Pending]);
        Http::fake([
            self::CLOCK.'/api/alarms/2' => Http::response(['error' => 'bad sound'], 400),
            self::CLOCK.'/api/alarms/3' => Http::response(['error' => 'busy ringing'], 409),
            self::CLOCK.'/api/alarms/4' => Http::response(['id' => 4]),
            self::CLOCK.'/api/alarms' => Http::response(['alarms' => [
                ['id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => true],
                ['id' => 3, 'time' => '07:00', 'days' => [1], 'enabled' => true],
                ['id' => 4, 'time' => '07:00', 'days' => [1], 'enabled' => true],
            ]]),
        ]);

        SyncAlarms::dispatchSync($this->device->id);

        $this->assertSame(AlarmSyncStatus::Failed, $refused->fresh()->sync_status);
        $this->assertSame(['code' => 'device_rejected', 'message' => 'bad sound'], $refused->fresh()->sync_error);
        $this->assertNull($refusedDelete->fresh()->deleted_at, 'a refused delete becomes visible again');
        $this->assertSame(AlarmSyncStatus::Synced, $fine->fresh()->sync_status);
    }

    public function test_sync_leaves_changes_pending_while_the_clock_is_unreachable(): void
    {
        $alarm = $this->alarm(['sync_status' => AlarmSyncStatus::Pending]);
        Http::fake(['*' => Http::failedConnection()]);

        SyncAlarms::dispatchSync($this->device->id);

        $this->assertSame(AlarmSyncStatus::Pending, $alarm->fresh()->sync_status);
    }

    public function test_sync_does_not_overwrite_a_newer_user_change(): void
    {
        $alarm = $this->alarm(['time' => '07:30', 'sync_status' => AlarmSyncStatus::Pending]);
        Http::fake([
            self::CLOCK.'/api/alarms' => Http::response(['alarms' => [['id' => 2, 'time' => '07:00', 'days' => [1], 'enabled' => true]]]),
            // The user edits the alarm while the clock is answering the sync's PUT.
            self::CLOCK.'/api/alarms/2' => function () use ($alarm) {
                Alarm::query()->whereKey($alarm->id)->update(['time' => '08:00', 'revision' => 2]);

                return Http::response(['id' => 2]);
            },
        ]);

        SyncAlarms::dispatchSync($this->device->id);

        $this->assertSame('08:00', $alarm->fresh()->time);
        $this->assertSame(AlarmSyncStatus::Pending, $alarm->fresh()->sync_status, 'the newer revision still has to be pushed');
    }

    public function test_command_queues_online_devices_with_pending_changes(): void
    {
        Queue::fake();
        $this->alarm(['sync_status' => AlarmSyncStatus::Pending]);
        $offline = Device::factory()->offline()->create();
        Alarm::query()->create(['device_id' => $offline->id, 'time' => '07:00', 'days' => []]);
        $synced = Device::factory()->create();
        Alarm::query()->create(['device_id' => $synced->id, 'time' => '07:00', 'days' => [], 'sync_status' => AlarmSyncStatus::Synced]);

        $this->artisan('alarms:sync')->assertSuccessful();

        Queue::assertPushed(SyncAlarms::class, 1);
        Queue::assertPushed(SyncAlarms::class, fn ($job) => $job->deviceId === $this->device->id);
    }
}
