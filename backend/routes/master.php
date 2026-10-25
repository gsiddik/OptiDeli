<?php

use App\Domain\Master\MasterRegistry;
use App\Http\Controllers\Api\MasterController as M;
use Illuminate\Support\Facades\Route;

$resources = implode('|', array_keys(MasterRegistry::all()));

Route::get('master/schema', [M::class, 'schema']);
Route::post('master/regional-reference', [M::class, 'loadRegionalReference']);
Route::get('master/{resource}', [M::class, 'index'])->where('resource', $resources);
Route::get('master/{resource}/options', [M::class, 'options'])->where('resource', $resources);
Route::post('master/{resource}', [M::class, 'store'])->where('resource', $resources);
Route::put('master/{resource}/{id}', [M::class, 'update'])->where('resource', $resources)->whereUuid('id');
Route::post('master/{resource}/{id}/status', [M::class, 'status'])->where('resource', $resources)->whereUuid('id');
