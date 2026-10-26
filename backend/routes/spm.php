<?php

use App\Http\Controllers\Api\SpmController as S;
use Illuminate\Support\Facades\Route;

Route::get('spm', [S::class, 'index']);
Route::post('orders/{orderId}/spm', [S::class, 'store'])->whereUuid('orderId');
Route::get('spm/{id}', [S::class, 'show'])->whereUuid('id');
Route::put('spm/{id}', [S::class, 'update'])->whereUuid('id');
Route::post('spm/{id}/lines', [S::class, 'addLine'])->whereUuid('id');
Route::put('spm/{id}/lines/{line}', [S::class, 'updateLine'])->whereUuid('id')->whereUuid('line');
Route::delete('spm/{id}/lines/{line}', [S::class, 'deleteLine'])->whereUuid('id')->whereUuid('line');
Route::post('spm/{id}/status', [S::class, 'transition'])->whereUuid('id');
Route::post('spm/{id}/destination', [S::class, 'destination'])->whereUuid('id');
Route::post('spm/{id}/charges', [S::class, 'addCharge'])->whereUuid('id');
