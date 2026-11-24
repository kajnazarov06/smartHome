<?php

namespace App\Devices;

use App\Devices\Exceptions\DeviceRequestException;
use App\Devices\Exceptions\DeviceUnreachableException;
use App\Devices\Exceptions\InvalidPairingCodeException;
use App\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the device local API (see "Device local API contract" in CLAUDE.md).
 *
 * Every call has a short timeout; failures surface as DeviceException subclasses,
 * which bootstrap/app.php renders as JSON errors.
 */
class DeviceClient
{
    public function __construct(private readonly Http $http) {}

    // ---- Unauthenticated calls (discovery & pairing) -------------------------------------------

    /** @return array{id:string,type:string,name?:string,fw?:string,mac?:string,paired?:bool,capabilities?:list<string>} */
    public function info(string $host, ?float $timeout = null): array
    {
        return $this->send($host, null, 'get', '/api/info', timeout: $timeout);
    }

    public function startPairing(string $host): array
    {
        return $this->send($host, null, 'post', '/api/pair/start');
    }

    /** Exchanges the code shown on the display for a long-lived device token. */
    public function confirmPairing(string $host, string $code): string
    {
        try {
            $body = $this->send($host, null, 'post', '/api/pair/confirm', ['code' => $code]);
        } catch (DeviceRequestException $e) {
            if (in_array($e->deviceStatus, [400, 401, 403, 422], true)) {
                throw new InvalidPairingCodeException($e->body['attempts_left'] ?? null);
            }
            throw $e;
        }

        if (! is_string($body['token'] ?? null) || $body['token'] === '') {
            throw new DeviceRequestException(502, $body, 'Device did not return a token.');
        }

        return $body['token'];
    }

    // ---- Authenticated calls ------------------------------------------------------------------

    public function unpair(Device $device): void
    {
        $this->send($device->last_ip, $device->token, 'post', '/api/unpair');
    }

    /** @return array{time?:string,tz?:string,temperature_c?:float,humidity_pct?:float,wifi_rssi?:int,uptime_s?:int,next_alarm?:mixed} */
    public function state(Device $device): array
    {
        return $this->send($device->last_ip, $device->token, 'get', '/api/state');
    }

    /** @return list<array{id:int,time:string,days:list<int>,enabled:bool,label?:string}> */
    public function alarms(Device $device): array
    {
        $body = $this->send($device->last_ip, $device->token, 'get', '/api/alarms');

        // The device may answer with a bare list or with {alarms: [...], api_version}.
        return array_values(array_is_list($body) ? $body : ($body['alarms'] ?? []));
    }

    public function createAlarm(Device $device, array $alarm): array
    {
        return $this->unwrapAlarm($this->send($device->last_ip, $device->token, 'post', '/api/alarms', $alarm));
    }

    public function updateAlarm(Device $device, int $alarmId, array $alarm): array
    {
        return $this->unwrapAlarm($this->send($device->last_ip, $device->token, 'put', "/api/alarms/{$alarmId}", $alarm));
    }

    public function deleteAlarm(Device $device, int $alarmId): void
    {
        $this->send($device->last_ip, $device->token, 'delete', "/api/alarms/{$alarmId}");
    }

    /** @param array{name?:string,tz?:string,brightness?:int,snooze_min?:int} $settings */
    public function updateSettings(Device $device, array $settings): array
    {
        return $this->send($device->last_ip, $device->token, 'put', '/api/settings', $settings);
    }

    // ---- Internals ----------------------------------------------------------------------------

    public static function baseUrl(string $host): string
    {
        $port = (int) config('smarthome.device.port');
        $hostPart = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;

        return 'http://'.$hostPart.($port === 80 ? '' : ':'.$port);
    }

    private function request(string $host, ?string $token, ?float $timeout): PendingRequest
    {
        $timeout ??= (float) config('smarthome.device.http_timeout');

        $request = $this->http
            ->baseUrl(self::baseUrl($host))
            ->acceptJson()
            ->asJson()
            ->connectTimeout($timeout)
            ->timeout($timeout);

        return $token === null ? $request : $request->withToken($token);
    }

    private function send(?string $host, ?string $token, string $method, string $path, array $data = [], ?float $timeout = null): array
    {
        if ($host === null || $host === '') {
            throw DeviceUnreachableException::forHost('(unknown address)');
        }

        // Never log the token, request data (pairing code) or response body (pairing token).
        $context = ['host' => $host, 'method' => strtoupper($method), 'path' => $path];
        $startedAt = hrtime(true);

        try {
            /** @var Response $response */
            $request = $this->request($host, $token, $timeout);
            $response = in_array($method, ['get', 'delete'], true)
                ? $request->{$method}($path)
                : $request->{$method}($path, $data);
        } catch (ConnectionException $e) {
            Log::warning('Device unreachable', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            throw DeviceUnreachableException::forHost($host, $e);
        }

        $context += ['status' => $response->status(), 'duration_ms' => $this->elapsedMs($startedAt)];

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            Log::warning('Device request failed', $context);

            throw new DeviceRequestException($response->status(), $body);
        }

        Log::debug('Device request', $context);

        $this->checkApiVersion($host, $body);

        return $body;
    }

    private function checkApiVersion(string $host, array $body): void
    {
        $version = $body['api_version'] ?? null;
        if ($version === null) {
            return;
        }

        $major = (int) explode('.', (string) $version)[0];
        if ($major !== config('smarthome.device.api_version')) {
            Log::warning('Device API version mismatch', ['host' => $host, 'device' => $version, 'backend' => config('smarthome.device.api_version')]);
        }
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function unwrapAlarm(array $body): array
    {
        return isset($body['alarm']) && is_array($body['alarm']) ? $body['alarm'] : $body;
    }
}
