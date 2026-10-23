<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Adapters\Accounting\AccountingSettlementManager;
use App\Services\Adapters\Fleet\FleetMaintenanceManager;
use App\Services\Adapters\Identity\IdentityManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcosystemContextController extends Controller
{
    public function getContext(Request $request): JsonResponse
    {
        $identity = IdentityManager::create()->resolveContext($request);

        return response()->json([
            'data' => [
                'deployment_mode' => config('optideli.deployment_mode', 'standalone'),
                'identity' => $identity,
                'active_drivers' => [
                    'identity' => config('optideli.identity.driver', 'standalone'),
                    'telematics' => config('optideli.telematics.driver', 'generic'),
                    'fleet' => config('optideli.fleet.driver', 'local'),
                    'accounting' => config('optideli.accounting.driver', 'internal'),
                ],
                'integrations' => [
                    'optinexus' => config('optideli.identity.driver') === 'optinexus',
                    'optiradar' => config('optideli.telematics.driver') === 'optiradar',
                    'optifleet' => config('optideli.fleet.driver') === 'optifleet',
                    'optientry' => config('optideli.accounting.driver') === 'optientry',
                ],
            ],
        ]);
    }

    public function checkFleetReadiness(Request $request, string $vehicleId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $result = FleetMaintenanceManager::create()->checkVehicleReadiness($tenantId, $vehicleId);

        return response()->json(['data' => $result]);
    }

    public function syncAccounting(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_ar_minor_units' => 'required|integer|min:0',
            'vendor_ap_minor_units' => 'required|integer|min:0',
            'accessorials_minor_units' => 'nullable|integer|min:0',
        ]);

        $result = AccountingSettlementManager::create()->syncSettlement(
            $tenantId,
            $validated['order_id'],
            $validated['customer_ar_minor_units'],
            $validated['vendor_ap_minor_units'],
            $validated['accessorials_minor_units'] ?? 0
        );

        return response()->json(['data' => $result]);
    }
}
