<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tariff\VendorRates;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** A vendor's own buying rates per vehicle type: view, propose, change a draft, submit. The tenant approves them like any other tariff. */
class VendorRateController extends Controller
{
    private function rates(Request $r): VendorRates
    {
        return new VendorRates(OrderController::storeFor($r), $r->attributes->get('vendor_id'));
    }

    public function options(Request $r)
    {
        return response()->json(['data' => $this->rates($r)->options()]);
    }

    public function index(Request $r)
    {
        return response()->json(['data' => $this->rates($r)->list()]);
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->rates($r)->show($id)]);
    }

    public function store(Request $r)
    {
        return response()->json(['data' => $this->rates($r)->create($r->all())], 201);
    }

    public function update(Request $r, string $id)
    {
        return response()->json(['data' => $this->rates($r)->update($id, $r->all())]);
    }

    public function addLine(Request $r, string $id)
    {
        return response()->json(['data' => $this->rates($r)->addLine($id, $r->all())], 201);
    }

    public function updateLine(Request $r, string $id, string $line)
    {
        return response()->json(['data' => $this->rates($r)->updateLine($id, $line, $r->all())]);
    }

    public function deleteLine(Request $r, string $id, string $line)
    {
        $this->rates($r)->deleteLine($id, $line);

        return response()->noContent();
    }

    public function submit(Request $r, string $id)
    {
        return response()->json(['data' => $this->rates($r)->submit($id)]);
    }

    public function withdraw(Request $r, string $id)
    {
        return response()->json(['data' => $this->rates($r)->withdraw($id)]);
    }

    public function newVersion(Request $r, string $id)
    {
        return response()->json(['data' => $this->rates($r)->newVersion($id, (string) $r->input('valid_from'))], 201);
    }
}
