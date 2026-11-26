<?php

namespace App\Http\Controllers\Api;

use App\Devices\DeviceClient;
use App\Devices\DeviceStateStore;
use App\Devices\Exceptions\DeviceRequestException;
use App\Devices\Exceptions\DeviceUnreachableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceRenameRequest;
use App\Http\Requests\DeviceSettingsRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\DeviceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceClient $client,
        private readonly DeviceStateStore $states,
        private readonly DeviceSettingsService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection($request->user()->devices()->orderBy('name')->get());
    }

    public function show(Device $device): DeviceResource
    {
        return DeviceResource::make($device);
    }

    /** Rename. Same offline-first flow as `updateSettings`. */
    public function update(DeviceRenameRequest $request, Device $device): JsonResponse
    {
        return $this->settingsResponse($this->settings->update($device, $request->validated()));
    }

    /** Live state straight from the device (the dashboard normally relies on broadcasts instead). */
    public function state(Device $device): JsonResponse
    {
        $state = $this->states->put($device->id, $this->client->state($device));
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['data' => $state]);
    }

    /**
     * Saved first, then pushed to the clock when it is reachable. 200 = the clock has it;
     * 202 = saved, `settings_sync_status: pending`, pushed when the clock is back.
     * A change the clock refuses is rolled back and its error returned (422 device_rejected).
     */
    public function updateSettings(DeviceSettingsRequest $request, Device $device): JsonResponse
    {
        return $this->settingsResponse($this->settings->update($device, $request->validated()));
    }

    private function settingsResponse(Device $device): JsonResponse
    {
        return DeviceResource::make($device)->response()
            ->setStatusCode($device->settings_sync_status->httpStatus());
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
            if (! $e->isUnauthorized() && ! $request->boolean('force')) {
                throw $e;
            }
        }

        $this->states->forget($device->id);
        $device->delete();

        return response()->noContent();
    }
}
