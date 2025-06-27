<?php
// routes/api.php
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

// Rutas principales para el frontend
Route::prefix('api')->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/orders/status/{status}', [OrderController::class, 'getByStatus']);
});

// Rutas para callbacks de otros microservicios
Route::prefix('api/callbacks')->group(function () {
    Route::post('/kitchen-completed', [CallbackController::class, 'kitchenCompleted']);
    Route::post('/warehouse-completed', [CallbackController::class, 'warehouseCompleted']);
    Route::post('/marketplace-completed', [CallbackController::class, 'marketplaceCompleted']);
    Route::post('/order-ready', [CallbackController::class, 'orderReady']);
});

// routes/web.php - Para testing local
Route::get('/', function () {
    return response()->json([
        'service' => 'Restaurant Order Service',
        'status' => 'active',
        'timestamp' => now()->toISOString(),
        'endpoints' => [
            'POST /api/orders' => 'Create new order',
            'GET /api/orders' => 'List all orders',
            'GET /api/orders/{id}' => 'Get specific order',
            'GET /api/orders/status/{status}' => 'Get orders by status'
        ]
    ]);
});
