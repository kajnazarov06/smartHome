<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\ReadingHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReadingController extends Controller
{
    public function __invoke(Request $request, Device $device, ReadingHistory $history): JsonResponse
    {
        $data = $request->validate(['range' => ['sometimes', Rule::in(array_keys(ReadingHistory::RANGES))]]);

        return response()->json(['data' => $history->series($device, $data['range'] ?? '24h')]);
    }
}
