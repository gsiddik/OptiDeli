<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DispatchController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Middleware\InternalAccess;
use Illuminate\Support\Facades\Route;

Route::get('/api/v1/csrf', fn () => response()->json(['token' => csrf_token()]));
Route::get('/api/v1/health', fn () => response()->json(['status' => 'ok', 'service' => 'optideli']));
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
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
    });
    require __DIR__.'/vendor.php';
});
