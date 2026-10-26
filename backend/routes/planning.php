<?php

use App\Http\Controllers\Api\ScheduleController as H;
use App\Http\Controllers\Api\TrackingController as K;
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

Route::get('route-templates', [H::class, 'templateIndex']);
Route::post('route-templates', [H::class, 'templateStore']);
Route::get('route-templates/{id}', [H::class, 'templateShow'])->whereUuid('id');
Route::put('route-templates/{id}', [H::class, 'templateUpdate'])->whereUuid('id');
Route::post('route-templates/{id}/status', [H::class, 'templateStatus'])->whereUuid('id');
Route::post('spm-trips/{id}/save-template', [H::class, 'templateFromTrip'])->whereUuid('id');

Route::get('reservations', [H::class, 'reservationIndex']);
Route::post('reservations', [H::class, 'reservationStore']);
Route::post('reservations/{id}/cancel', [H::class, 'reservationCancel'])->whereUuid('id');

Route::get('schedule', [H::class, 'schedule']);
Route::get('monitoring', [H::class, 'monitoring']);

Route::post('spm-trips/{id}/position', [K::class, 'simulate'])->whereUuid('id');
Route::get('spm-trips/{id}/signals', [K::class, 'signals'])->whereUuid('id');
