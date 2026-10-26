<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fleet\VendorFleet;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** A vendor's own vehicles and drivers. Everything is limited to the vendor of the signed-in membership. */
class VendorFleetController extends Controller
{
    private function fleet(Request $r): VendorFleet
    {
        return new VendorFleet(OrderController::storeFor($r), $r->attributes->get('vendor_id'));
    }

    public function overview(Request $r)
    {
        return response()->json(['data' => $this->fleet($r)->overview()]);
    }

    public function registerVehicle(Request $r)
    {
        return response()->json(['data' => $this->fleet($r)->registerVehicle($r->all())], 201);
    }

    public function updateVehicle(Request $r, string $id)
    {
        return response()->json(['data' => $this->fleet($r)->updateVehicle($id, $r->all())]);
    }

    public function registerDriver(Request $r)
    {
        return response()->json(['data' => $this->fleet($r)->registerDriver($r->all())], 201);
    }

    public function updateDriver(Request $r, string $id)
    {
        return response()->json(['data' => $this->fleet($r)->updateDriver($id, $r->all())]);
    }

    public function upload(Request $r, string $type, string $id)
    {
        $owner = $this->owner($type);

        return response()->json(['data' => $this->fleet($r)->uploadDocument($owner, $id, $r->except('file'), $r->file('file'))], 201);
    }

    public function setActive(Request $r, string $type, string $id)
    {
        $d = $r->validate(['active' => 'required|boolean']);

        return response()->json(['data' => $this->fleet($r)->setActive($this->owner($type), $id, (bool) $d['active'])]);
    }

    public function download(Request $r, string $id)
    {
        $s = OrderController::storeFor($r);
        $doc = $s->find('fleet_documents', $id);
        $table = $doc->owner_type === 'VEHICLE' ? 'vehicles' : 'drivers';
        abort_unless($s->query($table)->where('id', $doc->owner_id)->where('vendor_id', $r->attributes->get('vendor_id'))->exists(), 404);

        return self::file($doc);
    }

    public static function owner(string $type): string
    {
        return match ($type) {
            'vehicles' => 'VEHICLE', 'drivers' => 'DRIVER', default => abort(404)
        };
    }

    public static function file(object $doc)
    {
        abort_unless(Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->download($doc->path, strtolower($doc->kind).'-'.$doc->id.match ($doc->mime) {
            'application/pdf' => '.pdf', 'image/png' => '.png', default => '.jpg'
        }, ['Content-Type' => $doc->mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
