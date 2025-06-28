<?php

namespace App\Models;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Illuminate\Support\Facades\Log;

class Inventory
{
    private static $dynamoDb = null;
    private static $tableName = null;

    // Propiedades del modelo
    public $ingredient;
    public $quantity;
    public $reserved_quantity;
    public $unit;
    public $last_updated;

    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    // ✅ Inicializar cliente DynamoDB
    private static function getDynamoDb(): DynamoDbClient
    {
        if (self::$dynamoDb === null) {
            self::$dynamoDb = new DynamoDbClient([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ]);
        }

        return self::$dynamoDb;
    }

    // ✅ Obtener nombre de tabla
    private static function getTableName(): string
    {
        if (self::$tableName === null) {
            // ✅ Usar el nombre exacto que vimos en el deployment
            self::$tableName = 'restaurant-inventory-dev';
        }

        return self::$tableName;
    }

    // ✅ Inventario inicial optimizado para las recetas del sistema
    // ✅ Inventario inicial que coincide exactamente con las recetas del Kitchen Service
    public static function getInitialInventory(): array
    {
        return [
            // Ingredientes principales de las recetas
            'tomato' => ['quantity' => 15, 'unit' => 'kg'],
            'cheese' => ['quantity' => 12, 'unit' => 'kg'],
            'onion' => ['quantity' => 10, 'unit' => 'kg'],
            'lettuce' => ['quantity' => 8, 'unit' => 'kg'],
            'meat' => ['quantity' => 15, 'unit' => 'kg'],
            'chicken' => ['quantity' => 15, 'unit' => 'kg'],
            'rice' => ['quantity' => 12, 'unit' => 'kg'],
            'lemon' => ['quantity' => 8, 'unit' => 'kg'],
            'potato' => ['quantity' => 10, 'unit' => 'kg'],

            // Ingredientes de cocina
            'flour' => ['quantity' => 10, 'unit' => 'kg'],
            'olive_oil' => ['quantity' => 8, 'unit' => 'liters'],
            'croutons' => ['quantity' => 5, 'unit' => 'kg'],

            // Ingrediente extra de la lista original
            'ketchup' => ['quantity' => 5, 'unit' => 'liters'],
        ];
    }

    // ✅ Inicializar inventario en DynamoDB
    public static function initializeInventory(): array
    {
        $initialInventory = self::getInitialInventory();
        $results = [];
        $dynamoDb = self::getDynamoDb();
        $tableName = self::getTableName();

        try {
            foreach ($initialInventory as $ingredient => $data) {
                // Verificar si el item ya existe
                $existingItem = self::findByIngredient($ingredient);

                if ($existingItem) {
                    $results[] = [
                        'ingredient' => $ingredient,
                        'quantity' => $existingItem->quantity,
                        'unit' => $existingItem->unit,
                        'reserved_quantity' => $existingItem->reserved_quantity,
                        'available_quantity' => $existingItem->getAvailableQuantity(),
                        'status' => 'already_exists',
                        'last_updated' => $existingItem->last_updated
                    ];
                    continue;
                }

                // Crear nuevo item
                $timestamp = now()->toISOString();

                $response = $dynamoDb->putItem([
                    'TableName' => $tableName,
                    'Item' => [
                        'ingredient' => ['S' => $ingredient],
                        'quantity' => ['N' => (string) $data['quantity']],
                        'reserved_quantity' => ['N' => '0'],
                        'unit' => ['S' => $data['unit']],
                        'last_updated' => ['S' => $timestamp]
                    ]
                ]);

                $results[] = [
                    'ingredient' => $ingredient,
                    'quantity' => $data['quantity'],
                    'unit' => $data['unit'],
                    'reserved_quantity' => 0,
                    'available_quantity' => $data['quantity'],
                    'status' => 'initialized',
                    'last_updated' => $timestamp
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

        } catch (DynamoDbException $e) {
            Log::error('DynamoDB error during initialization: ' . $e->getMessage());
            throw new \Exception('Failed to initialize inventory in DynamoDB: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('General error during initialization: ' . $e->getMessage());
            throw $e;
        }
    }

    // ✅ Buscar item por ingrediente
    public static function findByIngredient(string $ingredient): ?self
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            $response = $dynamoDb->getItem([
                'TableName' => $tableName,
                'Key' => [
                    'ingredient' => ['S' => $ingredient]
                ]
            ]);

            if (!isset($response['Item'])) {
                return null;
            }

            $item = $response['Item'];

            return new self([
                'ingredient' => $item['ingredient']['S'] ?? '',
                'quantity' => (int) ($item['quantity']['N'] ?? 0),
                'reserved_quantity' => (int) ($item['reserved_quantity']['N'] ?? 0),
                'unit' => $item['unit']['S'] ?? '',
                'last_updated' => $item['last_updated']['S'] ?? ''
            ]);

        } catch (DynamoDbException $e) {
            Log::error("DynamoDB error finding ingredient {$ingredient}: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            Log::error("General error finding ingredient {$ingredient}: " . $e->getMessage());
            return null;
        }
    }

    // ✅ Obtener todo el inventario
    public static function getAllInventory(): array
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            $response = $dynamoDb->scan([
                'TableName' => $tableName
            ]);

            $inventory = [];

            foreach ($response['Items'] as $item) {
                $inventoryItem = new self([
                    'ingredient' => $item['ingredient']['S'] ?? '',
                    'quantity' => (int) ($item['quantity']['N'] ?? 0),
                    'reserved_quantity' => (int) ($item['reserved_quantity']['N'] ?? 0),
                    'unit' => $item['unit']['S'] ?? '',
                    'last_updated' => $item['last_updated']['S'] ?? ''
                ]);

                $inventory[] = [
                    'ingredient' => $inventoryItem->ingredient,
                    'total_quantity' => $inventoryItem->quantity,
                    'available_quantity' => $inventoryItem->getAvailableQuantity(),
                    'reserved_quantity' => $inventoryItem->reserved_quantity,
                    'unit' => $inventoryItem->unit,
                    'last_updated' => $inventoryItem->last_updated
                ];
            }

            return $inventory;

        } catch (DynamoDbException $e) {
            Log::error('DynamoDB error getting all inventory: ' . $e->getMessage());
            return [];
        } catch (\Exception $e) {
            Log::error('General error getting all inventory: ' . $e->getMessage());
            return [];
        }
    }

    // ✅ Guardar cambios en DynamoDB
    public function save(): bool
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            $this->last_updated = now()->toISOString();

            $response = $dynamoDb->putItem([
                'TableName' => $tableName,
                'Item' => [
                    'ingredient' => ['S' => $this->ingredient],
                    'quantity' => ['N' => (string) $this->quantity],
                    'reserved_quantity' => ['N' => (string) $this->reserved_quantity],
                    'unit' => ['S' => $this->unit],
                    'last_updated' => ['S' => $this->last_updated]
                ]
            ]);

            return true;

        } catch (DynamoDbException $e) {
            Log::error("DynamoDB error saving {$this->ingredient}: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Log::error("General error saving {$this->ingredient}: " . $e->getMessage());
            return false;
        }
    }

    // ✅ Métodos de negocio
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

        if ($this->save()) {
            Log::info("Reserved {$amount} units of {$this->ingredient}", [
                'new_reserved' => $this->reserved_quantity,
                'available' => $this->getAvailableQuantity()
            ]);
            return true;
        }

        return false;
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

        if ($this->save()) {
            Log::info("Consumed {$amount} units of {$this->ingredient}", [
                'new_quantity' => $this->quantity,
                'new_reserved' => $this->reserved_quantity
            ]);
            return true;
        }

        return false;
    }

    public function addStock(int $amount): bool
    {
        $this->quantity += $amount;

        if ($this->save()) {
            Log::info("Added {$amount} units to {$this->ingredient}", [
                'new_quantity' => $this->quantity,
                'available' => $this->getAvailableQuantity()
            ]);
            return true;
        }

        return false;
    }
}