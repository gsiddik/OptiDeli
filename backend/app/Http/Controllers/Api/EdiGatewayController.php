<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdiTransmission;
use App\Services\EDI\Edi204Service;
use App\Services\EDI\Edi210Service;
use App\Services\EDI\Edi214Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdiGatewayController extends Controller
{
    public function __construct(
        protected Edi204Service $edi204,
        protected Edi214Service $edi214,
        protected Edi210Service $edi210
    ) {}

    public function inbound204(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        $validated = $request->validate([
            'raw_x12' => 'required|string',
        ]);

        $orderData = $this->edi204->parseX12($tenantId, $validated['raw_x12']);

        return response()->json([
            'data' => $orderData,
            'message' => 'EDI 204 Load Tender berhasil diproses dan disimpan.',
        ], 201);
    }

    public function generate214(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        $validated = $request->validate([
            'shipment_id' => 'required|string',
            'status' => 'required|in:DISPATCHED,EN_ROUTE,ARRIVED,DELIVERED',
            'location_city' => 'required|string',
            'eta' => 'nullable|string',
        ]);

        $x12 = $this->edi214->generateX12(
            $tenantId,
            $validated['shipment_id'],
            $validated['status'],
            $validated['location_city'],
            $validated['eta'] ?? null
        );

        return response()->json([
            'raw_x12' => $x12,
            'message' => 'EDI 214 Shipment Status berhasil dipancarkan.',
        ]);
    }

    public function sync210(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        $validated = $request->validate([
            'invoice_number' => 'required|string',
            'order_id' => 'required|string',
            'customer_ar_minor_units' => 'required|integer|min:0',
            'vendor_ap_minor_units' => 'required|integer|min:0',
            'accessorials_minor_units' => 'nullable|integer|min:0',
        ]);

        $x12 = $this->edi210->generateX12(
            $tenantId,
            $validated['invoice_number'],
            $validated['order_id'],
            $validated['customer_ar_minor_units'],
            $validated['vendor_ap_minor_units'],
            $validated['accessorials_minor_units'] ?? 0
        );

        return response()->json([
            'raw_x12' => $x12,
            'message' => 'EDI 210 Freight Details Invoice berhasil disinkronisasikan.',
        ]);
    }

    public function transmissions(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        $transmissions = EdiTransmission::where('tenant_id', $tenantId)
            ->orderByDesc('transmitted_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $transmissions,
        ]);
    }
}
