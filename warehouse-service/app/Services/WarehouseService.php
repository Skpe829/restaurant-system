<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WarehouseService
{
    public function checkInventory(string $orderId, array $requiredIngredients): array
    {
        try {
            Log::info("Warehouse: Checking inventory for order {$orderId}", [
                'required_ingredients' => $requiredIngredients
            ]);

            $inventoryStatus = $this->analyzeInventoryStatus($requiredIngredients);

            if ($inventoryStatus['sufficient']) {
                // Reserve ingredients
                $this->reserveIngredients($requiredIngredients);

                // Notify order service that inventory is sufficient
                $this->notifyOrderService($orderId, 'sufficient', []);

                Log::info("Warehouse: Inventory sufficient for order {$orderId}");
            } else {
                // Try to purchase missing ingredients from marketplace
                $purchaseResult = $this->attemptMarketplacePurchase($orderId, $inventoryStatus['missing']);

                if ($purchaseResult['status'] === 'success') {
                    // All ingredients purchased successfully, try reservation again
                    $newInventoryStatus = $this->analyzeInventoryStatus($requiredIngredients);

                    if ($newInventoryStatus['sufficient']) {
                        $this->reserveIngredients($requiredIngredients);
                        $this->notifyOrderService($orderId, 'sufficient', []);
                        Log::info("Warehouse: Order {$orderId} fulfilled after marketplace purchase");
                    } else {
                        $this->notifyOrderService($orderId, 'waiting_marketplace', $newInventoryStatus['missing']);
                        Log::info("Warehouse: Order {$orderId} still waiting after partial marketplace purchase");
                    }
                } else if ($purchaseResult['status'] === 'partial') {
                    // Some ingredients purchased, order waiting for more
                    $this->notifyOrderService($orderId, 'waiting_marketplace', $purchaseResult['remaining_missing']);
                    Log::info("Warehouse: Order {$orderId} partially fulfilled, waiting for more ingredients");
                } else if ($purchaseResult['status'] === 'unavailable') {
                    // Ingredients not available in marketplace - need external purchase
                    $this->notifyOrderService($orderId, 'needs_external_purchase', $purchaseResult['unavailable_ingredients']);
                    Log::warning("Warehouse: Order {$orderId} needs external purchase - ingredients not available in marketplace");
                } else {
                    // Purchase failed after retries
                    $this->notifyOrderService($orderId, 'waiting_marketplace', $inventoryStatus['missing']);
                    Log::warning("Warehouse: Order {$orderId} marketplace purchase failed, will retry later");
                }
            }

            return $inventoryStatus;

        } catch (\Exception $e) {
            Log::error("Warehouse: Error checking inventory for order {$orderId}: " . $e->getMessage());

            return [
                'sufficient' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function analyzeInventoryStatus(array $requiredIngredients): array
    {
        $sufficient = true;
        $missing = [];
        $available = [];

        foreach ($requiredIngredients as $ingredient => $requiredAmount) {
            $inventory = Inventory::findByIngredient($ingredient);

            if (!$inventory) {
                $sufficient = false;
                $missing[$ingredient] = $requiredAmount;
                continue;
            }

            $availableAmount = $inventory->getAvailableQuantity();

            if ($availableAmount >= $requiredAmount) {
                $available[$ingredient] = $availableAmount;
            } else {
                $sufficient = false;
                $missing[$ingredient] = $requiredAmount - $availableAmount;
            }
        }

        return [
            'sufficient' => $sufficient,
            'missing' => $missing,
            'available' => $available
        ];
    }

    public function reserveIngredients(array $ingredients): bool
    {
        $reservedIngredients = [];

        try {
            foreach ($ingredients as $ingredient => $amount) {
                $inventory = Inventory::findByIngredient($ingredient);

                if (!$inventory) {
                    throw new \Exception("Ingredient not found: {$ingredient}");
                }

                if (!$inventory->reserve($amount)) {
                    throw new \Exception("Failed to reserve {$amount} units of {$ingredient}");
                }

                $reservedIngredients[] = ['ingredient' => $ingredient, 'amount' => $amount];
            }

            return true;

        } catch (\Exception $e) {
            // Rollback reservations if something failed
            Log::error("Failed to reserve ingredients, attempting rollback: " . $e->getMessage());

            foreach ($reservedIngredients as $reserved) {
                try {
                    $inventory = Inventory::findByIngredient($reserved['ingredient']);
                    if ($inventory) {
                        // Revert reservation by reducing reserved_quantity
                        $inventory->reserved_quantity -= $reserved['amount'];
                        $inventory->save();
                    }
                } catch (\Exception $rollbackException) {
                    Log::error("Rollback failed for {$reserved['ingredient']}: " . $rollbackException->getMessage());
                }
            }

            throw $e;
        }
    }

    public function consumeIngredients(array $ingredients): bool
    {
        foreach ($ingredients as $ingredient => $amount) {
            $inventory = Inventory::findByIngredient($ingredient);

            if (!$inventory || !$inventory->consume($amount)) {
                throw new \Exception("Failed to consume {$amount} units of {$ingredient}");
            }
        }

        return true;
    }

    public function addStock(array $ingredients): bool
    {
        foreach ($ingredients as $ingredient => $amount) {
            $inventory = Inventory::findByIngredient($ingredient);

            if ($inventory) {
                $inventory->addStock($amount);
            } else {
                // Create new inventory item
                $newInventory = new Inventory([
                    'ingredient' => $ingredient,
                    'quantity' => $amount,
                    'reserved_quantity' => 0,
                    'unit' => 'kg', // Default unit
                ]);

                if (!$newInventory->save()) {
                    throw new \Exception("Failed to create new inventory item for {$ingredient}");
                }
            }
        }

        return true;
    }

    public function getCurrentInventory(): array
    {
        return Inventory::getAllInventory();
    }

    public function processWaitingMarketplaceOrders(): array
    {
        $orderServiceUrl = env('ORDER_SERVICE_URL');
        if (!$orderServiceUrl) {
            return ['success' => false, 'message' => 'Order service URL not configured'];
        }

        try {
            Log::info("Warehouse: Starting auto-retry process for waiting marketplace orders");

            // Get all orders in waiting_marketplace status
            $response = Http::timeout(30)->get("{$orderServiceUrl}/api/orders/status/waiting_marketplace");

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch waiting orders'];
            }

            $waitingOrders = $response->json()['data'] ?? [];
            $processedCount = 0;
            $successCount = 0;

            foreach ($waitingOrders as $order) {
                if (!isset($order['id'], $order['required_ingredients']) || empty($order['required_ingredients'])) {
                    continue;
                }

                $processedCount++;
                Log::info("Warehouse: Auto-retrying marketplace purchase for order {$order['id']}");

                // Re-attempt inventory check (which includes marketplace purchase)
                $result = $this->checkInventory($order['id'], $order['required_ingredients']);

                if ($result['sufficient'] ?? false) {
                    $successCount++;
                    Log::info("Warehouse: Auto-retry successful for order {$order['id']}");
                } else {
                    Log::info("Warehouse: Auto-retry still pending for order {$order['id']}: " . json_encode($result));
                }

                // Small delay between orders to avoid overwhelming the marketplace API
                sleep(1);
            }

            $message = "Processed {$processedCount} waiting orders, {$successCount} successful";
            Log::info("Warehouse: Auto-retry process completed: {$message}");

            return [
                'success' => true,
                'message' => $message,
                'processed' => $processedCount,
                'successful' => $successCount,
                'remaining_waiting' => $processedCount - $successCount
            ];

        } catch (\Exception $e) {
            Log::error("Warehouse: Auto-retry process failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Auto-retry process failed: ' . $e->getMessage()
            ];
        }
    }

    private function attemptMarketplacePurchase(string $orderId, array $missingIngredients): array
    {
        // Ingredients available in marketplace (from external API)
        $marketplaceIngredients = [
            'tomato', 'lemon', 'potato', 'rice', 'ketchup',
            'lettuce', 'onion', 'cheese', 'meat', 'chicken'
        ];

        $availableInMarketplace = [];
        $unavailableIngredients = [];

        // Separate ingredients by availability in marketplace
        foreach ($missingIngredients as $ingredient => $quantity) {
            if (in_array($ingredient, $marketplaceIngredients)) {
                $availableInMarketplace[$ingredient] = $quantity;
            } else {
                $unavailableIngredients[$ingredient] = $quantity;
            }
        }

        Log::info("Warehouse: Analyzing missing ingredients for order {$orderId}", [
            'available_in_marketplace' => $availableInMarketplace,
            'unavailable_ingredients' => $unavailableIngredients
        ]);

        if (empty($availableInMarketplace)) {
            return [
                'status' => 'unavailable',
                'unavailable_ingredients' => $unavailableIngredients,
                'message' => 'No missing ingredients are available in marketplace'
            ];
        }

        $maxAttempts = 8;
        $attempt = 1;
        $totalPurchased = [];
        $originalNeeded = $availableInMarketplace; // Track original requirements

        while ($attempt <= $maxAttempts) {
            $stillNeeded = [];
            foreach ($originalNeeded as $ingredient => $originalAmount) {
                $alreadyGot = $totalPurchased[$ingredient] ?? 0;
                $remaining = $originalAmount - $alreadyGot;

                if ($remaining > 0) {
                    $stillNeeded[$ingredient] = $remaining;
                }
            }

            if (empty($stillNeeded)) {
                Log::info("Warehouse: Successfully obtained ALL required ingredients for order {$orderId} in {$attempt} attempts", [
                    'original_needed' => $originalNeeded,
                    'total_purchased' => $totalPurchased
                ]);

                if (empty($unavailableIngredients)) {
                    return [
                        'status' => 'success',
                        'purchased_ingredients' => $totalPurchased,
                        'message' => "All ingredients purchased successfully in {$attempt} attempts"
                    ];
                } else {
                    return [
                        'status' => 'partial',
                        'purchased_ingredients' => $totalPurchased,
                        'remaining_missing' => $unavailableIngredients,
                        'message' => "All marketplace ingredients obtained, but some ingredients not available in marketplace"
                    ];
                }
            }

            Log::info("Warehouse: Purchase attempt {$attempt}/{$maxAttempts} for order {$orderId}", [
                'still_needed' => $stillNeeded,
                'already_purchased' => $totalPurchased
            ]);

            $purchaseResult = $this->purchaseFromMarketplace($orderId, $stillNeeded);

            if ($purchaseResult['status'] === 'success' || $purchaseResult['status'] === 'partial') {
                $purchased = $purchaseResult['purchased_ingredients'] ?? [];

                foreach ($purchased as $ingredient => $quantity) {
                    $totalPurchased[$ingredient] = ($totalPurchased[$ingredient] ?? 0) + $quantity;

                    Log::info("Warehouse: Got {$quantity} units of {$ingredient}, total now: {$totalPurchased[$ingredient]}/{$originalNeeded[$ingredient]}");
                }

                $hasProgress = !empty($purchased);
                if (!$hasProgress) {
                    Log::warning("Warehouse: No progress on attempt {$attempt} for order {$orderId} - marketplace may be empty");

                    // Wait longer if no progress to avoid hammering empty marketplace
                    if ($attempt < $maxAttempts) {
                        sleep(5); // 5 second delay when no progress
                    }
                } else {
                    // Small delay between successful attempts
                    if ($attempt < $maxAttempts) {
                        sleep(1);
                    }
                }
            } else {
                Log::warning("Warehouse: Purchase attempt {$attempt} failed completely for order {$orderId}");

                // Wait before retrying failed attempts
                if ($attempt < $maxAttempts) {
                    sleep(3);
                }
            }

            $attempt++;
        }

        // Final evaluation after all attempts
        $finalStillNeeded = [];
        foreach ($originalNeeded as $ingredient => $originalAmount) {
            $alreadyGot = $totalPurchased[$ingredient] ?? 0;
            $remaining = $originalAmount - $alreadyGot;

            if ($remaining > 0) {
                $finalStillNeeded[$ingredient] = $remaining;
            }
        }

        if (empty($finalStillNeeded)) {
            // Got everything from marketplace
            $allMissing = $unavailableIngredients;

            if (empty($allMissing)) {
                return [
                    'status' => 'success',
                    'purchased_ingredients' => $totalPurchased,
                    'message' => "All ingredients obtained after {$maxAttempts} attempts"
                ];
            } else {
                return [
                    'status' => 'partial',
                    'purchased_ingredients' => $totalPurchased,
                    'remaining_missing' => $allMissing,
                    'message' => "All marketplace ingredients obtained, but some ingredients not available in marketplace"
                ];
            }
        }

        // Still missing some marketplace ingredients after all attempts
        if (!empty($totalPurchased)) {
            $allMissing = array_merge($finalStillNeeded, $unavailableIngredients);

            return [
                'status' => 'partial',
                'purchased_ingredients' => $totalPurchased,
                'remaining_missing' => $allMissing,
                'message' => "Partial success after {$maxAttempts} attempts - still missing: " . implode(', ', array_keys($finalStillNeeded))
            ];
        }

        // No ingredients purchased at all
        return [
            'status' => 'failed',
            'unavailable_ingredients' => array_merge($finalStillNeeded, $unavailableIngredients),
            'message' => "Failed to purchase any ingredients after {$maxAttempts} attempts"
        ];
    }

    private function purchaseFromMarketplace(string $orderId, array $ingredients): array
    {
        $marketplaceUrl = env('MARKETPLACE_SERVICE_URL');

        if (!$marketplaceUrl) {
            Log::warning('Marketplace service URL not configured');
            return [
                'status' => 'failed',
                'message' => 'Marketplace service not configured'
            ];
        }

        $maxRetries = 3;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            try {
                Log::info("Warehouse: Attempting marketplace purchase for order {$orderId}", [
                    'attempt' => $retryCount + 1,
                    'ingredients' => $ingredients
                ]);

                $response = Http::timeout(60)->post("{$marketplaceUrl}/api/purchase-ingredients", [
                    'order_id' => $orderId,
                    'missing_ingredients' => $ingredients,
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    if ($data['success'] ?? false) {
                        $purchaseData = $data['data'] ?? [];
                        $totalObtained = $purchaseData['total_obtained'] ?? 0;
                        $totalRequested = $purchaseData['total_requested'] ?? array_sum($ingredients);

                        Log::info("Warehouse: Marketplace purchase successful for order {$orderId}", [
                            'obtained' => $totalObtained,
                            'requested' => $totalRequested
                        ]);

                        if ($totalObtained >= $totalRequested) {
                            return [
                                'status' => 'success',
                                'purchased_ingredients' => $purchaseData['purchased'] ?? [],
                                'message' => 'All ingredients purchased successfully'
                            ];
                        } else if ($totalObtained > 0) {
                            return [
                                'status' => 'partial',
                                'purchased_ingredients' => $purchaseData['purchased'] ?? [],
                                'remaining_missing' => $this->calculateRemainingMissing($ingredients, $purchaseData['purchased'] ?? []),
                                'message' => 'Partial purchase successful'
                            ];
                        } else {
                            Log::warning("Warehouse: No ingredients obtained from marketplace for order {$orderId}");
                            $retryCount++;
                            if ($retryCount < $maxRetries) {
                                sleep(2 ** $retryCount); // Exponential backoff
                            }
                            continue;
                        }
                    }
                }

                Log::warning("Warehouse: Marketplace purchase failed for order {$orderId}", [
                    'attempt' => $retryCount + 1,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                $retryCount++;
                if ($retryCount < $maxRetries) {
                    sleep(2 ** $retryCount); // Exponential backoff
                }

            } catch (\Exception $e) {
                Log::error("Warehouse: Marketplace purchase exception for order {$orderId}", [
                    'attempt' => $retryCount + 1,
                    'error' => $e->getMessage()
                ]);

                $retryCount++;
                if ($retryCount < $maxRetries) {
                    sleep(2 ** $retryCount);
                }
            }
        }

        return [
            'status' => 'failed',
            'message' => "Failed to purchase ingredients after {$maxRetries} attempts"
        ];
    }

    private function calculateRemainingMissing(array $originalMissing, array $purchased): array
    {
        $remaining = [];

        foreach ($originalMissing as $ingredient => $needed) {
            $obtained = $purchased[$ingredient] ?? 0;
            $stillNeeded = $needed - $obtained;

            if ($stillNeeded > 0) {
                $remaining[$ingredient] = $stillNeeded;
            }
        }

        return $remaining;
    }

    private function notifyOrderService(string $orderId, string $status, array $missingIngredients): void
    {
        $orderServiceUrl = env('ORDER_SERVICE_URL');

        if (!$orderServiceUrl) {
            Log::warning('Order service URL not configured');
            return;
        }

        try {
            $payload = [
                'order_id' => $orderId,
                'inventory_status' => $status,
                'missing_ingredients' => $missingIngredients,
            ];

            // Map warehouse status to order status
            $orderStatus = match($status) {
                'sufficient' => 'in_preparation',
                'waiting_marketplace' => 'waiting_marketplace',
                'needs_external_purchase' => 'needs_external_purchase',
                'failed_unavailable_ingredients' => 'failed_unavailable_ingredients', // Legacy support
                default => 'failed'
            };

            $payload['order_status'] = $orderStatus;

            $response = Http::timeout(30)->post("{$orderServiceUrl}/api/callbacks/warehouse-completed", $payload);

            if ($response->successful()) {
                Log::info("Warehouse: Successfully notified order service for order {$orderId}", [
                    'status' => $status,
                    'order_status' => $orderStatus
                ]);
            } else {
                Log::error("Warehouse: Failed to notify order service", [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Warehouse: Exception notifying order service for order {$orderId}: " . $e->getMessage());
        }
    }
}
