<?php

use App\Http\Controllers\Api\FleetVerificationController as C;
use Illuminate\Support\Facades\Route;

Route::get('fleet-verification', [C::class, 'queue']);
Route::post('fleet-verification/{type}/{id}', [C::class, 'decide'])->whereIn('type', ['vehicles', 'drivers'])->whereUuid('id');
Route::get('fleet-documents/{id}', [C::class, 'download'])->whereUuid('id');
