<?php

use App\Devices\Exceptions\DeviceException;
use App\Devices\Exceptions\DeviceRequestException;
use App\Devices\Exceptions\InvalidPairingCodeException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channel auth for Echo at POST /api/broadcasting/auth (cookie session or bearer token).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: requests from SANCTUM_STATEFUL_DOMAINS get session + CSRF protection.
        $middleware->statefulApi();
        // nginx / Vite proxy sit in front of php-fpm.
        $middleware->trustProxies(at: ['127.0.0.1', '172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Device failures become JSON errors with a stable machine-readable `code`.
        $exceptions->render(function (DeviceException $e) {
            $body = ['message' => $e->getMessage(), 'code' => $e->errorCode()];
            $headers = [];

            if ($e instanceof InvalidPairingCodeException && $e->attemptsLeft !== null) {
                $body['attempts_left'] = $e->attemptsLeft;
            }
            if ($e instanceof DeviceRequestException && $e->deviceStatus === 429) {
                $body['code'] = 'pairing_locked';
                if ($e->retryAfter() !== null) {
                    $body['retry_after'] = $e->retryAfter();
                    $headers['Retry-After'] = (string) $e->retryAfter();
                }
            }

            return response()->json($body, $e->status(), $headers);
        });

        $exceptions->dontReport([DeviceException::class]);
    })->create();
