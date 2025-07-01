<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MarketplaceService
{
    private const API_URL = 'https://recruitment.alegra.com/api/farmers-market/buy';
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SECONDS = 30;
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_TIMEOUT = 300;

    private const VALID_INGREDIENTS = [
        'tomato', 'lemon', 'potato', 'rice', 'ketchup',
        'lettuce', 'onion', 'cheese', 'meat', 'chicken'
    ];

    public function purchaseIngredients(string $orderId, array $missingIngredients): array
    {
        Log::info("Marketplace: Starting purchase for order {$orderId}", [
            'missing_ingredients' => $missingIngredients
        ]);

        if ($this->isCircuitBreakerOpen()) {
            Log::warning('Marketplace: Circuit breaker is open, rejecting request');
            return [
                'success' => false,
                'error' => 'Marketplace API temporarily unavailable',
                'order_id' => $orderId
            ];
        }

        $purchaseResults = [
            'success' => true,
            'order_id' => $orderId,
            'purchased' => [],
            'failed' => [],
            'total_cost' => 0,
            'total_requested' => array_sum($missingIngredients),
            'total_obtained' => 0,
            'timestamp' => now()->toISOString()
        ];

        foreach ($missingIngredients as $ingredient => $neededQuantity) {
            if (!in_array($ingredient, self::VALID_INGREDIENTS)) {
                $purchaseResults['failed'][$ingredient] = "Invalid ingredient: {$ingredient}";
                Log::warning("Marketplace: Invalid ingredient {$ingredient}");
                continue;
            }

            $result = $this->purchaseSingleIngredient($ingredient, $neededQuantity);

            if ($result['success'] && ($result['quantity_sold'] ?? 0) > 0) {
                $purchaseResults['purchased'][$ingredient] = $result['quantity_sold'];
                $purchaseResults['total_obtained'] += $result['quantity_sold'];
                $purchaseResults['total_cost'] += $result['cost'] ?? 0;

                Log::info("Marketplace: Successfully purchased {$ingredient}", [
                    'needed' => $neededQuantity,
                    'obtained' => $result['quantity_sold'],
                    'cost' => $result['cost'] ?? 0
                ]);

                $this->resetCircuitBreaker();

            } else {
                $errorMsg = ($result['quantity_sold'] ?? 0) === 0
                    ? "No stock available for {$ingredient}"
                    : ($result['error'] ?? 'Unknown error');

                $purchaseResults['failed'][$ingredient] = $errorMsg;

                if (($result['quantity_sold'] ?? 0) !== 0) {
                    $this->incrementFailureCount();
                }

                Log::warning("Marketplace: Could not purchase {$ingredient}", [
                    'needed' => $neededQuantity,
                    'obtained' => $result['quantity_sold'] ?? 0,
                    'error' => $errorMsg
                ]);
            }
        }

        $purchaseResults['success'] = $purchaseResults['total_obtained'] > 0;

        $this->storePurchaseHistory($purchaseResults);

        if (!empty($purchaseResults['purchased'])) {
            $this->updateWarehouseInventory($purchaseResults['purchased']);
        }

        $this->notifyOrderService($orderId, $purchaseResults);

        return $purchaseResults;
    }

    public function purchaseSingleIngredient(string $ingredient, int $neededQuantity): array
    {
        if ($this->isCircuitBreakerOpen()) {
            return [
                'success' => false,
                'error' => 'Marketplace API temporarily unavailable'
            ];
        }

        if (!in_array($ingredient, self::VALID_INGREDIENTS)) {
            return [
                'success' => false,
                'error' => "Invalid ingredient: {$ingredient}. Valid: " . implode(', ', self::VALID_INGREDIENTS)
            ];
        }

        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            try {
                Log::info("Marketplace: Attempting purchase", [
                    'ingredient' => $ingredient,
                    'needed_quantity' => $neededQuantity,
                    'attempt' => $retries + 1,
                    'api_url' => self::API_URL
                ]);

                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->retry(2, 1000)
                    ->get(self::API_URL, [
                        'ingredient' => $ingredient
                    ]);

                if ($response->successful()) {
                    $data = $response->json();

                    Log::info("Marketplace: API response received", [
                        'ingredient' => $ingredient,
                        'response' => $data
                    ]);

                    $quantitySold = $data['quantitySold'] ?? 0;

                    $result = [
                        'success' => true,
                        'quantity_sold' => $quantitySold,
                        'needed_quantity' => $neededQuantity,
                        'cost' => $this->calculateCost($ingredient, $quantitySold),
                        'supplier' => $data['supplier'] ?? 'Farmers Market',
                        'available_stock' => $quantitySold > 0,
                        'api_response' => $data,
                        'timestamp' => now()->toISOString()
                    ];

                    $this->resetCircuitBreaker();
                    return $result;

                } else {
                    Log::warning("Marketplace API returned error", [
                        'ingredient' => $ingredient,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);

                    $this->incrementFailureCount();
                }

            } catch (\Exception $e) {
                Log::error("Marketplace API exception", [
                    'ingredient' => $ingredient,
                    'attempt' => $retries + 1,
                    'error' => $e->getMessage()
                ]);

                $this->incrementFailureCount();
            }

            $retries++;

            if ($retries < self::MAX_RETRIES) {
                $delay = min(2 ** $retries, 8);
                sleep($delay);
            }
        }

        return [
            'success' => false,
            'quantity_sold' => 0,
            'error' => "Failed to purchase {$ingredient} after " . self::MAX_RETRIES . " attempts"
        ];
    }

    private function calculateCost(string $ingredient, int $quantity): float
    {
        $pricePerUnit = [
            'tomato' => 2.50,
            'lemon' => 1.80,
            'potato' => 1.20,
            'rice' => 3.00,
            'ketchup' => 4.50,
            'lettuce' => 2.20,
            'onion' => 1.50,
            'cheese' => 8.00,
            'meat' => 12.00,
            'chicken' => 10.00
        ];

        return ($pricePerUnit[$ingredient] ?? 2.00) * $quantity;
    }

    public function testSinglePurchase(string $ingredient): array
    {
        Log::info("Marketplace: Testing API with ingredient", ['ingredient' => $ingredient]);

        if (!in_array($ingredient, self::VALID_INGREDIENTS)) {
            return [
                'success' => false,
                'error' => "Invalid ingredient for testing. Valid: " . implode(', ', self::VALID_INGREDIENTS)
            ];
        }

        return $this->purchaseSingleIngredient($ingredient, 1);
    }

    public function getPurchaseHistory(?string $orderId = null, int $limit = 50): array
    {
        $cacheKey = $orderId ? "purchase_history_{$orderId}" : 'purchase_history_all';
        return Cache::get($cacheKey, []);
    }

    public function checkApiHealth(): array
    {
        try {
            $startTime = microtime(true);

            $response = Http::timeout(10)->get(self::API_URL, ['ingredient' => 'tomato']);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $isHealthy = $response->successful();
            $data = $isHealthy ? $response->json() : null;

            return [
                'status' => $isHealthy ? 'healthy' : 'degraded',
                'response_time_ms' => $responseTime,
                'status_code' => $response->status(),
                'test_ingredient' => 'tomato',
                'test_response' => $data,
                'circuit_breaker' => [
                    'open' => $this->isCircuitBreakerOpen(),
                    'failure_count' => $this->getFailureCount()
                ],
                'valid_ingredients' => self::VALID_INGREDIENTS,
                'api_endpoint' => self::API_URL,
                'timestamp' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'circuit_breaker' => [
                    'open' => $this->isCircuitBreakerOpen(),
                    'failure_count' => $this->getFailureCount()
                ],
                'api_endpoint' => self::API_URL,
                'timestamp' => now()->toISOString()
            ];
        }
    }

    private function updateWarehouseInventory(array $purchasedIngredients): void
    {
        $warehouseUrl = env('WAREHOUSE_SERVICE_URL');

        if (!$warehouseUrl) {
            Log::warning('Warehouse service URL not configured');
            return;
        }

        try {
            Log::info('Marketplace: Updating warehouse inventory', [
                'ingredients' => $purchasedIngredients
            ]);

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
            Log::info('Marketplace: Notifying order service', [
                'order_id' => $orderId,
                'success' => $purchaseResults['success'],
                'total_obtained' => $purchaseResults['total_obtained']
            ]);

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
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Marketplace: Exception notifying order service for order {$orderId}: " . $e->getMessage());
        }
    }

    private function storePurchaseHistory(array $purchaseData): void
    {
        $historyKey = "purchase_history_{$purchaseData['order_id']}";
        $allHistoryKey = 'purchase_history_all';

        Cache::put($historyKey, $purchaseData, 3600);

        $allHistory = Cache::get($allHistoryKey, []);
        array_unshift($allHistory, $purchaseData);
        $allHistory = array_slice($allHistory, 0, 100);

        Cache::put($allHistoryKey, $allHistory, 3600);

        Log::info('Marketplace: Purchase history stored', [
            'order_id' => $purchaseData['order_id']
        ]);
    }

    private function isCircuitBreakerOpen(): bool
    {
        $failureCount = $this->getFailureCount();
        $lastFailureTime = Cache::get('marketplace_last_failure_time', 0);

        if ($failureCount >= self::CIRCUIT_BREAKER_THRESHOLD) {
            if (time() - $lastFailureTime < self::CIRCUIT_BREAKER_TIMEOUT) {
                return true;
            } else {
                $this->resetCircuitBreaker();
            }
        }

        return false;
    }

    private function incrementFailureCount(): void
    {
        $currentCount = $this->getFailureCount();
        Cache::put('marketplace_failure_count', $currentCount + 1, 3600);
        Cache::put('marketplace_last_failure_time', time(), 3600);
    }

    private function getFailureCount(): int
    {
        return Cache::get('marketplace_failure_count', 0);
    }

    private function resetCircuitBreaker(): void
    {
        Cache::forget('marketplace_failure_count');
        Cache::forget('marketplace_last_failure_time');
    }
}