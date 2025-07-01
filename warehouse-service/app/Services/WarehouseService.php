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
                    // Ingredients not available in marketplace
                    $this->notifyOrderService($orderId, 'failed_unavailable_ingredients', $purchaseResult['unavailable_ingredients']);
                    Log::warning("Warehouse: Order {$orderId} failed - ingredients not available in marketplace");
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

    // ✅ New intelligent marketplace purchase logic
    private function attemptMarketplacePurchase(string $orderId, array $missingIngredients): array
    {
        // Ingredients available in marketplace (from external API)
        $marketplaceIngredients = [
            'tomato', 'lemon', 'potato', 'rice', 'ketchup',
            'lettuce', 'onion', 'cheese', 'meat', 'chicken'
        ];
        // ❌ NOT available: croutons, flour, olive_oil

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

        // If no ingredients are available in marketplace, fail immediately
        if (empty($availableInMarketplace)) {
            return [
                'status' => 'unavailable',
                'unavailable_ingredients' => $unavailableIngredients,
                'message' => 'No missing ingredients are available in marketplace'
            ];
        }

        // Try to purchase available ingredients even if some are not available
        $purchaseResult = $this->purchaseFromMarketplace($orderId, $availableInMarketplace);

        // If some ingredients are not available in marketplace
        if (!empty($unavailableIngredients)) {
            if ($purchaseResult['status'] === 'success') {
                // Successfully purchased some ingredients, but still missing others
                return [
                    'status' => 'partial',
                    'purchased_ingredients' => $purchaseResult['purchased_ingredients'] ?? [],
                    'remaining_missing' => $unavailableIngredients,
                    'message' => 'Partial purchase successful, some ingredients not available in marketplace'
                ];
            } else {
                // Failed to purchase available ingredients AND have unavailable ones
                return [
                    'status' => 'unavailable',
                    'unavailable_ingredients' => $unavailableIngredients,
                    'failed_purchase' => $purchaseResult,
                    'message' => 'Some ingredients not available in marketplace and purchase failed for others'
                ];
            }
        }

        // All missing ingredients were available in marketplace
        return $purchaseResult;
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
                'failed_unavailable_ingredients' => 'failed_unavailable_ingredients',
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
