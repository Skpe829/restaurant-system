<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MarketplaceController extends Controller
{
    public function __construct(private MarketplaceService $marketplaceService) {}

    public function purchaseIngredients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'missing_ingredients' => 'required|array',
            'missing_ingredients.*' => 'integer|min:1'
        ]);

        $result = $this->marketplaceService->purchaseIngredients(
            $validated['order_id'],
            $validated['missing_ingredients']
        );

        $statusCode = $result['success'] ? 200 : 500;

        return response()->json($result, $statusCode);
    }

    public function testApiConnection(): JsonResponse
    {
        try {
            // Test with a small quantity of a common ingredient
            $testResult = $this->marketplaceService->purchaseSingleIngredient('tomato', 1);

            return response()->json([
                'success' => $testResult['success'],
                'message' => $testResult['success']
                    ? 'API connection successful'
                    : 'API connection failed',
                'test_data' => $testResult
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'API connection test failed: ' . $e->getMessage()
            ], 500);
        }
    }
}

?>