<?php

namespace App\Http\Controllers\Api;

use App\Devices\DeviceClient;
use App\Devices\DeviceStateStore;
use App\Devices\Exceptions\DeviceException;
use App\Devices\Exceptions\DeviceRequestException;
use App\Devices\Exceptions\DeviceUnreachableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceSettingsRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceClient $client,
        private readonly DeviceStateStore $states,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection($request->user()->devices()->orderBy('name')->get());
    }

    public function show(Device $device): DeviceResource
    {
        return DeviceResource::make($device);
    }

    /** Rename. Saved locally even if the device is offline; pushed to the device when it is reachable. */
    public function update(Request $request, Device $device): DeviceResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:64']]);
        $device->update($data);

        try {
            $this->client->updateSettings($device, ['name' => $data['name']]);
        } catch (DeviceException $e) {
            Log::info('Rename not pushed to device', ['device' => $device->id, 'error' => $e->getMessage()]);
        }

        return DeviceResource::make($device);
    }

    /** Live state straight from the device (the dashboard normally relies on broadcasts instead). */
    public function state(Device $device): JsonResponse
    {
        $state = $this->states->put($device->id, $this->client->state($device));
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['data' => $state]);
    }

    public function updateSettings(DeviceSettingsRequest $request, Device $device): DeviceResource
    {
        $settings = $request->validated();
        $this->client->updateSettings($device, $settings);

        $device->name = $settings['name'] ?? $device->name;
        $device->settings = array_merge($device->settings ?? [], array_diff_key($settings, ['name' => true]));
        $device->save();

        return DeviceResource::make($device);
    }

    /**
     * Tells the device to forget its token, then deletes the record (readings and cached alarms cascade).
     * If the device is unreachable the call fails unless `force=1`; the clock then still holds the
     * old token until it is factory-reset (10 s button hold).
     */
    public function destroy(Request $request, Device $device): Response
    {
        try {
            $this->client->unpair($device);
        } catch (DeviceUnreachableException $e) {
            if (! $request->boolean('force')) {
                throw $e;
            }
        } catch (DeviceRequestException $e) {
            // 401/403: the device already forgot us (e.g. after a factory reset). Anything else is a real error.
            if (! in_array($e->deviceStatus, [401, 403], true) && ! $request->boolean('force')) {
                throw $e;
            }
        }

        $this->states->forget($device->id);
        $device->delete();

        return response()->noContent();
    }
}
