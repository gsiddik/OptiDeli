<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelemetryAlert;
use App\Services\Telematics\TelematicsEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelematicsController extends Controller
{
    public function __construct(
        protected TelematicsEngineService $telematicsEngine
    ) {}

    public function ping(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'trip_id' => 'required|string',
            'vehicle_id' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'speed_kmh' => 'required|numeric|min:0',
            'fuel_level_pct' => 'required|numeric|between:0,100',
            'reefer_temp_celsius' => 'nullable|numeric',
            'is_door_open' => 'nullable|boolean',
        ]);

        $res = $this->telematicsEngine->processPing($tenantId, $validated);

        return response()->json([
            'data' => $res,
            'message' => 'Telemetry ping berhasil diproses.',
        ], 201);
    }

    public function live(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $fleet = $this->telematicsEngine->getLiveFleet($tenantId);

        return response()->json([
            'data' => $fleet,
        ]);
    }

    public function resolveAlert(Request $request, string $alertId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $alert = TelemetryAlert::where('tenant_id', $tenantId)
            ->where('id', $alertId)
            ->firstOrFail();

        $alert->update(['is_resolved' => true]);

        return response()->json([
            'message' => 'Peringatan telematika berhasil diselesaikan.',
        ]);
    }
}
