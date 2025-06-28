<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
            $inventory = Inventory::where('ingredient', $ingredient)->first();

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
        return DB::transaction(function () use ($ingredients) {
            foreach ($ingredients as $ingredient => $amount) {
                $inventory = Inventory::where('ingredient', $ingredient)->first();

                if (!$inventory || !$inventory->reserve($amount)) {
                    throw new \Exception("Failed to reserve {$amount} units of {$ingredient}");
                }
            }

            return true;
        });
    }

    public function consumeIngredients(array $ingredients): bool
    {
        return DB::transaction(function () use ($ingredients) {
            foreach ($ingredients as $ingredient => $amount) {
                $inventory = Inventory::where('ingredient', $ingredient)->first();

                if (!$inventory || !$inventory->consume($amount)) {
                    throw new \Exception("Failed to consume {$amount} units of {$ingredient}");
                }
            }

            return true;
        });
    }

    public function addStock(array $ingredients): bool
    {
        return DB::transaction(function () use ($ingredients) {
            foreach ($ingredients as $ingredient => $amount) {
                $inventory = Inventory::where('ingredient', $ingredient)->first();

                if ($inventory) {
                    $inventory->addStock($amount);
                } else {
                    // Create new inventory item
                    Inventory::create([
                        'ingredient' => $ingredient,
                        'quantity' => $amount,
                        'reserved_quantity' => 0,
                        'unit' => 'kg', // Default unit
                        'last_updated' => now()
                    ]);
                }
            }

            return true;
        });
    }

    public function getCurrentInventory(): array
    {
        return Inventory::all()->map(function ($item) {
            return [
                'ingredient' => $item->ingredient,
                'total_quantity' => $item->quantity,
                'available_quantity' => $item->getAvailableQuantity(),
                'reserved_quantity' => $item->reserved_quantity,
                'unit' => $item->unit,
                'last_updated' => $item->last_updated
            ];
        })->toArray();
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
                    'status' => $response->status()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Warehouse: Exception notifying order service for order {$orderId}: " . $e->getMessage());
        }
    }
}