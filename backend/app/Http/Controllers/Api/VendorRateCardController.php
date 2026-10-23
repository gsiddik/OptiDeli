<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendorRateCard;
use App\Services\Tendering\RateCardEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorRateCardController extends Controller
{
    public function __construct(
        protected RateCardEngineService $rateEngine
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $cards = VendorRateCard::where('tenant_id', $tenantId)
            ->orderBy('vendor_id')
            ->orderBy('vehicle_type')
            ->get();

        return response()->json(['data' => $cards]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'vendor_id' => 'required|string',
            'vehicle_type' => 'required|string',
            'zone_origin' => 'required|string',
            'zone_destination' => 'required|string',
            'base_rate_minor_units' => 'required|integer|min:0',
            'rate_per_km_minor_units' => 'required|integer|min:0',
            'min_distance_km' => 'required|integer|min:0',
        ]);

        $card = VendorRateCard::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'vendor_id' => $validated['vendor_id'],
                'vehicle_type' => $validated['vehicle_type'],
                'zone_origin' => $validated['zone_origin'],
                'zone_destination' => $validated['zone_destination'],
            ],
            array_merge($validated, ['is_active' => true])
        );

        return response()->json(['data' => $card, 'message' => 'Rate card berhasil disimpan.'], 201);
    }

    public function calculateEstimate(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'vendor_id' => 'required|string',
            'vehicle_type' => 'required|string',
            'zone_origin' => 'required|string',
            'zone_destination' => 'required|string',
            'distance_km' => 'required|numeric|min:0',
        ]);

        $costMinorUnits = $this->rateEngine->calculateEstimatedCost(
            $tenantId,
            $validated['vendor_id'],
            $validated['vehicle_type'],
            $validated['zone_origin'],
            $validated['zone_destination'],
            (float) $validated['distance_km']
        );

        return response()->json([
            'estimated_cost_minor_units' => $costMinorUnits,
            'estimated_cost_idr' => $costMinorUnits / 100,
        ]);
    }
}
