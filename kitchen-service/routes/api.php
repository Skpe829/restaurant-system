<?php

use App\Http\Controllers\KitchenController;
use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    // Kitchen processing endpoints
    Route::post('/process-order', [KitchenController::class, 'processOrder']);
    Route::post('/start-preparation', [KitchenController::class, 'startPreparation']);

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
        'endpoints' => [
            'POST /api/process-order' => 'Process order and select recipes',
            'POST /api/start-preparation' => 'Start cooking preparation',
            'GET /api/recipes' => 'List all available recipes',
            'GET /api/recipes/{id}' => 'Get specific recipe',
            'POST /api/recipes/random' => 'Get random recipes'
        ]
    ]);
});
