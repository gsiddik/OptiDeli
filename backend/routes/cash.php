<?php

use App\Http\Controllers\Api\CashController as C;
use Illuminate\Support\Facades\Route;

Route::get('workqueue', [C::class, 'workQueue']);
Route::get('cash/overview', [C::class, 'overview']);
Route::get('cash/ledger', [C::class, 'ledger']);
Route::put('cash/limit', [C::class, 'limit']);
Route::post('cash/cashboxes', [C::class, 'createCashbox']);
Route::post('cash/cashboxes/{id}/status', [C::class, 'cashboxStatus'])->whereUuid('id');
Route::post('cash/cashboxes/{id}/topups', [C::class, 'topUp'])->whereUuid('id');
Route::get('cash/vouchers', [C::class, 'vouchers']);
Route::post('spm-trips/{trip}/cash-vouchers', [C::class, 'request'])->whereUuid('trip');
Route::get('cash/vouchers/{id}', [C::class, 'show'])->whereUuid('id');
Route::post('cash/vouchers/{id}/approve', [C::class, 'approve'])->whereUuid('id');
Route::post('cash/vouchers/{id}/reject', [C::class, 'reject'])->whereUuid('id');
Route::post('cash/vouchers/{id}/cancel', [C::class, 'cancel'])->whereUuid('id');
Route::post('cash/vouchers/{id}/disburse', [C::class, 'disburse'])->whereUuid('id');
Route::post('cash/vouchers/{id}/settle', [C::class, 'settle'])->whereUuid('id');
