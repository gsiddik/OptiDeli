<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Dispatch\DispatchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DispatchController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(OrderController::storeFor($request)->query('dispatch_plans')->orderByDesc('created_at')->paginate(25));
    }

    public function create(Request $request, DispatchService $service)
    {
        OrderController::writeAccess($request);
        $data = $request->validate([
            'order_id' => 'required|uuid', 'vehicle_id' => 'required|uuid', 'driver_id' => 'required|uuid',
            'vendor_base_minor' => 'required|integer|min:0|max:1000000000000', 'vendor_accessorial_minor' => 'required|integer|min:0|max:1000000000000',
            'starts_at' => ['required', 'date', 'after_or_equal:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'load_safety_attestation' => 'required|string|min:20|max:2000',
            'reason_code' => ['nullable', Rule::in(['CUSTOMER_RETENTION', 'BACKHAUL', 'CONTRACT_COMMITMENT'])],
            'reason_detail' => 'nullable|string|min:10|max:2000',
        ]);

        return response()->json(['data' => $service->create(OrderController::storeFor($request), $data, OrderController::key($request))], 201);
    }

    public function approve(Request $request, string $id, DispatchService $service)
    {
        return response()->json(['data' => $service->approve(OrderController::storeFor($request), $id, Access::approvalLevel($request))]);
    }

    public function confirm(Request $request, string $id, DispatchService $service)
    {
        OrderController::writeAccess($request);

        return response()->json(['data' => $service->confirm(OrderController::storeFor($request), $id)]);
    }
}
