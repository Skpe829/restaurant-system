<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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

        try {
            Log::info('Marketplace: Purchase request received', [
                'order_id' => $validated['order_id'],
                'ingredients' => $validated['missing_ingredients']
            ]);

            $result = $this->marketplaceService->purchaseIngredients(
                $validated['order_id'],
                $validated['missing_ingredients']
            );

            $statusCode = $result['success'] ? 200 : 500;

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
                'message' => $this->getPurchaseMessage($result)
            ], $statusCode);

        } catch (\Exception $e) {
            Log::error('Marketplace: Purchase failed with exception', [
                'order_id' => $validated['order_id'],
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purchase failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPurchaseHistory(Request $request): JsonResponse
    {
        try {
            $orderId = $request->query('order_id');
            $limit = (int) $request->query('limit', 50);

            $history = $this->marketplaceService->getPurchaseHistory($orderId, $limit);

            return response()->json([
                'success' => true,
                'data' => $history,
                'total' => count($history),
                'filter' => $orderId ? "order_id: {$orderId}" : 'all'
            ]);

        } catch (\Exception $e) {
            Log::error('Marketplace: Failed to get purchase history', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get purchase history: ' . $e->getMessage()
            ], 500);
        }
    }

    public function testApiConnection(): JsonResponse
    {
        try {
            Log::info('Marketplace: Testing API connection to farmers market');

            $testResult = $this->marketplaceService->testSinglePurchase('tomato');

            $message = $testResult['success'] && ($testResult['quantity_sold'] ?? 0) > 0
                ? "API connection successful! Got {$testResult['quantity_sold']} units of tomato"
                : 'API connection failed or no stock available';

            return response()->json([
                'success' => $testResult['success'],
                'message' => $message,
                'test_data' => $testResult,
                'api_info' => [
                    'endpoint' => 'https://recruitment.alegra.com/api/farmers-market/buy',
                    'method' => 'GET',
                    'parameter' => 'ingredient',
                    'valid_ingredients' => [
                        'tomato', 'lemon', 'potato', 'rice', 'ketchup',
                        'lettuce', 'onion', 'cheese', 'meat', 'chicken'
                    ]
                ],
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Marketplace: API connection test failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API connection test failed: ' . $e->getMessage(),
                'api_endpoint' => 'https://recruitment.alegra.com/api/farmers-market/buy'
            ], 500);
        }
    }

    public function getApiStatus(): JsonResponse
    {
        try {
            $status = $this->marketplaceService->checkApiHealth();

            return response()->json([
                'success' => true,
                'data' => $status,
                'api_info' => [
                    'endpoint' => 'https://recruitment.alegra.com/api/farmers-market/buy',
                    'method' => 'GET',
                    'parameter' => 'ingredient'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check API status: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getPurchaseMessage(array $result): string
    {
        if (!$result['success']) {
            return 'Purchase failed - no ingredients could be obtained';
        }

        $obtained = $result['total_obtained'] ?? 0;
        $requested = $result['total_requested'] ?? 0;

        if ($obtained >= $requested) {
            return 'All ingredients purchased successfully';
        } else if ($obtained > 0) {
            return "Partial purchase: got {$obtained} out of {$requested} requested units";
        } else {
            return 'No ingredients available in marketplace';
        }
    }
}