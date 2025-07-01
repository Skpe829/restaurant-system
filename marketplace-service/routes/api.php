<?php

use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('/purchase-ingredients', [MarketplaceController::class, 'purchaseIngredients']);
    Route::get('/purchase-history', [MarketplaceController::class, 'getPurchaseHistory']);

    Route::post('/test-connection', [MarketplaceController::class, 'testApiConnection']);
    Route::get('/health', [MarketplaceController::class, 'getApiStatus']);
    Route::get('/status', [MarketplaceController::class, 'getApiStatus']);
});

Route::get('/', function () {
    return response()->json([
        'service' => 'Restaurant Marketplace Service',
        'status' => 'active',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
        'external_api' => [
            'endpoint' => 'https://recruitment.alegra.com/api/farmers-market/buy',
            'method' => 'GET',
            'parameter' => 'ingredient',
            'valid_ingredients' => [
                'tomato', 'lemon', 'potato', 'rice', 'ketchup',
                'lettuce', 'onion', 'cheese', 'meat', 'chicken'
            ],
            'response_field' => 'quantitySold',
            'success_condition' => 'quantitySold > 0'
        ],
        'endpoints' => [
            'POST /api/purchase-ingredients' => 'Purchase ingredients from farmers market',
            'GET /api/purchase-history' => 'Get purchase history',
            'POST /api/test-connection' => 'Test farmers market API connection',
            'GET /api/health' => 'Check API health status',
            'GET /api/status' => 'Check API health status (alias)'
        ],
        'environment' => [
            'stage' => env('STAGE', 'unknown'),
            'region' => env('REGION', 'unknown')
        ]
    ]);
});