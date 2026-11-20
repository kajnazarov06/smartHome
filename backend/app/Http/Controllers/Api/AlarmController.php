<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AlarmRequest;
use App\Models\Device;
use App\Services\AlarmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Alarms live on the device (they must ring with the laptop off), so every write is proxied
 * synchronously: the user sees immediately whether the clock accepted the change.
 */
class AlarmController extends Controller
{
    public function __construct(private readonly AlarmService $alarms) {}

    /** `meta.stale = true` means the device was unreachable and the list comes from alarms_cache. */
    public function index(Device $device): JsonResponse
    {
        $result = $this->alarms->list($device);

        return response()->json(['data' => $result['alarms'], 'meta' => ['stale' => $result['stale']]]);
    }

    public function store(AlarmRequest $request, Device $device): JsonResponse
    {
        abort_if(
            $device->cachedAlarms()->count() >= config('smarthome.device.max_alarms'),
            422,
            'A device can store at most '.config('smarthome.device.max_alarms').' alarms.',
        );

        return response()->json(['data' => $this->alarms->create($device, $request->alarm())], 201);
    }

    public function update(AlarmRequest $request, Device $device, int $alarm): JsonResponse
    {
        return response()->json(['data' => $this->alarms->update($device, $alarm, $request->alarm())]);
    }

    public function destroy(Device $device, int $alarm): Response
    {
        $this->alarms->delete($device, $alarm);

        return response()->noContent();
    }
}
