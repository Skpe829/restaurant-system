<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:100',
            'customer_name' => 'nullable|string|max:255',
        ]);

        try {
            $order = $this->orderService->createOrder($validated);

            return response()->json([
                'success' => true,
                'data' => $order->toArray(),
                'message' => 'Order created successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(): JsonResponse
    {
        try {
            $orders = Order::orderBy('created_at', 'desc');

            // Convertir objetos Order a arrays
            $ordersData = [];
            foreach ($orders as $order) {
                $ordersData[] = $order->toArray();
            }

            return response()->json([
                'success' => true,
                'data' => $ordersData,
                'total' => count($ordersData)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $order->toArray()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    public function getByStatus(string $status): JsonResponse
    {
        $validStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_IN_PREPARATION,
            Order::STATUS_READY,
            Order::STATUS_DELIVERED,
            Order::STATUS_FAILED,
            Order::STATUS_WAITING_MARKETPLACE
        ];

        if (!in_array($status, $validStatuses)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status. Valid statuses: ' . implode(', ', $validStatuses)
            ], 400);
        }

        try {
            $orders = Order::where('status', $status);

            // Convertir objetos Order a arrays y ordenar
            $ordersData = [];
            foreach ($orders as $order) {
                $ordersData[] = $order->toArray();
            }

            // Ordenar por created_at desc
            usort($ordersData, function($a, $b) {
                return strcmp($b['created_at'], $a['created_at']);
            });

            return response()->json([
                'success' => true,
                'data' => $ordersData,
                'status' => $status,
                'total' => count($ordersData)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }
}