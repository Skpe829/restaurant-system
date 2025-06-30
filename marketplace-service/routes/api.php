<?php

use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('/purchase-ingredients', [MarketplaceController::class, 'purchaseIngredients']);
    Route::get('/purchase-history', [MarketplaceController::class, 'getPurchaseHistory']);
    Route::post('/test-connection', [MarketplaceController::class, 'testApiConnection']);
});

Route::get('/', function () {
    return response()->json([
        'service' => 'Restaurant Marketplace Service',
        'status' => 'active',
        'timestamp' => now()->toISOString(),
        'api_endpoint' => 'https://recruitment.alegra.com/api/farmers-market/buy'
    ]);
});