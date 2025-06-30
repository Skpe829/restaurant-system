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
            'inventory_status' => 'required|string|in:sufficient,insufficient',
            'missing_ingredients' => 'nullable|array',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            if ($validated['inventory_status'] === 'sufficient') {
                $order->update(['status' => Order::STATUS_IN_PREPARATION]);
                Log::info('Order moved to preparation: ' . $order->id);
            } else {
                $order->update(['status' => 'waiting_marketplace']);
                Log::info('Order waiting for marketplace purchase: ' . $order->id);

                // Intentar comprar ingredientes faltantes
                $purchaseResult = $this->attemptMarketplacePurchase($order, $validated['missing_ingredients']);

                if ($purchaseResult['success']) {
                    $order->update(['status' => Order::STATUS_IN_PREPARATION]);
                    Log::info('Order moved to preparation after successful marketplace purchase: ' . $order->id);
                } else {
                    // Si no hay marketplace service, simular compra exitosa por ahora
                    Log::warning('Marketplace service not available, simulating successful purchase');

                    // Agregar stock faltante al warehouse
                    $this->simulateMarketplacePurchase($validated['missing_ingredients']);

                    $order->update(['status' => Order::STATUS_IN_PREPARATION]);
                    Log::info('Order moved to preparation after simulated marketplace purchase: ' . $order->id);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Warehouse callback processed',
                'order_status' => $order->status
            ]);

        } catch (\Exception $e) {
            Log::error('Warehouse callback failed: ' . $e->getMessage());

            try {
                $order = Order::find($validated['order_id']);
                if ($order && $order->status !== Order::STATUS_FAILED) {
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
}