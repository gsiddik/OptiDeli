<?php

use App\Http\Controllers\Api\FreightController as F;
use App\Http\Controllers\Api\VendorController as V;
use App\Http\Controllers\Api\VendorFleetController as VF;
use App\Http\Controllers\Api\VendorRateController as VR;
use App\Http\Middleware\VendorAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('vendor')->middleware(VendorAccess::class)->group(function () {
    Route::get('portal', [V::class, 'portal']);
    Route::get('fleet', [VF::class, 'overview']);
    Route::post('vehicles', [VF::class, 'registerVehicle']);
    Route::put('vehicles/{id}', [VF::class, 'updateVehicle'])->whereUuid('id');
    Route::post('drivers', [VF::class, 'registerDriver']);
    Route::put('drivers/{id}', [VF::class, 'updateDriver'])->whereUuid('id');
    Route::post('{type}/{id}/documents', [VF::class, 'upload'])->whereIn('type', ['vehicles', 'drivers'])->whereUuid('id');
    Route::put('{type}/{id}/active', [VF::class, 'setActive'])->whereIn('type', ['vehicles', 'drivers'])->whereUuid('id');
    Route::get('fleet-documents/{id}', [VF::class, 'download'])->whereUuid('id');
    Route::get('rate-options', [VR::class, 'options']);
    Route::get('rates', [VR::class, 'index']);
    Route::post('rates', [VR::class, 'store']);
    Route::get('rates/{id}', [VR::class, 'show'])->whereUuid('id');
    Route::put('rates/{id}', [VR::class, 'update'])->whereUuid('id');
    Route::post('rates/{id}/lines', [VR::class, 'addLine'])->whereUuid('id');
    Route::put('rates/{id}/lines/{line}', [VR::class, 'updateLine'])->whereUuid('id')->whereUuid('line');
    Route::delete('rates/{id}/lines/{line}', [VR::class, 'deleteLine'])->whereUuid('id')->whereUuid('line');
    Route::post('rates/{id}/submit', [VR::class, 'submit'])->whereUuid('id');
    Route::post('rates/{id}/withdraw', [VR::class, 'withdraw'])->whereUuid('id');
    Route::post('rates/{id}/versions', [VR::class, 'newVersion'])->whereUuid('id');
    Route::post('invoices', [F::class, 'create']);
    Route::get('invoices/{id}', [F::class, 'show'])->whereUuid('id');
    Route::post('invoices/{correction}/correct', [F::class, 'create'])->whereUuid('correction');
    Route::post('claims', [F::class, 'claim']);
    Route::post('disputes/{id}/action', [F::class, 'disputeAction'])->whereUuid('id');
});
