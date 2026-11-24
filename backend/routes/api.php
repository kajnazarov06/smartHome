<?php

use App\Http\Controllers\Api\AlarmController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\PairingController;
use App\Http\Controllers\Api\ReadingController;
use Illuminate\Support\Facades\Route;

/*
| Public JSON API (prefix /api). The web UI and the v2 mobile app use the same routes.
| v1 auth: Sanctum SPA cookie session. v2 adds bearer-token login for the mobile app.
*/

Route::prefix('auth')->group(function () {
    Route::get('status', [AuthController::class, 'status']);
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Discovery & pairing
    Route::get('discovery', [DiscoveryController::class, 'index']);
    Route::post('discovery/scan', [DiscoveryController::class, 'scan'])->middleware('throttle:10,1');
    Route::post('discovery/probe', [DiscoveryController::class, 'probe'])->middleware('throttle:30,1');
    Route::middleware('throttle:pairing')->group(function () {
        Route::post('pairing/start', [PairingController::class, 'start']);
        Route::post('pairing/confirm', [PairingController::class, 'confirm']);
    });

    // Devices (owner only; other users' devices answer 404)
    Route::get('devices', [DeviceController::class, 'index']);
    // scopeBindings: {alarm} must belong to {device}, else 404.
    Route::middleware('can:manage,device')->prefix('devices/{device}')->scopeBindings()->group(function () {
        Route::get('/', [DeviceController::class, 'show']);
        Route::patch('/', [DeviceController::class, 'update']);
        Route::delete('/', [DeviceController::class, 'destroy']);
        Route::get('state', [DeviceController::class, 'state']);
        Route::put('settings', [DeviceController::class, 'updateSettings']);
        Route::get('readings', ReadingController::class);

        Route::get('alarms', [AlarmController::class, 'index']);
        Route::post('alarms', [AlarmController::class, 'store']);
        Route::put('alarms/{alarm}', [AlarmController::class, 'update'])->whereNumber('alarm');
        Route::delete('alarms/{alarm}', [AlarmController::class, 'destroy'])->whereNumber('alarm');
    });
});
