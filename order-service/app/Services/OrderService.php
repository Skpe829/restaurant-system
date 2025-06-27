<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function createOrder(array $orderData): Order
    {
        try {
            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'status' => Order::STATUS_PENDING,
                'quantity' => $orderData['quantity'],
                'customer_name' => $orderData['customer_name'] ?? 'Guest',
                'total_amount' => 0, // Se calculará después
            ]);

            // Iniciar el flujo asíncrono
            $this->triggerKitchenService($order);

            return $order;
        } catch (\Exception $e) {
            Log::error('Error creating order: ' . $e->getMessage());
            throw $e;
        }
    }

    public function triggerKitchenService(Order $order): void
    {
        $kitchenUrl = env('KITCHEN_SERVICE_URL');

        if (!$kitchenUrl) {
            Log::warning('Kitchen service URL not configured');
            return;
        }

        try {
            Http::timeout(30)->post("{$kitchenUrl}/api/process-order", [
                'order_id' => $order->id,
                'quantity' => $order->quantity,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to trigger kitchen service: ' . $e->getMessage());
            $order->update(['status' => Order::STATUS_FAILED]);
        }
    }

    public function updateOrderFromKitchen(string $orderId, array $recipes): Order
    {
        $order = Order::findOrFail($orderId);

        $order->update([
            'selected_recipes' => $recipes,
            'status' => Order::STATUS_PROCESSING,
            'required_ingredients' => $this->calculateTotalIngredients($recipes, $order->quantity)
        ]);

        // Trigger warehouse service
        $this->triggerWarehouseService($order);

        return $order;
    }

    private function calculateTotalIngredients(array $recipes, int $quantity): array
    {
        $totalIngredients = [];

        foreach ($recipes as $recipe) {
            foreach ($recipe['ingredients'] as $ingredient => $amount) {
                $totalIngredients[$ingredient] =
                    ($totalIngredients[$ingredient] ?? 0) + ($amount * $quantity);
            }
        }

        return $totalIngredients;
    }

    private function triggerWarehouseService(Order $order): void
    {
        $warehouseUrl = env('WAREHOUSE_SERVICE_URL');

        if (!$warehouseUrl) {
            Log::warning('Warehouse service URL not configured');
            return;
        }

        try {
            Http::timeout(30)->post("{$warehouseUrl}/api/check-inventory", [
                'order_id' => $order->id,
                'required_ingredients' => $order->required_ingredients,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to trigger warehouse service: ' . $e->getMessage());
        }
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(substr(uniqid(), -8));
    }
}