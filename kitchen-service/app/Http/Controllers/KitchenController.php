<?php

namespace App\Http\Controllers;

use App\Services\KitchenService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KitchenController extends Controller
{
    public function __construct(private KitchenService $kitchenService) {}

    public function processOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        $result = $this->kitchenService->processOrder(
            $validated['order_id'],
            $validated['quantity']
        );

        $statusCode = $result['success'] ? 200 : 500;

        return response()->json($result, $statusCode);
    }
}