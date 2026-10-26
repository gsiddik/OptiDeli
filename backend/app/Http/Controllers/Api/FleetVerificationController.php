<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Fleet\FleetVerification;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** The tenant's review of the vehicles and drivers its vendors registered. */
class FleetVerificationController extends Controller
{
    private function service(Request $r): FleetVerification
    {
        Access::authorize($r, 'vendor.fleet.verify', 'Akses verifikasi armada vendor diperlukan.');

        return new FleetVerification(OrderController::storeFor($r));
    }

    public function queue(Request $r)
    {
        return response()->json(['data' => $this->service($r)->queue((string) $r->query('status', 'PENDING'))]);
    }

    public function decide(Request $r, string $type, string $id)
    {
        return response()->json(['data' => $this->service($r)->decide(VendorFleetController::owner($type), $id, $r->all())]);
    }

    public function download(Request $r, string $id)
    {
        $this->service($r);

        return VendorFleetController::file(OrderController::storeFor($r)->find('fleet_documents', $id));
    }
}
