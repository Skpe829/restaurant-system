<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KitchenService
{
    public function processOrder(string $orderId, int $quantity): array
    {
        try {
            // Step 1: Select random recipes
            $selectedRecipes = Recipe::selectMultipleRandomRecipes($quantity);

            Log::info("Kitchen: Selected {$quantity} random recipes for order {$orderId}", [
                'recipes' => array_column($selectedRecipes, 'name')
            ]);

            // Step 2: Calculate total ingredients needed
            $totalIngredients = $this->calculateTotalIngredients($selectedRecipes, $quantity);

            Log::info("Kitchen: Calculated total ingredients", [
                'order_id' => $orderId,
                'ingredients' => $totalIngredients
            ]);

            // Step 3: Notify Order Service that kitchen processing is complete
            $this->notifyOrderService($orderId, $selectedRecipes);

            return [
                'success' => true,
                'order_id' => $orderId,
                'selected_recipes' => $selectedRecipes,
                'total_ingredients' => $totalIngredients,
                'message' => 'Kitchen processing completed successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Kitchen service error for order {$orderId}: " . $e->getMessage());

            return [
                'success' => false,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ];
        }
    }

    public function startPreparation(string $orderId, array $selectedRecipes): array
    {
        try {
            // Calculate total preparation time
            $totalPreparationTime = $this->calculateTotalPreparationTime($selectedRecipes);

            Log::info("Kitchen: Starting preparation for order {$orderId}", [
                'total_preparation_time' => $totalPreparationTime,
                'recipes' => array_column($selectedRecipes, 'name')
            ]);

            // For serverless demo - mark as ready immediately
            // In production this would use proper queue/job system
            Log::info("Kitchen: Order {$orderId} completed preparation immediately (demo mode)");

            // Notify immediately that order is ready
            $this->notifyOrderReady($orderId);

            $estimatedReadyAt = now();

            return [
                'success' => true,
                'order_id' => $orderId,
                'total_preparation_time' => $totalPreparationTime,
                'estimated_ready_at' => $estimatedReadyAt->toISOString(),
                'message' => "Preparation completed immediately (demo mode)"
            ];

        } catch (\Exception $e) {
            Log::error("Kitchen: Error starting preparation for order {$orderId}: " . $e->getMessage());

            return [
                'success' => false,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ];
        }
    }

    public function calculateTotalIngredients(array $recipes, int $orderQuantity = 1): array
    {
        $totalIngredients = [];

        foreach ($recipes as $recipe) {
            foreach ($recipe['ingredients'] as $ingredient => $amount) {
                $totalIngredients[$ingredient] =
                    ($totalIngredients[$ingredient] ?? 0) + ($amount * $orderQuantity);
            }
        }

        return $totalIngredients;
    }

    private function calculateTotalPreparationTime(array $selectedRecipes): int
    {
        // Find the recipe with the maximum preparation time
        // (assuming recipes can be prepared in parallel, so total time = max time)
        $maxPreparationTime = 0;
        $availableRecipes = Recipe::getAvailableRecipes();

        foreach ($selectedRecipes as $recipe) {
            // If preparation_time is provided, use it
            if (isset($recipe['preparation_time'])) {
                $preparationTime = $recipe['preparation_time'];
            } else {
                // Otherwise, look it up from internal recipes by name
                $preparationTime = 20; // Default
                foreach ($availableRecipes as $availableRecipe) {
                    if ($availableRecipe['name'] === $recipe['name']) {
                        $preparationTime = $availableRecipe['preparation_time'];
                        break;
                    }
                }
            }

            $maxPreparationTime = max($maxPreparationTime, $preparationTime);
        }

        return $maxPreparationTime;
    }

    private function notifyOrderReady(string $orderId): void
    {
        $orderServiceUrl = env('ORDER_SERVICE_URL');

        if (!$orderServiceUrl) {
            Log::warning('Order service URL not configured');
            return;
        }

        try {
            Log::info("Kitchen: Notifying order ready for {$orderId}");

            $response = Http::timeout(30)->post("{$orderServiceUrl}/api/callbacks/order-ready", [
                'order_id' => $orderId,
            ]);

            if ($response->successful()) {
                Log::info("Kitchen: Successfully notified order ready for order {$orderId}");
            } else {
                Log::error("Kitchen: Failed to notify order ready", [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Kitchen: Exception notifying order ready for order {$orderId}: " . $e->getMessage());
        }
    }

    private function notifyOrderService(string $orderId, array $selectedRecipes): void
    {
        $orderServiceUrl = env('ORDER_SERVICE_URL');

        if (!$orderServiceUrl) {
            Log::warning('Order service URL not configured');
            return;
        }

        try {
            $response = Http::timeout(30)->post("{$orderServiceUrl}/api/callbacks/kitchen-completed", [
                'order_id' => $orderId,
                'selected_recipes' => $selectedRecipes,
            ]);

            if ($response->successful()) {
                Log::info("Kitchen: Successfully notified order service for order {$orderId}");
            } else {
                Log::error("Kitchen: Failed to notify order service", [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Kitchen: Exception notifying order service for order {$orderId}: " . $e->getMessage());
        }
    }

    public function getAvailableRecipes(): array
    {
        return Recipe::getAvailableRecipes();
    }

    public function getRecipeById(string $id): ?array
    {
        $recipes = Recipe::getAvailableRecipes();

        foreach ($recipes as $recipe) {
            if ($recipe['id'] === $id) {
                return $recipe;
            }
        }

        return null;
    }
}
