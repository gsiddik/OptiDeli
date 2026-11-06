<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Integration\Pickup\PickupRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Pickup requests from OptiStash (docs/integration/PICKUP_REQUESTS.md). Reading needs operations.view; linking a warehouse, making the order
 * and rejecting need spm.manage (they make an SPM); the defaults that let a request become an order by itself need master.manage.
 */
class PickupRequestController extends Controller
{
    private const STAGES = 'FIRST_MILE,MIDDLE_MILE,LAST_MILE,E2E';

    public function index(Request $r)
    {
        Access::authorize($r, 'operations.view', 'Akun Anda tidak boleh melihat permintaan jemput.');
        $d = $r->validate(['status' => 'nullable|in:RECEIVED,CONVERTED,REJECTED', 'page' => 'nullable|integer|min:1|max:10000']);

        return response()->json(PickupRequests::list($r->attributes->get('tenant')->id, $d['status'] ?? null, (int) ($d['page'] ?? 1)));
    }

    public function show(Request $r, string $id)
    {
        Access::authorize($r, 'operations.view', 'Akun Anda tidak boleh melihat permintaan jemput.');

        return response()->json(['data' => PickupRequests::show(OrderController::storeFor($r), $id)]);
    }

    public function link(Request $r, string $id)
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh menautkan gudang.');
        $d = $r->validate(['location_id' => 'required|uuid']);

        return response()->json(['data' => PickupRequests::linkWarehouse(OrderController::storeFor($r), $id, $d['location_id'])]);
    }

    public function convert(Request $r, string $id)
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh membuat order dari permintaan jemput.');
        $d = $r->validate([
            'customer_id' => 'required|uuid', 'service_id' => 'required|uuid', 'destination_location_id' => 'required|uuid', 'stage' => 'required|in:'.self::STAGES,
            'planned_pickup_at' => 'nullable|date', 'notes' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => PickupRequests::convert(OrderController::storeFor($r), $id, $d)]);
    }

    public function reject(Request $r, string $id)
    {
        Access::authorize($r, 'spm.manage', 'Akun Anda tidak boleh menolak permintaan jemput.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => PickupRequests::reject(OrderController::storeFor($r), $id, $d['reason'])]);
    }

    public function settings(Request $r)
    {
        Access::authorize($r, 'operations.view', 'Akun Anda tidak boleh melihat pengaturan permintaan jemput.');

        return response()->json(['data' => PickupRequests::settings($r->attributes->get('tenant')->id)]);
    }

    public function saveSettings(Request $r)
    {
        Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah pengaturan permintaan jemput.');
        $d = $r->validate([
            'auto_order' => 'required|boolean', 'customer_id' => 'nullable|uuid', 'service_id' => 'nullable|uuid',
            'destination_location_id' => 'nullable|uuid', 'stage' => 'required|in:'.self::STAGES,
        ]);

        return response()->json(['data' => PickupRequests::saveSettings(OrderController::storeFor($r), $d)]);
    }
}
