<?php

use App\Http\Controllers\Api\TripPlanController as P;
use Illuminate\Support\Facades\Route;

Route::post('spm/{spm}/awbs', [P::class, 'createAwb'])->whereUuid('spm');
Route::put('awbs/{id}', [P::class, 'updateAwb'])->whereUuid('id');
Route::post('awbs/{id}/cancel', [P::class, 'cancelAwb'])->whereUuid('id');

Route::get('spm-trips', [P::class, 'index']);
Route::post('spm-trips', [P::class, 'store']);
Route::get('spm-trips/{id}', [P::class, 'show'])->whereUuid('id');
Route::put('spm-trips/{id}/stops', [P::class, 'reorder'])->whereUuid('id');
Route::post('spm-trips/{id}/approve', [P::class, 'approve'])->whereUuid('id');
Route::post('spm-trips/{id}/start', [P::class, 'start'])->whereUuid('id');
Route::post('spm-trips/{id}/complete', [P::class, 'complete'])->whereUuid('id');
Route::post('spm-trips/{id}/cancel', [P::class, 'cancel'])->whereUuid('id');
Route::post('spm-trips/{id}/stops/{stop}/{event}', [P::class, 'stop'])->whereUuid('id')->whereUuid('stop')->whereIn('event', ['arrive', 'depart']);
