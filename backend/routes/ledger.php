<?php

use App\Http\Controllers\Api\LedgerController;
use Illuminate\Support\Facades\Route;

// Wave 5 sub-ledger. Loaded inside the auth + tenant + internal group; every action checks finance.access.
Route::get('ledger/documents', [LedgerController::class, 'index']);
Route::get('ledger/documents/{id}', [LedgerController::class, 'show'])->whereUuid('id');
Route::get('ledger/summary', [LedgerController::class, 'summary']);
Route::post('ledger/customer-invoices', [LedgerController::class, 'customerInvoice']);
Route::post('ledger/vendor-bills', [LedgerController::class, 'vendorBill']);
Route::post('ledger/documents/{id}/payments', [LedgerController::class, 'payment'])->whereUuid('id');
Route::post('ledger/documents/{id}/void', [LedgerController::class, 'void'])->whereUuid('id');
