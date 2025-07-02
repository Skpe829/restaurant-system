<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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
            'inventory_status' => 'required|string|in:sufficient,insufficient,waiting_marketplace,needs_external_purchase,failed_unavailable_ingredients',
            'order_status' => 'nullable|string|in:in_preparation,waiting_marketplace,needs_external_purchase,failed_unavailable_ingredients,failed',
            'missing_ingredients' => 'nullable|array',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            // Use the order_status provided by warehouse if available, otherwise map from inventory_status
            $newStatus = $validated['order_status'] ?? $this->mapInventoryStatusToOrderStatus($validated['inventory_status']);

            $order->update(['status' => $newStatus]);

            $statusMessages = [
                Order::STATUS_IN_PREPARATION => "Order {$order->id} moved to preparation - all ingredients available",
                Order::STATUS_WAITING_MARKETPLACE => "Order {$order->id} waiting for marketplace purchase",
                Order::STATUS_NEEDS_EXTERNAL_PURCHASE => "Order {$order->id} needs external purchase - some ingredients only available in other stores",
                Order::STATUS_FAILED_UNAVAILABLE_INGREDIENTS => "Order {$order->id} failed - ingredients not available in marketplace",
                Order::STATUS_FAILED => "Order {$order->id} failed due to warehouse error"
            ];

            $message = $statusMessages[$newStatus] ?? "Order {$order->id} status updated to {$newStatus}";
            Log::info($message, [
                'inventory_status' => $validated['inventory_status'],
                'missing_ingredients' => $validated['missing_ingredients'] ?? []
            ]);

            // FIXED: If order moved to preparation, start cooking process IMMEDIATELY
            if ($newStatus === Order::STATUS_IN_PREPARATION) {
                Log::info("CALLBACK: Order {$order->id} moved to IN_PREPARATION - triggering kitchen preparation NOW");
                $this->triggerKitchenPreparation($order);
            } else {
                Log::info("CALLBACK: Order {$order->id} status is {$newStatus} - NO kitchen preparation needed");
            }

            return response()->json([
                'success' => true,
                'message' => 'Warehouse callback processed successfully',
                'order_status' => $order->status,
                'inventory_status' => $validated['inventory_status']
            ]);

        } catch (\Exception $e) {
            Log::error('Warehouse callback failed: ' . $e->getMessage());

            try {
                $order = Order::find($validated['order_id']);
                if ($order && !in_array($order->status, [Order::STATUS_FAILED, Order::STATUS_FAILED_UNAVAILABLE_INGREDIENTS])) {
                    $order->update(['status' => Order::STATUS_FAILED]);
                }
            } catch (\Exception $updateException) {
                Log::error('Failed to update order status to failed: ' . $updateException->getMessage());
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process warehouse callback: ' . $e->getMessage()
            ], 500);
        }
    }

    private function mapInventoryStatusToOrderStatus(string $inventoryStatus): string
    {
        return match($inventoryStatus) {
            'sufficient' => Order::STATUS_IN_PREPARATION,
            'waiting_marketplace' => Order::STATUS_WAITING_MARKETPLACE,
            'needs_external_purchase' => Order::STATUS_NEEDS_EXTERNAL_PURCHASE,
            'failed_unavailable_ingredients' => Order::STATUS_FAILED_UNAVAILABLE_INGREDIENTS,
            'insufficient' => Order::STATUS_WAITING_MARKETPLACE,
            default => Order::STATUS_FAILED
        };
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

                // Start cooking process
                $this->triggerKitchenPreparation($order);
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

    // Método para intentar compra en marketplace real
    private function attemptMarketplacePurchase(Order $order, array $missingIngredients): array
    {
        $marketplaceUrl = env('MARKETPLACE_SERVICE_URL');

        // Si no hay URL configurada o es placeholder, retornar false
        if (!$marketplaceUrl || strpos($marketplaceUrl, 'placeholder') !== false) {
            return ['success' => false, 'reason' => 'No marketplace service configured'];
        }

        try {
            $response = Http::timeout(30)->post("{$marketplaceUrl}/api/purchase-ingredients", [
                'order_id' => $order->id,
                'missing_ingredients' => $missingIngredients,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'reason' => 'HTTP error: ' . $response->status()];

        } catch (\Exception $e) {
            Log::error('Marketplace service call failed: ' . $e->getMessage());
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    private function simulateMarketplacePurchase(array $missingIngredients): void
    {
        $warehouseUrl = env('WAREHOUSE_SERVICE_URL');

        if (!$warehouseUrl) {
            Log::warning('Cannot simulate marketplace purchase: Warehouse URL not configured');
            return;
        }

        try {
            // Agregar stock faltante al warehouse
            $response = Http::timeout(30)->post("{$warehouseUrl}/api/add-stock", [
                'ingredients' => $missingIngredients
            ]);

            if ($response->successful()) {
                Log::info('Successfully added missing ingredients to warehouse', [
                    'ingredients' => $missingIngredients
                ]);
            } else {
                Log::warning('Failed to add stock to warehouse', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to simulate marketplace purchase: ' . $e->getMessage());
        }
    }

    private function triggerKitchenPreparation(Order $order): void
    {
        if (!$order->selected_recipes) {
            Log::error("KITCHEN TRIGGER: Order {$order->id} has no selected recipes, cannot start preparation");
            return;
        }

        $kitchenUrl = env('KITCHEN_SERVICE_URL');

        if (!$kitchenUrl) {
            Log::warning("KITCHEN TRIGGER: Kitchen service URL not configured for order {$order->id}");
            return;
        }

        try {
            Log::info("KITCHEN TRIGGER: Starting preparation for order {$order->id} with Kitchen Service");
            Log::info("KITCHEN TRIGGER: Recipes: " . json_encode($order->selected_recipes));

            // Call Kitchen Service to start preparation
            $response = Http::timeout(30)->post("{$kitchenUrl}/api/start-preparation", [
                'order_id' => $order->id,
                'selected_recipes' => $order->selected_recipes,
            ]);

            if ($response->successful()) {
                Log::info("KITCHEN TRIGGER: Successfully triggered kitchen preparation for order {$order->id}");
            } else {
                Log::error("KITCHEN TRIGGER: Failed to trigger kitchen preparation for order {$order->id}", [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("KITCHEN TRIGGER: EXCEPTION! Failed to trigger kitchen for order {$order->id}: " . $e->getMessage());
        }
    }
}
