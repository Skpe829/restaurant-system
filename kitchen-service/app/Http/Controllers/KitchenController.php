<?php

namespace App\Http\Controllers;

use App\Services\KitchenService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KitchenController extends Controller
{
    public function __construct(private KitchenService $kitchenService) {}

    public function processOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        $result = $this->kitchenService->processOrder(
            $validated['order_id'],
            $validated['quantity']
        );

        $statusCode = $result['success'] ? 200 : 500;

        return response()->json($result, $statusCode);
    }

    public function startPreparation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'selected_recipes' => 'required|array',
            'selected_recipes.*.name' => 'required|string',
            'selected_recipes.*.ingredients' => 'required|array',
        ]);

        $result = $this->kitchenService->startPreparation(
            $validated['order_id'],
            $validated['selected_recipes']
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'preparation_time_minutes' => $result['total_preparation_time'] ?? 0,
            'estimated_ready_at' => $result['estimated_ready_at'] ?? null
        ]);
    }
}
