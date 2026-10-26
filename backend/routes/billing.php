<?php

use App\Http\Controllers\Api\BillingController as B;
use Illuminate\Support\Facades\Route;

Route::post('spm/{id}/charges/verify', [B::class, 'verify'])->whereUuid('id');
Route::get('spm/{id}/vendor-bill', [B::class, 'vendorPreview'])->whereUuid('id');
Route::post('spm/{id}/vendor-bill', [B::class, 'vendorBill'])->whereUuid('id');

Route::get('billing/ready', [B::class, 'ready']);
Route::get('billing/settings', [B::class, 'settings']);
Route::put('billing/settings', [B::class, 'updateSettings']);
Route::get('billing/batches', [B::class, 'batches']);
Route::post('billing/batches', [B::class, 'run']);
Route::get('billing/batches/{id}', [B::class, 'batch'])->whereUuid('id');
Route::post('billing/batches/{id}/cancel', [B::class, 'cancelBatch'])->whereUuid('id');
Route::get('billing/invoices/{id}', [B::class, 'invoice'])->whereUuid('id');

Route::get('reports', [B::class, 'reportCatalog']);
Route::get('reports/pnl', [B::class, 'pnl']);
Route::get('reports/ar-aging', [B::class, 'arAging']);
Route::get('reports/spm-status', [B::class, 'spmStatus']);
