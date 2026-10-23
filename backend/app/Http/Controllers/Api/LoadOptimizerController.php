<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Optimizer\LoadOptimizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoadOptimizerController extends Controller
{
    public function __construct(
        protected LoadOptimizerService $optimizerService
    ) {}

    public function build(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle' => 'required|array',
            'vehicle.type' => 'required|string',
            'vehicle.max_weight_kg' => 'required|numeric|min:1',
            'vehicle.container_dimensions' => 'required|array',
            'vehicle.container_dimensions.length_cm' => 'required|numeric|min:10',
            'vehicle.container_dimensions.width_cm' => 'required|numeric|min:10',
            'vehicle.container_dimensions.height_cm' => 'required|numeric|min:10',
            'vehicle.axle_limits' => 'nullable|array',
            'vehicle.axle_limits.front_max_kg' => 'nullable|numeric|min:1',
            'vehicle.axle_limits.rear_max_kg' => 'nullable|numeric|min:1',
            'cargo_items' => 'required|array|min:1',
            'cargo_items.*.id' => 'required|string',
            'cargo_items.*.name' => 'required|string',
            'cargo_items.*.length_cm' => 'required|numeric|min:1',
            'cargo_items.*.width_cm' => 'required|numeric|min:1',
            'cargo_items.*.height_cm' => 'required|numeric|min:1',
            'cargo_items.*.weight_kg' => 'required|numeric|min:0.1',
            'cargo_items.*.quantity' => 'required|integer|min:1',
            'cargo_items.*.is_hazmat' => 'nullable|boolean',
            'cargo_items.*.stackable' => 'nullable|boolean',
        ]);

        $result = $this->optimizerService->optimize($validated['vehicle'], $validated['cargo_items']);

        return response()->json([
            'data' => $result,
            'message' => 'Kalkulasi 3D Load Optimization berhasil.',
        ]);
    }
}
