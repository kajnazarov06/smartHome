<?php

declare(strict_types=1);

namespace App\Devices\Listener;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\PeriodicHeartbeatQueue;
use App\Devices\DeviceClient;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\delay;

/**
 * Long-running process behind `php artisan devices:listen`.
 *
 * Holds one WebSocket per paired device (Amp fibers on the Revolt event loop). When a
 * socket can't be opened it falls back to polling GET /api/state, retrying the socket
 * with exponential backoff. The device list is reloaded from the database periodically,
 * so newly paired, removed or re-addressed devices are picked up without a restart.
 */
class DeviceListener
{
    /** @var array<string, array{device: Device, fingerprint: string, cancel: DeferredCancellation}> */
    private array $workers = [];

    private ?Rfc6455Connector $connector = null;

    private ?HttpClient $http = null;

    private ?DeferredCancellation $shutdown = null;

    /** @var (callable(string): void)|null */
    private $output;

    public function __construct(private readonly DeviceEventHandler $handler) {}

    /** @param (callable(string): void)|null $output */
    public function run(?callable $output = null): void
    {
        $this->output = $output;
        $this->shutdown = new DeferredCancellation;
        $this->http = HttpClientBuilder::buildDefault();
        $this->connector = new Rfc6455Connector(
            new Rfc6455ConnectionFactory(
                heartbeatQueue: new PeriodicHeartbeatQueue(2, max(1, (int) config('smarthome.listener.ws_heartbeat'))),
            ),
            compressionContextFactory: null, // ESP32 servers don't do permessage-deflate
        );

        $timers = [
            EventLoop::repeat((float) config('smarthome.listener.reload_interval'), fn () => $this->reload()),
            EventLoop::repeat((float) config('smarthome.listener.offline_check_interval'), fn () => $this->handler->checkOffline(
                array_column($this->workers, 'device'),
            )),
        ];

        foreach ([SIGINT, SIGTERM] as $signal) {
            try {
                $timers[] = EventLoop::onSignal($signal, fn () => $this->stop());
            } catch (Throwable) {
                // ext-pcntl / ev / uv missing: Ctrl+C still kills the process, just not gracefully.
            }
        }

        $this->reload();

        // Runs the event loop until stop() is called.
        $suspension = EventLoop::getSuspension();
        $this->shutdown->getCancellation()->subscribe(fn () => $suspension->resume());
        $suspension->suspend();

        foreach ($timers as $id) {
            EventLoop::cancel($id);
        }
    }

    public function stop(): void
    {
        $this->say('Stopping device listener...');
        foreach (array_keys($this->workers) as $id) {
            $this->stopWorker($id);
        }
        $this->shutdown?->cancel();
    }

    /** Syncs running workers with the paired devices in the database. */
    public function reload(): void
    {
        try {
            $devices = Device::query()->whereNotNull('last_ip')->get()->keyBy('id');
        } catch (Throwable $e) {
            Log::error('Device listener could not load devices', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($this->workers as $id => $worker) {
            $device = $devices->get($id);
            if ($device === null || $this->fingerprint($device) !== $worker['fingerprint']) {
                $this->stopWorker($id);
            } else {
                // Keep the in-memory model (its last_seen_at is fresher than the DB copy) but pick up renames.
                $worker['device']->name = $device->name;
            }
        }

        foreach ($devices as $id => $device) {
            if (! isset($this->workers[$id])) {
                $this->startWorker($device);
            }
        }
    }

    private function startWorker(Device $device): void
    {
        $cancel = new DeferredCancellation;
        $this->workers[$device->id] = ['device' => $device, 'fingerprint' => $this->fingerprint($device), 'cancel' => $cancel];
        $this->say("Watching {$device->name} ({$device->id}) at {$device->last_ip}");

        async(function () use ($device, $cancel) {
            try {
                $this->watch($device, $cancel->getCancellation());
            } catch (CancelledException) {
            } catch (Throwable $e) {
                Log::error('Device watcher crashed', ['device' => $device->id, 'error' => $e->getMessage()]);
                unset($this->workers[$device->id]); // restarted by the next reload()
            }
        });
    }

    private function stopWorker(string $id): void
    {
        $this->workers[$id]['cancel']->cancel();
        unset($this->workers[$id]);
        $this->handler->forget($id);
    }

    private function watch(Device $device, Cancellation $cancellation): void
    {
        $backoff = 1;
        $maxBackoff = (int) config('smarthome.listener.reconnect_max_backoff');
        $pollInterval = (int) config('smarthome.listener.poll_interval');

        while (true) {
            $cancellation->throwIfRequested();

            try {
                $this->consume($device, $this->connect($device, $cancellation), $cancellation);
                $backoff = 1; // the socket was up; reconnect quickly
            } catch (CancelledException $e) {
                $cancellation->throwIfRequested();
                // Connect timeout: fall through to polling.
            } catch (Throwable $e) {
                Log::debug('Device WebSocket unavailable', ['device' => $device->id, 'error' => $e->getMessage()]);
            }

            try {
                $this->handler->handleState($device, $this->poll($device, $cancellation));
            } catch (CancelledException $e) {
                $cancellation->throwIfRequested();
            } catch (Throwable $e) {
                Log::debug('Device poll failed', ['device' => $device->id, 'error' => $e->getMessage()]);
            }

            delay(min($backoff, $pollInterval), cancellation: $cancellation);
            $backoff = min($backoff * 2, $maxBackoff);
        }
    }

    private function connect(Device $device, Cancellation $cancellation): WebsocketConnection
    {
        $timeout = (float) config('smarthome.device.http_timeout');
        $handshake = (new WebsocketHandshake(str_replace('http://', 'ws://', DeviceClient::baseUrl($device->last_ip)).'/ws'))
            ->withQueryParameter('token', $device->token)
            ->withTcpConnectTimeout($timeout);

        return $this->connector->connect(
            $handshake,
            new CompositeCancellation($cancellation, new TimeoutCancellation($timeout)),
        );
    }

    private function consume(Device $device, WebsocketConnection $connection, Cancellation $cancellation): void
    {
        $this->say("Connected to {$device->id}");
        $this->handler->touch($device);

        // An open socket (kept honest by ping/pong heartbeats) means the device is alive,
        // even when it only pushes state every 60 s.
        $keepalive = EventLoop::repeat(
            (float) config('smarthome.listener.keepalive_interval'),
            fn () => $this->handler->touch($device),
        );

        try {
            while ($message = $connection->receive($cancellation)) {
                $this->handler->handleMessage($device, $message->buffer());
            }
        } finally {
            EventLoop::cancel($keepalive);
            if (! $connection->isClosed()) {
                $connection->close();
            }
            $this->say("Disconnected from {$device->id}");
        }
    }

    private function poll(Device $device, Cancellation $cancellation): array
    {
        $timeout = (float) config('smarthome.device.http_timeout');
        $request = new Request(DeviceClient::baseUrl($device->last_ip).'/api/state');
        $request->setHeader('Authorization', 'Bearer '.$device->token);
        $request->setHeader('Accept', 'application/json');
        $request->setTcpConnectTimeout($timeout);
        $request->setTransferTimeout($timeout);

        // Hard cap: the per-request timeouts above don't always fire when the peer vanished mid-handshake.
        $bounded = new CompositeCancellation($cancellation, new TimeoutCancellation($timeout + 1));
        $response = $this->http->request($request, $bounded);
        $body = $response->getBody()->buffer($bounded);

        if ($response->getStatus() !== 200) {
            throw new RuntimeException("GET /api/state returned HTTP {$response->getStatus()}");
        }

        $state = json_decode($body, true);
        if (! is_array($state)) {
            throw new RuntimeException('GET /api/state returned invalid JSON');
        }

        return $state;
    }

    private function fingerprint(Device $device): string
    {
        return $device->last_ip.'|'.hash('sha256', (string) $device->token);
    }

    private function say(string $line): void
    {
        if ($this->output) {
            ($this->output)($line);
        }
    }
}
