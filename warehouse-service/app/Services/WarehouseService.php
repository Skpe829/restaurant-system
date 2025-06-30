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
                // Notify order service about missing ingredients
                $this->notifyOrderService($orderId, 'insufficient', $inventoryStatus['missing']);

                Log::warning("Warehouse: Insufficient inventory for order {$orderId}", [
                    'missing' => $inventoryStatus['missing']
                ]);
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

    private function notifyOrderService(string $orderId, string $status, array $missingIngredients): void
    {
        $orderServiceUrl = env('ORDER_SERVICE_URL');

        if (!$orderServiceUrl) {
            Log::warning('Order service URL not configured');
            return;
        }

        try {
            $response = Http::timeout(30)->post("{$orderServiceUrl}/api/callbacks/warehouse-completed", [
                'order_id' => $orderId,
                'inventory_status' => $status,
                'missing_ingredients' => $missingIngredients,
            ]);

            if ($response->successful()) {
                Log::info("Warehouse: Successfully notified order service for order {$orderId}");
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