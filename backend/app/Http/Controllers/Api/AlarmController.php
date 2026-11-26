<?php

namespace App\Http\Controllers\Api;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AlarmRequest;
use App\Http\Resources\AlarmResource;
use App\Models\Alarm;
use App\Models\Device;
use App\Services\AlarmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Alarms are saved in the database first and pushed to the clock right away when it is reachable.
 * 200/201 = the clock has the change; 202 = saved, `sync_status: pending`, it is pushed when the
 * clock is back. A change the clock refuses is rolled back and its error returned (422 device_rejected).
 */
class AlarmController extends Controller
{
    public function __construct(private readonly AlarmService $alarms) {}

    /** `meta.stale = true` means the clock could not be asked; the list is what the database holds. */
    public function index(Device $device): JsonResponse
    {
        $result = $this->alarms->list($device);

        return AlarmResource::collection($result['alarms'])
            ->additional(['meta' => ['stale' => $result['stale']]])
            ->response();
    }

    public function store(AlarmRequest $request, Device $device): JsonResponse
    {
        $alarm = $this->alarms->create($device, $request->alarm());

        return AlarmResource::make($alarm)->response()->setStatusCode($this->writeStatus($alarm, 201));
    }

    public function update(AlarmRequest $request, Device $device, Alarm $alarm): JsonResponse
    {
        $alarm = $this->alarms->update($device, $alarm, $request->alarm());

        return AlarmResource::make($alarm)->response()->setStatusCode($this->writeStatus($alarm, 200));
    }

    public function destroy(Device $device, Alarm $alarm): Response
    {
        return $this->alarms->delete($device, $alarm) ? response()->noContent() : response()->noContent(202);
    }

    private function writeStatus(Alarm $alarm, int $synced): int
    {
        return $alarm->sync_status === SyncStatus::Synced ? $synced : 202;
    }
}
