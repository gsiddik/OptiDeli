<?php

use App\Http\Controllers\Api\FreightController as F;
use App\Http\Controllers\Api\VendorController as V;
use App\Http\Middleware\VendorAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('vendor')->middleware(VendorAccess::class)->group(function () {
    Route::get('portal', [V::class, 'portal']);
    Route::post('invoices', [F::class, 'create']);
    Route::get('invoices/{id}', [F::class, 'show'])->whereUuid('id');
    Route::post('invoices/{correction}/correct', [F::class, 'create'])->whereUuid('correction');
    Route::post('claims', [F::class, 'claim']);
    Route::post('disputes/{id}/action', [F::class, 'disputeAction'])->whereUuid('id');
});
