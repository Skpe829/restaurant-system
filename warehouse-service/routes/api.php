<?php

use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    // Main warehouse operations
    Route::post('/check-inventory', [WarehouseController::class, 'checkInventory']);
    Route::post('/reserve-ingredients', [WarehouseController::class, 'reserveIngredients']);
    Route::post('/consume-ingredients', [WarehouseController::class, 'consumeIngredients']);
    Route::post('/add-stock', [WarehouseController::class, 'addStock']);

    // Inventory management
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/{ingredient}', [InventoryController::class, 'show']);
    Route::post('/inventory/initialize', [InventoryController::class, 'initialize']);
    
    // ✅ Nuevos endpoints para testing y gestión avanzada
    Route::put('/inventory/{ingredient}/add-stock', [InventoryController::class, 'addStock']);
    Route::put('/inventory/{ingredient}/reserve', [InventoryController::class, 'reserveStock']);
});

Route::get('/', function () {
    return response()->json([
        'service' => 'Restaurant Warehouse Service',
        'status' => 'active',
        'version' => '2.0.0-production',
        'database' => 'DynamoDB',
        'timestamp' => now()->toISOString(),
        'endpoints' => [
            'GET /api/inventory' => 'List all inventory items',
            'GET /api/inventory/{ingredient}' => 'Get specific ingredient details',
            'POST /api/inventory/initialize' => 'Initialize inventory with default stock',
            'PUT /api/inventory/{ingredient}/add-stock' => 'Add stock to specific ingredient',
            'PUT /api/inventory/{ingredient}/reserve' => 'Reserve stock for specific ingredient',
            'POST /api/check-inventory' => 'Check inventory for order (microservice)',
            'POST /api/add-stock' => 'Add stock from marketplace (microservice)'
        ],
        'environment' => [
            'stage' => env('STAGE', 'unknown'),
            'region' => env('REGION', 'unknown'),
            'table' => env('DYNAMODB_TABLE', 'unknown')
        ]
    ]);
});