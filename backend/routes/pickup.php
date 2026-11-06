<?php

use App\Http\Controllers\Api\PickupRequestController as P;
use Illuminate\Support\Facades\Route;

// Pickup requests from OptiStash. Permission checks live in the controller.
Route::get('pickup-requests', [P::class, 'index']);
Route::get('pickup-settings', [P::class, 'settings']);
Route::put('pickup-settings', [P::class, 'saveSettings']);
Route::get('pickup-requests/{id}', [P::class, 'show'])->whereUuid('id');
Route::post('pickup-requests/{id}/warehouse', [P::class, 'link'])->whereUuid('id');
Route::post('pickup-requests/{id}/convert', [P::class, 'convert'])->whereUuid('id');
Route::post('pickup-requests/{id}/reject', [P::class, 'reject'])->whereUuid('id');
