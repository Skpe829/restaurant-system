<?php

namespace App\Models;

use Kitar\Dynamodb\Model\Model;
use Illuminate\Support\Facades\Log;

class Inventory extends Model
{
    protected $connection = 'dynamodb';
    protected $table = 'restaurant-inventory-dev';
    protected $primaryKey = 'ingredient';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'ingredient',
        'quantity',
        'reserved_quantity',
        'unit',
        'last_updated'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'last_updated' => 'datetime'
    ];

    // ✅ Inventario inicial optimizado para las recetas del sistema
    public static function getInitialInventory(): array
    {
        return [
            'tomato' => ['quantity' => 10, 'unit' => 'kg'],
            'cheese' => ['quantity' => 10, 'unit' => 'kg'],
            'onion' => ['quantity' => 10, 'unit' => 'kg'],
            'flour' => ['quantity' => 10, 'unit' => 'kg'],
            'olive_oil' => ['quantity' => 8, 'unit' => 'liters'],
            'lettuce' => ['quantity' => 8, 'unit' => 'kg'],
            'lemon' => ['quantity' => 8, 'unit' => 'kg'],
            'croutons' => ['quantity' => 5, 'unit' => 'kg'],
            'chicken' => ['quantity' => 15, 'unit' => 'kg'],
            'potato' => ['quantity' => 10, 'unit' => 'kg'],
            'meat' => ['quantity' => 15, 'unit' => 'kg'],
            'rice' => ['quantity' => 12, 'unit' => 'kg'],
            'ketchup' => ['quantity' => 5, 'unit' => 'liters'],
            // Ingredientes adicionales comunes
            'mozzarella' => ['quantity' => 8, 'unit' => 'kg'],
            'basil' => ['quantity' => 3, 'unit' => 'kg'],
            'parmesan' => ['quantity' => 5, 'unit' => 'kg'],
            'beef' => ['quantity' => 12, 'unit' => 'kg'],
            'fish' => ['quantity' => 10, 'unit' => 'kg']
        ];
    }

    public static function initializeInventory(): array
    {
        $initialInventory = self::getInitialInventory();
        $results = [];

        try {
            foreach ($initialInventory as $ingredient => $data) {
                // Usar updateOrCreate para DynamoDB
                $inventory = self::updateOrCreate(
                    ['ingredient' => $ingredient],
                    [
                        'quantity' => $data['quantity'],
                        'reserved_quantity' => 0,
                        'unit' => $data['unit'],
                        'last_updated' => now()->toISOString()
                    ]
                );

                $results[] = [
                    'ingredient' => $ingredient,
                    'quantity' => $data['quantity'],
                    'unit' => $data['unit'],
                    'reserved_quantity' => 0,
                    'available_quantity' => $data['quantity'],
                    'status' => 'initialized',
                    'last_updated' => now()->toISOString()
                ];

                Log::info("Initialized inventory for {$ingredient}", [
                    'quantity' => $data['quantity'],
                    'unit' => $data['unit']
                ]);
            }

            Log::info('Inventory initialization completed successfully', [
                'total_items' => count($results)
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Failed to initialize inventory: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getAvailableQuantity(): int
    {
        return $this->quantity - $this->reserved_quantity;
    }

    public function canReserve(int $amount): bool
    {
        return $this->getAvailableQuantity() >= $amount;
    }

    public function reserve(int $amount): bool
    {
        if (!$this->canReserve($amount)) {
            Log::warning("Cannot reserve {$amount} units of {$this->ingredient}", [
                'available' => $this->getAvailableQuantity(),
                'requested' => $amount
            ]);
            return false;
        }

        $this->reserved_quantity += $amount;
        $this->last_updated = now()->toISOString();
        
        try {
            $this->save();
            
            Log::info("Reserved {$amount} units of {$this->ingredient}", [
                'new_reserved' => $this->reserved_quantity,
                'available' => $this->getAvailableQuantity()
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save reservation for {$this->ingredient}: " . $e->getMessage());
            return false;
        }
    }

    public function consume(int $amount): bool
    {
        if ($this->reserved_quantity < $amount) {
            Log::warning("Cannot consume {$amount} units of {$this->ingredient}", [
                'reserved' => $this->reserved_quantity,
                'requested' => $amount
            ]);
            return false;
        }

        $this->quantity -= $amount;
        $this->reserved_quantity -= $amount;
        $this->last_updated = now()->toISOString();

        try {
            $this->save();
            
            Log::info("Consumed {$amount} units of {$this->ingredient}", [
                'new_quantity' => $this->quantity,
                'new_reserved' => $this->reserved_quantity
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save consumption for {$this->ingredient}: " . $e->getMessage());
            return false;
        }
    }

    public function addStock(int $amount): void
    {
        $this->quantity += $amount;
        $this->last_updated = now()->toISOString();

        try {
            $this->save();
            
            Log::info("Added {$amount} units to {$this->ingredient}", [
                'new_quantity' => $this->quantity,
                'available' => $this->getAvailableQuantity()
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to add stock for {$this->ingredient}: " . $e->getMessage());
            throw $e;
        }
    }

    // ✅ Método helper para obtener todos los inventarios
    public static function getAllInventory(): array
    {
        try {
            $items = self::all();
            $inventory = [];

            foreach ($items as $item) {
                $inventory[] = [
                    'ingredient' => $item->ingredient,
                    'total_quantity' => $item->quantity,
                    'available_quantity' => $item->getAvailableQuantity(),
                    'reserved_quantity' => $item->reserved_quantity,
                    'unit' => $item->unit,
                    'last_updated' => $item->last_updated
                ];
            }

            return $inventory;
        } catch (\Exception $e) {
            Log::error('Failed to get all inventory: ' . $e->getMessage());
            return [];
        }
    }

    // ✅ Método helper para buscar por ingrediente
    public static function findByIngredient(string $ingredient)
    {
        try {
            return self::find($ingredient);
        } catch (\Exception $e) {
            Log::error("Failed to find ingredient {$ingredient}: " . $e->getMessage());
            return null;
        }
    }
}