<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Reading;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Down-samples stored readings into fixed buckets for the history charts.
 * Aggregation happens in PHP so the query stays portable across SQLite, MySQL and PostgreSQL
 * (a 7-day window is ~10k rows per device).
 */
class ReadingHistory
{
    /** range => [window seconds, bucket seconds] */
    public const RANGES = [
        '24h' => [86_400, 300],   // 288 points
        '7d' => [604_800, 1_800], // 336 points
    ];

    /** @return array{range:string, bucket_s:int, from:string, to:string, points:list<array{ts:string,temperature_c:?float,humidity_pct:?float}>} */
    public function series(Device $device, string $range, ?CarbonImmutable $now = null): array
    {
        if (! isset(self::RANGES[$range])) {
            throw new InvalidArgumentException("Unknown range {$range}");
        }

        [$window, $bucket] = self::RANGES[$range];
        $to = $now ?? CarbonImmutable::now();
        $from = $to->subSeconds($window);

        $buckets = [];
        Reading::query()
            ->where('device_id', $device->id)
            ->where('ts', '>=', $from)
            ->where('ts', '<=', $to)
            ->orderBy('ts')
            ->select(['ts', 'temperature_c', 'humidity_pct'])
            ->lazy(2000)
            ->each(function (Reading $reading) use (&$buckets, $bucket) {
                $key = intdiv($reading->ts->getTimestamp(), $bucket) * $bucket;
                $b = &$buckets[$key];
                $b ??= ['t_sum' => 0.0, 't_n' => 0, 'h_sum' => 0.0, 'h_n' => 0];
                if ($reading->temperature_c !== null) {
                    $b['t_sum'] += $reading->temperature_c;
                    $b['t_n']++;
                }
                if ($reading->humidity_pct !== null) {
                    $b['h_sum'] += $reading->humidity_pct;
                    $b['h_n']++;
                }
            });

        $points = [];
        foreach ($buckets as $ts => $b) {
            $points[] = [
                'ts' => CarbonImmutable::createFromTimestampUTC($ts)->toIso8601String(),
                'temperature_c' => $b['t_n'] ? round($b['t_sum'] / $b['t_n'], 2) : null,
                'humidity_pct' => $b['h_n'] ? round($b['h_sum'] / $b['h_n'], 1) : null,
            ];
        }

        return [
            'range' => $range,
            'bucket_s' => $bucket,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'points' => $points,
        ];
    }
}
