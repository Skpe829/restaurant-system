# 🏪 Warehouse Service - Servicio de Almacén

## 📋 Descripción General

El Warehouse Service es responsable de la gestión completa del inventario del restaurante. Controla el stock de ingredientes, verifica disponibilidad para órdenes, gestiona reservas y consumo, y coordina con el Marketplace Service para la compra de ingredientes faltantes.

## 🏗️ Arquitectura del Servicio

### Controladores (Controllers)

#### 1. `WarehouseController`
**Ubicación:** `app/Http/Controllers/WarehouseController.php`

**Responsabilidades:**
- Verificar inventario para órdenes específicas
- Gestionar reservas de ingredientes
- Procesar consumo de ingredientes
- Agregar stock desde compras de marketplace

**Endpoints:**
- `POST /api/check-inventory` - Verificar inventario para una orden
- `POST /api/reserve-ingredients` - Reservar ingredientes
- `POST /api/consume-ingredients` - Consumir ingredientes reservados
- `POST /api/add-stock` - Agregar stock al inventario

**Validaciones:**
- `order_id`: Requerido para verificación de inventario
- `required_ingredients`: Array con ingredientes y cantidades
- `ingredients`: Array con formato ingrediente → cantidad

#### 2. `InventoryController`
**Ubicación:** `app/Http/Controllers/InventoryController.php`

**Responsabilidades:**
- Consultar estado completo del inventario
- Inicializar inventario con valores por defecto
- Gestionar ingredientes individuales
- Proporcionar endpoints de administración

**Endpoints:**
- `GET /api/inventory` - Obtener todo el inventario
- `GET /api/inventory/{ingredient}` - Consultar ingrediente específico
- `POST /api/inventory/initialize` - Inicializar inventario
- `PUT /api/inventory/{ingredient}/add-stock` - Agregar stock a ingrediente
- `PUT /api/inventory/{ingredient}/reserve` - Reservar stock de ingrediente

### Servicios (Services)

#### `WarehouseService`
**Ubicación:** `app/Services/WarehouseService.php`

**Funcionalidades principales:**

1. **Análisis de inventario** (`checkInventory`, `analyzeInventoryStatus`)
   - Verifica disponibilidad de ingredientes requeridos
   - Identifica ingredientes faltantes
   - Calcula cantidades disponibles vs necesarias

2. **Gestión de reservas** (`reserveIngredients`)
   - Reserva ingredientes para órdenes específicas
   - Implementa rollback automático en caso de fallas
   - Actualiza cantidades reservadas en DynamoDB

3. **Integración con Marketplace** (`attemptMarketplacePurchase`)
   - Identifica ingredientes disponibles en marketplace externo
   - Gestiona compras inteligentes y parciales
   - Maneja reintentos y circuit breaker

4. **Gestión de stock** (`addStock`, `consumeIngredients`)
   - Agrega nuevo stock desde compras
   - Consume ingredientes reservados
   - Mantiene integridad de datos

5. **Comunicación con servicios** (`notifyOrderService`)
   - Envía callbacks al Order Service con estados de inventario
   - Mapea estados de inventario a estados de orden
   - Maneja errores de comunicación

### Modelos (Models)

#### `Inventory`
**Ubicación:** `app/Models/Inventory.php`
**Tipo:** Modelo DynamoDB personalizado

**Propiedades:**
- `ingredient`: Nombre del ingrediente (clave primaria)
- `quantity`: Cantidad total en stock
- `reserved_quantity`: Cantidad reservada para órdenes
- `unit`: Unidad de medida (kg, liters, etc.)
- `last_updated`: Timestamp de última actualización

**Métodos principales:**

1. **Gestión de datos:**
   - `findByIngredient(string $ingredient)`: Buscar por nombre
   - `getAllInventory()`: Obtener inventario completo
   - `initializeInventory()`: Crear inventario inicial
   - `save()`: Guardar cambios en DynamoDB

2. **Lógica de negocio:**
   - `getAvailableQuantity()`: Cantidad disponible (total - reservada)
   - `canReserve(int $amount)`: Verificar si se puede reservar
   - `reserve(int $amount)`: Reservar cantidad específica
   - `consume(int $amount)`: Consumir cantidad reservada
   - `addStock(int $amount)`: Agregar nuevo stock

**Inventario inicial predefinido:**
```php
'tomato' => 15 kg       'cheese' => 12 kg      'onion' => 10 kg
'lettuce' => 8 kg       'meat' => 15 kg        'chicken' => 15 kg
'rice' => 12 kg         'lemon' => 8 kg        'potato' => 10 kg
'flour' => 10 kg        'olive_oil' => 8L      'croutons' => 5 kg
'ketchup' => 5L
```

## 📊 Base de Datos

### Tabla Principal: `restaurant-inventory-dev`
**Tipo:** DynamoDB

**Estructura:**
```json
{
  "ingredient": "string",        // Clave primaria (ej: "tomato")
  "quantity": "number",          // Cantidad total
  "reserved_quantity": "number", // Cantidad reservada
  "unit": "string",             // Unidad (kg, liters)
  "last_updated": "timestamp"   // Última actualización
}
```

**Ejemplo de item:**
```json
{
  "ingredient": "tomato",
  "quantity": 15,
  "reserved_quantity": 3,
  "unit": "kg",
  "last_updated": "2024-12-19T10:30:00Z"
}
```

## 🔄 Flujo de Verificación de Inventario

### 1. **Recepción de solicitud**
```
Order Service → POST /api/check-inventory
{
  "order_id": "uuid",
  "required_ingredients": {
    "tomato": 6,
    "cheese": 4,
    "meat": 8
  }
}
```

### 2. **Análisis de disponibilidad**
```
WarehouseService::analyzeInventoryStatus()
        ↓
Por cada ingrediente:
├─ Buscar en DynamoDB
├─ Calcular disponible = total - reservado
└─ Comparar con requerido
```

### 3. **Escenarios de respuesta**

#### **Escenario A: Inventario Suficiente**
```
✅ Todos los ingredientes disponibles
        ↓
Reserve ingredientes
        ↓
Callback: inventory_status = "sufficient"
        ↓
Order status → "in_preparation"
```

#### **Escenario B: Inventario Insuficiente**
```
❌ Faltan ingredientes
        ↓
Identificar ingredientes faltantes
        ↓
Verificar disponibilidad en marketplace
        ↓
┌─ Disponibles → Comprar en marketplace
├─ No disponibles → Status "failed_unavailable_ingredients"
└─ Error compra → Status "waiting_marketplace"
```

## 🛒 Integración con Marketplace

### Ingredientes disponibles en marketplace externo:
```php
MARKETPLACE_INGREDIENTS = [
    'tomato', 'lemon', 'potato', 'rice', 'ketchup',
    'lettuce', 'onion', 'cheese', 'meat', 'chicken'
]

// ❌ NO disponibles en marketplace:
// 'flour', 'olive_oil', 'croutons'
```

### Lógica de compra inteligente:
1. **Separar ingredientes** por disponibilidad en marketplace
2. **Comprar disponibles** usando Marketplace Service
3. **Fallar ingredientes no disponibles** inmediatamente
4. **Gestionar compras parciales** correctamente
5. **Implementar reintentos** con exponential backoff

## 🌐 Variables de Entorno

```env
# Configuración de servicios
ORDER_SERVICE_URL=https://order-service-url
MARKETPLACE_SERVICE_URL=https://marketplace-service-url

# Configuración DynamoDB
AWS_DEFAULT_REGION=us-east-1
DYNAMODB_TABLE=restaurant-inventory-dev

# Configuración de reintentos
MARKETPLACE_MAX_RETRIES=3
MARKETPLACE_TIMEOUT=60
```

## 📝 Estados y Callbacks

### Estados de inventario enviados al Order Service:
- `sufficient` → Todos los ingredientes disponibles
- `waiting_marketplace` → Esperando compra de ingredientes
- `failed_unavailable_ingredients` → Ingredientes no disponibles
- `failed` → Error general

### Mapeo a estados de orden:
```php
'sufficient' → 'in_preparation'
'waiting_marketplace' → 'waiting_marketplace'  
'failed_unavailable_ingredients' → 'failed_unavailable_ingredients'
'failed' → 'failed'
```

## 📊 Logs y Monitoreo

### Eventos principales logged:
- Verificación de inventario por orden
- Reservas y consumos de ingredientes
- Compras en marketplace
- Actualizaciones de stock
- Errores de comunicación

**Ejemplos de logs:**
```
Warehouse: Checking inventory for order ORD-12345678
Warehouse: Inventory sufficient for order ORD-12345678
Warehouse: Order ORD-12345678 waiting for marketplace purchase
Reserved 5 units of tomato, new_reserved: 8, available: 7
Marketplace purchase successful for order ORD-12345678
```

## 🧪 Testing y Administración

### Endpoints de administración:
```http
# Consultar inventario completo
GET /api/inventory

# Inicializar inventario (solo si está vacío)
POST /api/inventory/initialize

# Agregar stock a ingrediente específico
PUT /api/inventory/tomato/add-stock
{ "amount": 10 }

# Reservar stock para testing
PUT /api/inventory/cheese/reserve  
{ "amount": 3 }
```

### Casos de prueba críticos:
1. **Inventario suficiente** (reserva exitosa)
2. **Inventario insuficiente con marketplace** (compra exitosa)
3. **Ingredientes no disponibles** (fallo controlado)
4. **Compra parcial** (algunos ingredientes comprados)
5. **Rollback de reservas** (en caso de fallas)

## 🔧 Gestión de Errores

### Estrategias de recuperación:
- **Rollback automático** de reservas en caso de falla
- **Reintentos inteligentes** para compras de marketplace
- **Circuit breaker** para marketplace no disponible
- **Estados específicos** para diferentes tipos de falla
- **Logs detallados** para debugging

### Integridad de datos:
- Validación de cantidades disponibles antes de reservar
- Verificación de cantidades reservadas antes de consumir
- Consistencia eventual con DynamoDB
- Manejo de concurrencia en reservas simultáneas
