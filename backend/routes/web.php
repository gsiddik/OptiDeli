<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DispatchController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\OptinexusEventController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\SsoController;
use App\Http\Middleware\InternalAccess;
use App\Http\Middleware\PlatformContext;
use Illuminate\Support\Facades\Route;

Route::get('/api/v1/csrf', fn () => response()->json(['token' => csrf_token()]));
Route::get('/api/v1/health', fn () => response()->json(['status' => 'ok', 'service' => 'optideli']));
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/api/v1/auth/sso/status', [SsoController::class, 'status']);
Route::get('/api/v1/auth/sso/redirect', [SsoController::class, 'redirect'])->middleware('throttle:30,1');
Route::get('/api/v1/auth/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:30,1');
Route::post('/api/v1/integration/optinexus/backchannel-logout', [SsoController::class, 'backchannelLogout'])->middleware('throttle:120,1');
Route::post('/api/v1/integration/optinexus/events', [OptinexusEventController::class, 'receive'])->middleware('throttle:600,1');
Route::post('/api/v1/platform/auth/login', [PlatformController::class, 'login'])->middleware('throttle:login');
Route::middleware(['auth', PlatformContext::class, 'throttle:120,1'])->prefix('api/v1/platform')->group(function () {
    Route::get('auth/me', [PlatformController::class, 'me']);
    Route::get('tenants', [PlatformController::class, 'tenants']);
    Route::post('tenants', [PlatformController::class, 'createTenant']);
    Route::post('tenants/{id}/status', [PlatformController::class, 'tenantStatus'])->whereUuid('id');
    Route::post('tenants/{id}/administrators', [PlatformController::class, 'createTenantAdmin'])->whereUuid('id');
    Route::post('tenants/{id}/optinexus', [PlatformController::class, 'linkOptinexus'])->whereUuid('id');
    Route::get('audit', [PlatformController::class, 'audit']);
});
Route::middleware(['auth', 'tenant', 'throttle:120,1'])->prefix('api/v1')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('files/{kind}/{id}', [ExecutionController::class, 'file'])->whereUuid('id');
    Route::middleware(InternalAccess::class)->group(function () {
        Route::get('commercial/policy', [PolicyController::class, 'show']);
        Route::put('commercial/policy', [PolicyController::class, 'update']);
        Route::get('dashboard', [FleetController::class, 'summary']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'create']);
        Route::get('orders/{id}', [OrderController::class, 'show'])->whereUuid('id');
        Route::post('orders/{id}/transition', [OrderController::class, 'transition'])->whereUuid('id');
        Route::get('dispatch/plans', [DispatchController::class, 'index']);
        Route::post('dispatch/plans', [DispatchController::class, 'create']);
        Route::post('dispatch/plans/{id}/approve', [DispatchController::class, 'approve'])->whereUuid('id');
        Route::post('dispatch/plans/{id}/confirm', [DispatchController::class, 'confirm'])->whereUuid('id');
        Route::get('fleet', [FleetController::class, 'index']);
        Route::post('fleet/vehicles', [FleetController::class, 'vehicle']);
        Route::post('fleet/vehicles/{id}/readiness', [FleetController::class, 'readiness'])->whereUuid('id');
        Route::post('fleet/drivers', [FleetController::class, 'driver']);
        Route::get('audit', [FleetController::class, 'audit']);
        require __DIR__.'/operations.php';
        require __DIR__.'/enterprise.php';
        require __DIR__.'/access.php';
    });
    require __DIR__.'/vendor.php';
});
