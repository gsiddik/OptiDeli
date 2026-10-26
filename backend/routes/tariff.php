<?php

use App\Http\Controllers\Api\TariffController as T;
use Illuminate\Support\Facades\Route;

Route::get('tariffs/schema', [T::class, 'schema']);
Route::post('tariffs/resolve', [T::class, 'resolve']);
Route::get('tariffs/lines-template', [T::class, 'lineTemplate']);
Route::get('tariffs/markups', [T::class, 'markups']);
Route::post('tariffs/markups', [T::class, 'storeMarkup']);
Route::put('tariffs/markups/{id}', [T::class, 'updateMarkup'])->whereUuid('id');
Route::post('tariffs/markups/{id}/status', [T::class, 'markupStatus'])->whereUuid('id');
Route::get('tariffs', [T::class, 'index']);
Route::post('tariffs', [T::class, 'store']);
Route::get('tariffs/{id}', [T::class, 'show'])->whereUuid('id');
Route::put('tariffs/{id}', [T::class, 'update'])->whereUuid('id');
Route::post('tariffs/{id}/lines', [T::class, 'addLine'])->whereUuid('id');
Route::put('tariffs/{id}/lines/{line}', [T::class, 'updateLine'])->whereUuid('id')->whereUuid('line');
Route::delete('tariffs/{id}/lines/{line}', [T::class, 'deleteLine'])->whereUuid('id')->whereUuid('line');
Route::post('tariffs/{id}/imports', [T::class, 'importLines'])->whereUuid('id');
Route::post('tariffs/{id}/approve', [T::class, 'approve'])->whereUuid('id');
Route::post('tariffs/{id}/retire', [T::class, 'retire'])->whereUuid('id');
Route::post('tariffs/{id}/reject', [T::class, 'rejectProposal'])->whereUuid('id');
Route::post('tariffs/{id}/versions', [T::class, 'newVersion'])->whereUuid('id');
