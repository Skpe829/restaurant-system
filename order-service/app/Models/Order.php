<?php

namespace App\Models;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Order
{
    private static $dynamoDb = null;
    private static $tableName = null;

    // Estados posibles de la orden
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IN_PREPARATION = 'in_preparation';
    public const STATUS_READY = 'ready';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WAITING_MARKETPLACE = 'waiting_marketplace';

    // Propiedades del modelo
    public $id;
    public $order_number;
    public $status;
    public $quantity;
    public $customer_name;
    public $selected_recipes;
    public $required_ingredients;
    public $total_amount;
    public $estimated_completion_at;
    public $created_at;
    public $updated_at;

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
            self::$tableName = env('DYNAMODB_TABLE', 'restaurant-orders-dev');
        }

        return self::$tableName;
    }

    // ✅ Crear nueva orden
    public static function create(array $attributes): self
    {
        $order = new self($attributes);

        // Generar ID único si no existe
        if (!$order->id) {
            $order->id = (string) Str::uuid();
        }

        // Timestamps
        $timestamp = now()->toISOString();
        $order->created_at = $timestamp;
        $order->updated_at = $timestamp;

        // Generar order number
        if (!$order->order_number) {
            $order->order_number = $order->generateOrderNumber();
        }

        // Guardar en DynamoDB
        if ($order->save()) {
            return $order;
        }

        throw new \Exception('Failed to create order in DynamoDB');
    }

    // ✅ Buscar orden por ID
    public static function find(string $id): ?self
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            $response = $dynamoDb->getItem([
                'TableName' => $tableName,
                'Key' => [
                    'id' => ['S' => $id]
                ]
            ]);

            if (!isset($response['Item'])) {
                return null;
            }

            return self::itemToOrder($response['Item']);

        } catch (DynamoDbException $e) {
            Log::error("DynamoDB error finding order {$id}: " . $e->getMessage());
            return null;
        }
    }

    // ✅ Buscar orden por ID (lanza excepción si no existe)
    public static function findOrFail(string $id): self
    {
        $order = self::find($id);

        if (!$order) {
            throw new \Exception("Order not found: {$id}");
        }

        return $order;
    }

    // ✅ Obtener órdenes por estado
    public static function where(string $attribute, string $value): array
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            if ($attribute === 'status') {
                // Usar GSI para consulta por estado
                $response = $dynamoDb->query([
                    'TableName' => $tableName,
                    'IndexName' => 'status-index',
                    'KeyConditionExpression' => '#status = :status',
                    'ExpressionAttributeNames' => [
                        '#status' => 'status'
                    ],
                    'ExpressionAttributeValues' => [
                        ':status' => ['S' => $value]
                    ]
                ]);

                $orders = [];
                foreach ($response['Items'] as $item) {
                    $orders[] = self::itemToOrder($item);
                }

                return $orders;
            }

            // Para otros atributos, usar scan (menos eficiente)
            $response = $dynamoDb->scan([
                'TableName' => $tableName,
                'FilterExpression' => '#attr = :value',
                'ExpressionAttributeNames' => [
                    '#attr' => $attribute
                ],
                'ExpressionAttributeValues' => [
                    ':value' => ['S' => $value]
                ]
            ]);

            $orders = [];
            foreach ($response['Items'] as $item) {
                $orders[] = self::itemToOrder($item);
            }

            return $orders;

        } catch (DynamoDbException $e) {
            Log::error("DynamoDB error querying orders: " . $e->getMessage());
            return [];
        }
    }

    // ✅ Obtener todas las órdenes
    public static function orderBy(string $column, string $direction = 'asc'): array
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            $response = $dynamoDb->scan([
                'TableName' => $tableName
            ]);

            $orders = [];
            foreach ($response['Items'] as $item) {
                $orders[] = self::itemToOrder($item);
            }

            // Ordenar en PHP (DynamoDB no soporta ORDER BY en scan)
            usort($orders, function($a, $b) use ($column, $direction) {
                $aValue = $a->$column ?? '';
                $bValue = $b->$column ?? '';

                if ($direction === 'desc') {
                    return strcmp($bValue, $aValue);
                }

                return strcmp($aValue, $bValue);
            });

            return $orders;

        } catch (DynamoDbException $e) {
            Log::error("DynamoDB error getting orders: " . $e->getMessage());
            return [];
        }
    }

    // ✅ Método auxiliar para obtener todas las órdenes
    public static function get(): array
    {
        return self::orderBy('created_at', 'desc');
    }

    // ✅ Guardar orden en DynamoDB
    public function save(): bool
    {
        try {
            $dynamoDb = self::getDynamoDb();
            $tableName = self::getTableName();

            $this->updated_at = now()->toISOString();

            $item = [
                'id' => ['S' => $this->id],
                'order_number' => ['S' => $this->order_number ?? ''],
                'status' => ['S' => $this->status ?? self::STATUS_PENDING],
                'quantity' => ['N' => (string) ($this->quantity ?? 0)],
                'customer_name' => ['S' => $this->customer_name ?? ''],
                'total_amount' => ['N' => (string) ($this->total_amount ?? 0)],
                'created_at' => ['S' => $this->created_at ?? now()->toISOString()],
                'updated_at' => ['S' => $this->updated_at]
            ];

            // Campos opcionales
            if ($this->selected_recipes) {
                $item['selected_recipes'] = ['S' => json_encode($this->selected_recipes)];
            }

            if ($this->required_ingredients) {
                $item['required_ingredients'] = ['S' => json_encode($this->required_ingredients)];
            }

            if ($this->estimated_completion_at) {
                $item['estimated_completion_at'] = ['S' => $this->estimated_completion_at];
            }

            $response = $dynamoDb->putItem([
                'TableName' => $tableName,
                'Item' => $item
            ]);

            return true;

        } catch (DynamoDbException $e) {
            Log::error("DynamoDB error saving order {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    // ✅ Actualizar orden
    public function update(array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        return $this->save();
    }

    // ✅ Convertir item de DynamoDB a Order
    private static function itemToOrder(array $item): self
    {
        $attributes = [
            'id' => $item['id']['S'] ?? '',
            'order_number' => $item['order_number']['S'] ?? '',
            'status' => $item['status']['S'] ?? '',
            'quantity' => (int) ($item['quantity']['N'] ?? 0),
            'customer_name' => $item['customer_name']['S'] ?? '',
            'total_amount' => (float) ($item['total_amount']['N'] ?? 0),
            'created_at' => $item['created_at']['S'] ?? '',
            'updated_at' => $item['updated_at']['S'] ?? ''
        ];

        // Campos opcionales JSON
        if (isset($item['selected_recipes']['S'])) {
            $attributes['selected_recipes'] = json_decode($item['selected_recipes']['S'], true);
        }

        if (isset($item['required_ingredients']['S'])) {
            $attributes['required_ingredients'] = json_decode($item['required_ingredients']['S'], true);
        }

        if (isset($item['estimated_completion_at']['S'])) {
            $attributes['estimated_completion_at'] = $item['estimated_completion_at']['S'];
        }

        return new self($attributes);
    }

    // ✅ Métodos de negocio
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

    // ✅ Método para convertir a array (para JSON responses)
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'quantity' => $this->quantity,
            'customer_name' => $this->customer_name,
            'selected_recipes' => $this->selected_recipes,
            'required_ingredients' => $this->required_ingredients,
            'total_amount' => $this->total_amount,
            'estimated_completion_at' => $this->estimated_completion_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}