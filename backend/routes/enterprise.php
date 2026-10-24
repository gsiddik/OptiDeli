<?php

use App\Http\Controllers\Api\EcosystemContextController;
use App\Http\Controllers\Api\EdiGatewayController;
use App\Http\Controllers\Api\EsgCarbonController;
use App\Http\Controllers\Api\LoadOptimizerController;
use App\Http\Controllers\Api\RadarEmbedController;
use App\Http\Controllers\Api\TelematicsController;
use App\Http\Controllers\Api\VendorRateCardController;
use App\Http\Controllers\Api\WaterfallTenderController;
use App\Http\Middleware\WriteAccess;
use Illuminate\Support\Facades\Route;

// Increment 2B-5 and ecosystem endpoints. Loaded inside the auth + tenant + internal group:
// tenant comes from the session, never from a request header.
Route::get('rate-cards', [VendorRateCardController::class, 'index']);
Route::post('rate-cards/calculate-estimate', [VendorRateCardController::class, 'calculateEstimate']);
Route::get('tenders', [WaterfallTenderController::class, 'index']);
Route::post('load-optimization/build', [LoadOptimizerController::class, 'build']);
Route::get('telematics/live', [TelematicsController::class, 'live']);
// Read-only: it only mints a viewing session, so it sits outside the WriteAccess group.
Route::post('telematics/radar-embed', [RadarEmbedController::class, 'session']);
Route::get('esg/summary', [EsgCarbonController::class, 'summary']);
Route::get('esg/records', [EsgCarbonController::class, 'records']);
Route::get('edi/transmissions', [EdiGatewayController::class, 'transmissions']);
Route::get('ecosystem/context', [EcosystemContextController::class, 'getContext']);
Route::get('fleet/assets/{vehicleId}/readiness', [EcosystemContextController::class, 'checkFleetReadiness']);

Route::middleware(WriteAccess::class)->group(function () {
    Route::post('rate-cards', [VendorRateCardController::class, 'store']);
    Route::post('tenders/waterfall/start', [WaterfallTenderController::class, 'startWaterfall']);
    Route::post('tenders/offers/{offerId}/respond', [WaterfallTenderController::class, 'respondOffer']);
    Route::post('telematics/ping', [TelematicsController::class, 'ping']);
    Route::post('telematics/alerts/{alertId}/resolve', [TelematicsController::class, 'resolveAlert']);
    Route::post('esg/calculate-trip', [EsgCarbonController::class, 'recordTrip']);
    Route::post('edi/inbound-204', [EdiGatewayController::class, 'inbound204']);
    Route::post('edi/generate-214', [EdiGatewayController::class, 'generate214']);
    Route::post('edi/sync-210', [EdiGatewayController::class, 'sync210']);
    Route::post('accounting/sync', [EcosystemContextController::class, 'syncAccounting']);
});
