<?php

use App\Http\Controllers\Api\ExecutionController as E;
use App\Http\Controllers\Api\FreightController as F;
use App\Http\Controllers\Api\VendorController as V;
use Illuminate\Support\Facades\Route;

Route::get('trips', [E::class, 'trips']);
Route::get('trips/{id}', [E::class, 'show'])->whereUuid('id');
Route::post('trips/{id}/transition', [E::class, 'transition'])->whereUuid('id');
Route::post('trips/{id}/legs', [E::class, 'leg'])->whereUuid('id');
Route::post('legs/{id}/transition', [E::class, 'legTransition'])->whereUuid('id');
Route::post('trips/{id}/vendor', [E::class, 'vendor'])->whereUuid('id');
Route::post('trips/{id}/pods', [E::class, 'pod'])->whereUuid('id');
Route::post('pods/{id}/verify', [E::class, 'verifyPod'])->whereUuid('id');
Route::get('yard', [E::class, 'yard']);
Route::post('yard/docks', [E::class, 'dock']);
Route::post('yard/appointments', [E::class, 'book']);
Route::post('yard/appointments/{id}/transition', [E::class, 'yardTransition'])->whereUuid('id');
Route::get('vendors', [V::class, 'index']);
Route::post('vendors', [V::class, 'create']);
Route::post('vendors/{id}/account', [V::class, 'account'])->whereUuid('id');
Route::get('freight/invoices', [F::class, 'index']);
Route::post('freight/invoices', [F::class, 'create']);
Route::get('freight/invoices/{id}', [F::class, 'show'])->whereUuid('id');
Route::post('freight/invoices/{id}/approve', [F::class, 'approve'])->whereUuid('id');
Route::post('freight/invoices/{correction}/correct', [F::class, 'create'])->whereUuid('correction');
Route::post('freight/invoices/{id}/disputes', [F::class, 'dispute'])->whereUuid('id');
Route::post('freight/claims', [F::class, 'claim']);
Route::post('freight/claims/{id}/verify', [F::class, 'verifyClaim'])->whereUuid('id');
Route::post('freight/disputes/{id}/action', [F::class, 'disputeAction'])->whereUuid('id');
