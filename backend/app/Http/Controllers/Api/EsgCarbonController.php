<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarbonEmissionRecord;
use App\Services\Analytics\EsgCarbonEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EsgCarbonController extends Controller
{
    public function __construct(
        protected EsgCarbonEngineService $esgEngine
    ) {}

    public function recordTrip(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'trip_id' => 'required|string',
            'vehicle_id' => 'required|string',
            'fleet_ownership' => 'required|in:INTERNAL,VENDOR,internal,vendor',
            'fuel_type' => 'required|in:DIESEL_B35,DIESEL_EURO4,CNG,EV,diesel_b35,diesel_euro4,cng,ev',
            'distance_km' => 'required|numeric|min:0.1',
            'cargo_weight_ton' => 'required|numeric|min:0.01',
        ]);

        $record = $this->esgEngine->recordTripEmission($tenantId, $validated);

        return response()->json([
            'data' => $record,
            'message' => 'Jejak emisi karbon berhasil dihitung dan dicatat.',
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $summary = $this->esgEngine->getSummary($tenantId);

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $records = CarbonEmissionRecord::where('tenant_id', $tenantId)
            ->orderByDesc('recorded_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $records,
        ]);
    }
}
