<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Devices\Discovery\DiscoveredDevice;
use App\Devices\Discovery\DiscoveryService;
use App\Jobs\ScanNetwork;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_devices_not_paired_here(): void
    {
        $user = User::factory()->create();
        Device::factory()->for($user)->create(['id' => 'clock-paired', 'last_ip' => '192.168.1.10']);

        app(DiscoveryService::class)->remember([
            new DiscoveredDevice('clock-new1', '192.168.1.20', seenAt: now()->toImmutable()),
            new DiscoveredDevice('clock-paired', '192.168.1.11', paired: true, seenAt: now()->toImmutable()),
        ]);

        $this->actingAs($user)->getJson('/api/discovery')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'clock-new1');

        $this->assertSame('192.168.1.11', Device::query()->find('clock-paired')->last_ip, 'DHCP change picked up');
    }

    public function test_stale_entries_expire(): void
    {
        $service = app(DiscoveryService::class);
        $service->remember([new DiscoveredDevice('clock-old', '192.168.1.20', seenAt: now()->subHour()->toImmutable())]);

        $this->assertSame([], $service->all());
    }

    public function test_scan_is_queued_for_the_worker(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/discovery/scan', ['subnet' => '192.168.1.0/24'])->assertAccepted();

        Queue::assertPushed(ScanNetwork::class, fn ($job) => $job->userId === $user->id && $job->subnet === '192.168.1.0/24');
    }

    public function test_scan_rejects_subnets_the_prober_cannot_handle(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        foreach (['192.168.0.0/16', 'not-a-cidr', '300.1.1.1/24'] as $subnet) {
            $this->actingAs($user)->postJson('/api/discovery/scan', ['subnet' => $subnet])
                ->assertJsonValidationErrors('subnet');
        }
        Queue::assertNothingPushed();
    }

    public function test_add_by_ip(): void
    {
        Http::fake(['http://192.168.1.77/api/info' => Http::response(['api_version' => 1, 'id' => 'clock-77ab', 'type' => 'clock', 'paired' => false])]);

        $this->actingAs(User::factory()->create())->postJson('/api/discovery/probe', ['host' => '192.168.1.77'])
            ->assertOk()
            ->assertJsonPath('data.id', 'clock-77ab')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.paired_here', false);
    }

    public function test_subnet_probe_finds_devices(): void
    {
        config(['smarthome.discovery.mdns_enabled' => false]);
        Http::fake([
            'http://192.168.5.7/api/info' => Http::response(['api_version' => 1, 'id' => 'clock-0007', 'type' => 'clock', 'paired' => true]),
            'http://192.168.5.3/api/info' => Http::response('<html>router</html>'), // not a device
            '*' => Http::response(null, 404),
        ]);

        $found = app(DiscoveryService::class)->scan('192.168.5.0/28');

        $this->assertCount(1, $found);
        $this->assertSame('192.168.5.7', $found[0]->host);
        $this->assertTrue($found[0]->paired);
    }
}
