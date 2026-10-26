<?php

use App\Http\Controllers\Api\CustomerPortalController as C;
use App\Http\Middleware\CustomerAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('customer')->middleware(CustomerAccess::class)->group(function () {
    Route::get('portal', [C::class, 'portal']);
    Route::get('spm', [C::class, 'spms']);
    Route::get('spm/{id}', [C::class, 'spm'])->whereUuid('id');
    Route::get('invoices', [C::class, 'invoices']);
    Route::get('invoices/{id}', [C::class, 'invoice'])->whereUuid('id');
});
