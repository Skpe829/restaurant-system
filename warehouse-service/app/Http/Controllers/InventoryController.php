<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            Log::info('Fetching all inventory from DynamoDB...');
            
            // Usar el método helper del modelo
            $inventory = Inventory::getAllInventory();
            
            if (empty($inventory)) {
                Log::warning('No inventory found in DynamoDB');
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'total_items' => 0,
                    'message' => 'No inventory found. Consider running /api/inventory/initialize first.'
                ]);
            }
            
            Log::info('Inventory retrieved successfully', ['total_items' => count($inventory)]);
            
            return response()->json([
                'success' => true,
                'data' => $inventory,
                'total_items' => count($inventory),
                'message' => 'Inventory retrieved successfully from DynamoDB'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching inventory from DynamoDB: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch inventory: ' . $e->getMessage(),
                'error_type' => 'database_error'
            ], 500);
        }
    }

    public function show(string $ingredient): JsonResponse
    {
        try {
            Log::info("Fetching ingredient '{$ingredient}' from DynamoDB...");
            
            $inventory = Inventory::findByIngredient($ingredient);
            
            if (!$inventory) {
                Log::warning("Ingredient '{$ingredient}' not found in DynamoDB");
                return response()->json([
                    'success' => false,
                    'message' => 'Ingredient not found in inventory'
                ], 404);
            }
            
            $inventoryData = [
                'ingredient' => $inventory->ingredient,
                'total_quantity' => $inventory->quantity,
                'available_quantity' => $inventory->getAvailableQuantity(),
                'reserved_quantity' => $inventory->reserved_quantity,
                'unit' => $inventory->unit,
                'last_updated' => $inventory->last_updated
            ];
            
            Log::info("Ingredient '{$ingredient}' retrieved successfully from DynamoDB");
            
            return response()->json([
                'success' => true,
                'data' => $inventoryData
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error fetching ingredient '{$ingredient}' from DynamoDB: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ingredient: ' . $e->getMessage(),
                'error_type' => 'database_error'
            ], 500);
        }
    }

    public function initialize(): JsonResponse
    {
        try {
            Log::info('Starting inventory initialization in DynamoDB...');
            
            // Verificar si ya existe inventario
            $existingInventory = Inventory::getAllInventory();
            
            if (!empty($existingInventory)) {
                Log::info('Inventory already exists in DynamoDB', ['count' => count($existingInventory)]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Inventory already initialized in DynamoDB',
                    'data' => $existingInventory,
                    'total_items' => count($existingInventory),
                    'action' => 'retrieved_existing',
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            // Inicializar inventario nuevo
            $results = Inventory::initializeInventory();
            
            Log::info('Inventory initialization completed successfully in DynamoDB', [
                'total_items' => count($results)
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Inventory initialized successfully in DynamoDB',
                'data' => $results,
                'total_items' => count($results),
                'action' => 'initialized_new',
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to initialize inventory in DynamoDB: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize inventory: ' . $e->getMessage(),
                'error_type' => 'database_error',
                'error_details' => $e->getMessage()
            ], 500);
        }
    }

    // ✅ Nuevo endpoint para agregar stock (útil para testing)
    public function addStock(string $ingredient): JsonResponse
    {
        try {
            $inventory = Inventory::findByIngredient($ingredient);
            
            if (!$inventory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ingredient not found in inventory'
                ], 404);
            }
            
            $amountToAdd = request()->input('amount', 5);
            $inventory->addStock($amountToAdd);
            
            return response()->json([
                'success' => true,
                'message' => "Added {$amountToAdd} units to {$ingredient}",
                'data' => [
                    'ingredient' => $inventory->ingredient,
                    'new_quantity' => $inventory->quantity,
                    'available_quantity' => $inventory->getAvailableQuantity(),
                    'amount_added' => $amountToAdd
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to add stock to {$ingredient}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add stock: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ Nuevo endpoint para reservar ingredientes (útil para testing)
    public function reserveStock(string $ingredient): JsonResponse
    {
        try {
            $inventory = Inventory::findByIngredient($ingredient);
            
            if (!$inventory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ingredient not found in inventory'
                ], 404);
            }
            
            $amountToReserve = request()->input('amount', 1);
            $success = $inventory->reserve($amountToReserve);
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock to reserve',
                    'data' => [
                        'ingredient' => $ingredient,
                        'requested' => $amountToReserve,
                        'available' => $inventory->getAvailableQuantity()
                    ]
                ], 400);
            }
            
            return response()->json([
                'success' => true,
                'message' => "Reserved {$amountToReserve} units of {$ingredient}",
                'data' => [
                    'ingredient' => $inventory->ingredient,
                    'reserved_amount' => $amountToReserve,
                    'total_reserved' => $inventory->reserved_quantity,
                    'available_quantity' => $inventory->getAvailableQuantity()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to reserve stock for {$ingredient}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reserve stock: ' . $e->getMessage()
            ], 500);
        }
    }
}