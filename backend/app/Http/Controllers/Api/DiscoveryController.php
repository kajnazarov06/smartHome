<?php

namespace App\Http\Controllers\Api;

use App\Devices\Discovery\DiscoveredDevice;
use App\Devices\Discovery\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Jobs\ScanNetwork;
use App\Models\Device;
use App\Rules\LanHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoveryController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery) {}

    /** Unpaired devices seen recently (filled by the worker's scans). */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (DiscoveredDevice $d) => $d->toArray(), $this->discovery->unpaired()),
            'scanned_at' => $this->discovery->scannedAt(),
        ]);
    }

    /**
     * Queues a scan on the worker. The result arrives as a `.discovery.finished` broadcast;
     * clients without Echo can poll GET /api/discovery.
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subnet' => ['sometimes', 'nullable', 'string', 'regex:#^\d{1,3}(\.\d{1,3}){3}/(2[2-9]|3[0-2])$#'],
        ]);

        ScanNetwork::dispatch($request->user()->id, $data['subnet'] ?? null);

        return response()->json(['status' => 'scanning'], 202);
    }

    /** Manual "add by IP": fetch /api/info from the given address. */
    public function probe(Request $request): JsonResponse
    {
        $data = $request->validate(['host' => ['required', 'string', new LanHost]]);
        $device = $this->discovery->probeHost($data['host']);

        return response()->json([
            'data' => $device->toArray() + [
                'paired_here' => Device::query()->whereKey($device->id)->exists(),
            ],
        ]);
    }
}
