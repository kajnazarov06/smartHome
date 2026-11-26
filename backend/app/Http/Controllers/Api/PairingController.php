<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Devices\DeviceClient;
use App\Devices\DeviceStateStore;
use App\Devices\Exceptions\DeviceException;
use App\Devices\Exceptions\UnsupportedDeviceTypeException;
use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PairConfirmRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Rules\LanHost;
use App\Services\AlarmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pairing = physical presence: the clock shows a 6-digit code (120 s) that the user types in;
 * the device returns a long random token which only the backend keeps (encrypted).
 * The device itself enforces the 5-tries / 5-minute lockout.
 */
class PairingController extends Controller
{
    public function __construct(
        private readonly DeviceClient $client,
        private readonly AlarmService $alarms,
        private readonly DeviceStateStore $states,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['host' => ['required', 'string', new LanHost]]);
        $this->supportedType($this->client->info($data['host'])); // don't show a code for a device we can't pair
        $body = $this->client->startPairing($data['host']);

        return response()->json([
            'status' => 'code_displayed',
            'expires_in' => (int) ($body['expires_in'] ?? 120),
        ]);
    }

    public function confirm(PairConfirmRequest $request): JsonResponse
    {
        $host = $request->string('host')->toString();
        // Check the type before the device issues a token, so an unsupported device is never left holding one.
        $info = $this->client->info($host);
        $type = $this->supportedType($info);
        $token = $this->client->confirmPairing($host, $request->string('code')->toString());

        $existing = Device::query()->find($info['id']);
        if ($existing && $existing->user_id !== $request->user()->id) {
            // The clock was factory-reset and paired again by another account: the old token is dead anyway.
            Log::info('Device re-paired by a different account', ['device' => $info['id']]);
            $existing = null;
        }

        $name = $request->input('name')
            ?: $existing?->name
            ?? $info['name']
            ?? 'Clock '.strtoupper(substr($info['id'], -4));

        $device = Device::query()->updateOrCreate(['id' => $info['id']], [
            'user_id' => $request->user()->id,
            'type' => $type,
            'name' => $name,
            'token' => $token,
            'last_ip' => $host,
            'fw_version' => $info['fw'] ?? null,
            'mac' => $info['mac'] ?? null,
            'capabilities' => $info['capabilities'] ?? [],
            'last_seen_at' => now(),
        ]);

        $this->bestEffortSetup($device, $name, $info);

        return DeviceResource::make($device->refresh())->response()->setStatusCode(201);
    }

    private function supportedType(array $info): DeviceType
    {
        $type = (string) ($info['type'] ?? '');

        return DeviceType::tryFrom($type) ?? throw new UnsupportedDeviceTypeException($type);
    }

    /** Pushes the chosen name and warms the caches; pairing already succeeded, so failures are only logged. */
    private function bestEffortSetup(Device $device, string $name, array $info): void
    {
        try {
            if ($name !== ($info['name'] ?? null)) {
                $this->client->updateSettings($device, ['name' => $name]);
            }
            $this->states->put($device->id, $this->client->state($device));
            $this->alarms->sync($device, wait: 0); // adopts alarms already on the clock
        } catch (DeviceException $e) {
            Log::warning('Post-pairing setup failed', ['device' => $device->id, 'error' => $e->getMessage()]);
        }
    }
}
