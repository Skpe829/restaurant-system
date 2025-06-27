<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CallbackController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function kitchenCompleted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'selected_recipes' => 'required|array',
            'selected_recipes.*.name' => 'required|string',
            'selected_recipes.*.ingredients' => 'required|array',
        ]);

        try {
            $order = $this->orderService->updateOrderFromKitchen(
                $validated['order_id'],
                $validated['selected_recipes']
            );

            Log::info('Kitchen service completed for order: ' . $order->id);

            return response()->json([
                'success' => true,
                'message' => 'Order updated from kitchen service'
            ]);

        } catch (\Exception $e) {
            Log::error('Kitchen callback failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process kitchen callback'
            ], 500);
        }
    }

    public function warehouseCompleted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'inventory_status' => 'required|string|in:sufficient,insufficient',
            'missing_ingredients' => 'nullable|array',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            if ($validated['inventory_status'] === 'sufficient') {
                $order->update(['status' => Order::STATUS_IN_PREPARATION]);
                Log::info('Order moved to preparation: ' . $order->id);
            } else {
                // Trigger marketplace service
                $this->triggerMarketplaceService($order, $validated['missing_ingredients']);
                Log::info('Triggered marketplace for missing ingredients: ' . $order->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Warehouse callback processed'
            ]);

        } catch (\Exception $e) {
            Log::error('Warehouse callback failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process warehouse callback'
            ], 500);
        }
    }

    public function marketplaceCompleted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'purchase_status' => 'required|string|in:success,failed',
            'purchased_ingredients' => 'nullable|array',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            if ($validated['purchase_status'] === 'success') {
                $order->update(['status' => Order::STATUS_IN_PREPARATION]);
                Log::info('Order moved to preparation after marketplace purchase: ' . $order->id);
            } else {
                $order->update(['status' => Order::STATUS_FAILED]);
                Log::error('Order failed due to marketplace purchase failure: ' . $order->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Marketplace callback processed'
            ]);

        } catch (\Exception $e) {
            Log::error('Marketplace callback failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process marketplace callback'
            ], 500);
        }
    }

    public function orderReady(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);
            $order->update([
                'status' => Order::STATUS_READY,
                'estimated_completion_at' => now()
            ]);

            Log::info('Order marked as ready: ' . $order->id);

            return response()->json([
                'success' => true,
                'message' => 'Order marked as ready'
            ]);

        } catch (\Exception $e) {
            Log::error('Order ready callback failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark order as ready'
            ], 500);
        }
    }

    private function triggerMarketplaceService(Order $order, array $missingIngredients): void
    {
        $marketplaceUrl = env('MARKETPLACE_SERVICE_URL');

        if (!$marketplaceUrl) {
            Log::warning('Marketplace service URL not configured');
            return;
        }

        try {
            \Illuminate\Support\Facades\Http::timeout(30)->post("{$marketplaceUrl}/api/purchase-ingredients", [
                'order_id' => $order->id,
                'missing_ingredients' => $missingIngredients,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to trigger marketplace service: ' . $e->getMessage());
        }
    }
}