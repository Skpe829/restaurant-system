<?php

namespace App\Http\Controllers;

use App\Services\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    public function __construct(private WarehouseService $warehouseService) {}

    public function checkInventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'required_ingredients' => 'required|array',
            'required_ingredients.*' => 'integer|min:1'
        ]);

        $result = $this->warehouseService->checkInventory(
            $validated['order_id'],
            $validated['required_ingredients']
        );

        return response()->json([
            'success' => !isset($result['error']),
            'data' => $result
        ]);
    }

    public function reserveIngredients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredients' => 'required|array',
            'ingredients.*' => 'integer|min:1'
        ]);

        try {
            $success = $this->warehouseService->reserveIngredients($validated['ingredients']);

            return response()->json([
                'success' => $success,
                'message' => 'Ingredients reserved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reserve ingredients: ' . $e->getMessage()
            ], 500);
        }
    }

    public function consumeIngredients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredients' => 'required|array',
            'ingredients.*' => 'integer|min:1'
        ]);

        try {
            $success = $this->warehouseService->consumeIngredients($validated['ingredients']);

            return response()->json([
                'success' => $success,
                'message' => 'Ingredients consumed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to consume ingredients: ' . $e->getMessage()
            ], 500);
        }
    }

    public function addStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredients' => 'required|array',
            'ingredients.*' => 'integer|min:1'
        ]);

        try {
            $this->warehouseService->addStock($validated['ingredients']);

            return response()->json([
                'success' => true,
                'message' => 'Stock added successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add stock: ' . $e->getMessage()
            ], 500);
        }
    }

    public function processWaitingOrders(Request $request): JsonResponse
    {
        try {
            $result = $this->warehouseService->processWaitingMarketplaceOrders();

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process waiting orders: ' . $e->getMessage()
            ], 500);
        }
    }
}
