<?php

use App\Http\Controllers\Api\ContractController as C;
use Illuminate\Support\Facades\Route;

Route::get('contracts', [C::class, 'index']);
Route::post('contracts', [C::class, 'store']);
Route::get('contracts/{id}', [C::class, 'show'])->whereUuid('id');
Route::put('contracts/{id}', [C::class, 'update'])->whereUuid('id');
Route::post('contracts/{id}/end', [C::class, 'end'])->whereUuid('id');
Route::post('contracts/{id}/runs', [C::class, 'run'])->whereUuid('id');
Route::post('contracts/{id}/charges/verify', [C::class, 'verify'])->whereUuid('id');
Route::post('attendance', [C::class, 'attend']);
Route::get('vehicles/{vehicle}/attendance', [C::class, 'attendance'])->whereUuid('vehicle');
