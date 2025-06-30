<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketplaceService
{
    private const API_URL = 'https://recruitment.alegra.com/api/farmers-market/buy';
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SECONDS = 30;

    public function purchaseIngredients(string $orderId, array $missingIngredients): array
    {
        Log::info("Marketplace: Starting purchase for order {$orderId}", [
            'missing_ingredients' => $missingIngredients
        ]);

        $purchaseResults = [
            'success' => true,
            'order_id' => $orderId,
            'purchased' => [],
            'failed' => [],
            'total_cost' => 0
        ];

        foreach ($missingIngredients as $ingredient => $quantity) {
            $result = $this->purchaseSingleIngredient($ingredient, $quantity);

            if ($result['success']) {
                $purchaseResults['purchased'][$ingredient] = $result['quantity_sold'];
                $purchaseResults['total_cost'] += $result['cost'];

                Log::info("Marketplace: Successfully purchased {$ingredient}", [
                    'quantity' => $result['quantity_sold'],
                    'cost' => $result['cost']
                ]);
            } else {
                $purchaseResults['failed'][$ingredient] = $result['error'];
                $purchaseResults['success'] = false;

                Log::error("Marketplace: Failed to purchase {$ingredient}", [
                    'error' => $result['error']
                ]);
            }
        }

        // Update warehouse inventory with purchased ingredients
        if (!empty($purchaseResults['purchased'])) {
            $this->updateWarehouseInventory($purchaseResults['purchased']);
        }

        // Notify order service
        $this->notifyOrderService($orderId, $purchaseResults);

        return $purchaseResults;
    }

    private function purchaseSingleIngredient(string $ingredient, int $quantity): array
    {
        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->retry(2, 1000) // 2 retries with 1 second delay
                    ->post(self::API_URL, [
                        'ingredient' => $ingredient,
                        'quantity' => $quantity
                    ]);

                if ($response->successful()) {
                    $data = $response->json();

                    return [
                        'success' => true,
                        'quantity_sold' => $data['quantitySold'] ?? $quantity,
                        'cost' => $data['cost'] ?? 0,
                        'supplier' => $data['supplier'] ?? 'Unknown'
                    ];
                } else {
                    Log::warning("Marketplace API returned error", [
                        'ingredient' => $ingredient,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                }

            } catch (\Exception $e) {
                Log::error("Marketplace API exception", [
                    'ingredient' => $ingredient,
                    'attempt' => $retries + 1,
                    'error' => $e->getMessage()
                ]);
            }

            $retries++;

            if ($retries < self::MAX_RETRIES) {
                sleep(2 ** $retries); // Exponential backoff
            }
        }

        return [
            'success' => false,
            'error' => "Failed to purchase {$ingredient} after " . self::MAX_RETRIES . " attempts"
        ];
    }

    private function updateWarehouseInventory(array $purchasedIngredients): void
    {
        $warehouseUrl = env('WAREHOUSE_SERVICE_URL');

        if (!$warehouseUrl) {
            Log::warning('Warehouse service URL not configured');
            return;
        }

        try {
            $response = Http::timeout(30)->post("{$warehouseUrl}/api/add-stock", [
                'ingredients' => $purchasedIngredients
            ]);

            if ($response->successful()) {
                Log::info('Marketplace: Successfully updated warehouse inventory', [
                    'purchased' => $purchasedIngredients
                ]);
            } else {
                Log::error('Marketplace: Failed to update warehouse inventory', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Marketplace: Exception updating warehouse inventory: ' . $e->getMessage());
        }
    }

    private function notifyOrderService(string $orderId, array $purchaseResults): void
    {
        $orderServiceUrl = env('ORDER_SERVICE_URL');

        if (!$orderServiceUrl) {
            Log::warning('Order service URL not configured');
            return;
        }

        try {
            $response = Http::timeout(30)->post("{$orderServiceUrl}/api/callbacks/marketplace-completed", [
                'order_id' => $orderId,
                'purchase_status' => $purchaseResults['success'] ? 'success' : 'failed',
                'purchased_ingredients' => $purchaseResults['purchased'],
                'failed_ingredients' => $purchaseResults['failed'],
                'total_cost' => $purchaseResults['total_cost']
            ]);

            if ($response->successful()) {
                Log::info("Marketplace: Successfully notified order service for order {$orderId}");
            } else {
                Log::error("Marketplace: Failed to notify order service", [
                    'order_id' => $orderId,
                    'status' => $response->status()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Marketplace: Exception notifying order service for order {$orderId}: " . $e->getMessage());
        }
    }
}