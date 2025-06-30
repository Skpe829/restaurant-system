<?php

namespace App\Http\Controllers;

use App\Services\KitchenService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RecipeController extends Controller
{
    public function __construct(private KitchenService $kitchenService) {}

    public function index(): JsonResponse
    {
        $recipes = $this->kitchenService->getAvailableRecipes();

        return response()->json([
            'success' => true,
            'data' => $recipes,
            'total' => count($recipes)
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $recipe = $this->kitchenService->getRecipeById($id);

        if (!$recipe) {
            return response()->json([
                'success' => false,
                'message' => 'Recipe not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $recipe
        ]);
    }

    public function getRandomRecipes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:20'
        ]);

        $recipes = \App\Models\Recipe::selectMultipleRandomRecipes($validated['quantity']);

        return response()->json([
            'success' => true,
            'data' => $recipes,
            'quantity' => count($recipes)
        ]);
    }
}