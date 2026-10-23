<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenderDispatch;
use App\Services\Tendering\WaterfallTenderEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaterfallTenderController extends Controller
{
    public function __construct(
        protected WaterfallTenderEngineService $tenderEngine
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $dispatches = TenderDispatch::where('tenant_id', $tenantId)
            ->with('offers')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json(['data' => $dispatches]);
    }

    public function startWaterfall(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'order_id' => 'required|uuid',
            'timeout_minutes' => 'nullable|integer|min:5|max:1440',
            'ranked_vendors' => 'required|array|min:1',
            'ranked_vendors.*.vendor_id' => 'required|string',
            'ranked_vendors.*.vehicle_type' => 'required|string',
            'ranked_vendors.*.zone_origin' => 'required|string',
            'ranked_vendors.*.zone_destination' => 'required|string',
            'ranked_vendors.*.distance_km' => 'required|numeric|min:0',
        ]);

        $dispatch = $this->tenderEngine->initiateWaterfall(
            $tenantId,
            $validated['order_id'],
            $validated['ranked_vendors'],
            $validated['timeout_minutes'] ?? 30
        );

        return response()->json(['data' => $dispatch, 'message' => 'Waterfall tender berhasil diinisiasi.'], 201);
    }

    public function respondOffer(Request $request, string $offerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'action' => 'required|in:ACCEPT,REJECT',
            'rejection_reason' => 'nullable|string|max:255',
        ]);

        $dispatch = $this->tenderEngine->processVendorResponse(
            $tenantId,
            $offerId,
            $validated['action'],
            $validated['rejection_reason'] ?? null
        );

        return response()->json(['data' => $dispatch, 'message' => 'Tawaran tender berhasil diproses.']);
    }
}
