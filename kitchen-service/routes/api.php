<?php

use App\Http\Controllers\KitchenController;
use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    // Kitchen processing endpoints
    Route::post('/process-order', [KitchenController::class, 'processOrder']);

    // Recipe management endpoints
    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::get('/recipes/{id}', [RecipeController::class, 'show']);
    Route::post('/recipes/random', [RecipeController::class, 'getRandomRecipes']);
});

// Health check
Route::get('/', function () {
    return response()->json([
        'service' => 'Restaurant Kitchen Service',
        'status' => 'active',
        'timestamp' => now()->toISOString(),
        'available_recipes' => 6
    ]);
});
