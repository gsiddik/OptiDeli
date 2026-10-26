<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Shared\TenantStore;
use App\Domain\Tracking\TrackingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Geofence capture of the stop times. Machines (a GPS provider, OptiRadar) post positions or ENTER/EXIT events with a tracking
 * credential; an operator can post a position for a trip by hand to see where the geofence would put the vehicle, and that is
 * recorded as manual.
 */
class TrackingController extends Controller
{
    private function machine(Request $r): TrackingService
    {
        $c = $r->attributes->get('tracking_credential');

        return new TrackingService(new TenantStore($c->tenant_id, (int) $c->acts_as), app(FleetAvailability::class));
    }

    public function positions(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->machine($r)->position($r->all())])->header('Cache-Control', 'no-store');
    }

    public function events(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->machine($r)->event($r->all())])->header('Cache-Control', 'no-store');
    }

    public function simulate(Request $r, string $id): JsonResponse
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh mengirim posisi.');
        $store = OrderController::storeFor($r);
        $trip = $store->find('spm_trips', $id);

        return response()->json(['data' => (new TrackingService($store, app(FleetAvailability::class)))->position($r->all() + ['vehicle_id' => $trip->vehicle_id], 'MANUAL')]);
    }

    public function signals(Request $r, string $id): JsonResponse
    {
        $service = new TrackingService(OrderController::storeFor($r), app(FleetAvailability::class));

        return response()->json(['data' => $service->signals($id)]);
    }
}
