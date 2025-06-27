<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Order extends Model
{
    use HasUuids;

    protected $table = 'restaurant-orders-dev';

    protected $fillable = [
        'order_number',
        'status',
        'quantity',
        'customer_name',
        'selected_recipes',
        'required_ingredients',
        'total_amount',
        'estimated_completion_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'selected_recipes' => 'array',
        'required_ingredients' => 'array',
        'estimated_completion_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Estados posibles de la orden
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IN_PREPARATION = 'in_preparation';
    public const STATUS_READY = 'ready';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(substr($this->id, 0, 8));
    }

    public function calculateIngredients(): array
    {
        $totalIngredients = [];

        if ($this->selected_recipes) {
            foreach ($this->selected_recipes as $recipe) {
                foreach ($recipe['ingredients'] as $ingredient => $quantity) {
                    $totalIngredients[$ingredient] =
                        ($totalIngredients[$ingredient] ?? 0) +
                        ($quantity * $this->quantity);
                }
            }
        }

        return $totalIngredients;
    }
}