<?php

use App\Http\Controllers\Api\CutoverController as C;
use Illuminate\Support\Facades\Route;

Route::get('cutover', [C::class, 'index']);
Route::post('cutover', [C::class, 'store']);
Route::get('cutover/{id}', [C::class, 'show'])->whereUuid('id');
Route::put('cutover/{id}', [C::class, 'update'])->whereUuid('id');
Route::post('cutover/{id}/status', [C::class, 'move'])->whereUuid('id');
Route::put('cutover/{id}/checks/{code}', [C::class, 'check'])->whereUuid('id')->where('code', '[A-Z_]+');
Route::put('cutover/{id}/totals', [C::class, 'saveTotals'])->whereUuid('id');
Route::delete('cutover/{id}/totals/{period}', [C::class, 'removeTotals'])->whereUuid('id')->where('period', '\d{4}-\d{2}');
Route::post('cutover/{id}/totals/{period}/accept', [C::class, 'accept'])->whereUuid('id')->where('period', '\d{4}-\d{2}');
