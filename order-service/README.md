# 📋 Order Service - Servicio de Órdenes

## 📋 Descripción General

El Order Service es el **microservicio central** del sistema de restaurante. Actúa como orquestador principal coordinando el flujo completo desde la creación de una orden hasta su finalización. Gestiona el estado de las órdenes y comunica con Kitchen, Warehouse y Marketplace Services.

## 🏗️ Arquitectura del Servicio

### Controladores (Controllers)

#### 1. `OrderController`
**Ubicación:** `app/Http/Controllers/OrderController.php`

**Responsabilidades:**
- Crear nuevas órdenes de clientes
- Consultar órdenes existentes
- Filtrar órdenes por estado
- Proporcionar API REST para el frontend

**Endpoints:**
- `POST /api/orders` - Crear nueva orden
- `GET /api/orders` - Listar todas las órdenes
- `GET /api/orders/{id}` - Obtener orden específica
- `GET /api/orders/status/{status}` - Filtrar por estado

**Validaciones:**
- `quantity`: Requerido, entero entre 1 y 100
- `customer_name`: Opcional, string máximo 255 caracteres

#### 2. `CallbackController`
**Ubicación:** `app/Http/Controllers/CallbackController.php`

**Responsabilidades:**
- Recibir callbacks de otros microservicios
- Actualizar estados de órdenes según respuestas
- Manejar flujos complejos de coordinación
- Gestionar errores y reintentos

**Endpoints de Callback:**
- `POST /api/callbacks/kitchen-completed` - Kitchen Service completó procesamiento
- `POST /api/callbacks/warehouse-completed` - Warehouse verificó inventario
- `POST /api/callbacks/marketplace-completed` - Marketplace completó compra
- `POST /api/callbacks/order-ready` - Orden lista para entrega

### Servicios (Services)

#### `OrderService`
**Ubicación:** `app/Services/OrderService.php`

**Funcionalidades principales:**

1. **Creación de órdenes** (`createOrder`)
   - Genera número de orden único
   - Establece estado inicial (pending)
   - Dispara flujo asíncrono con Kitchen Service

2. **Orquestación de servicios** (`triggerKitchenService`)
   - Comunica con Kitchen Service para procesamiento inicial
   - Maneja errores de comunicación
   - Actualiza estado en caso de fallas

3. **Procesamiento de callbacks** (`updateOrderFromKitchen`)
   - Actualiza orden con recetas seleccionadas
   - Calcula ingredientes requeridos
   - Dispara Warehouse Service para verificación

4. **Cálculo de ingredientes** (`calculateTotalIngredients`)
   - Consolida ingredientes de múltiples recetas
   - Multiplica por cantidad de la orden

### Modelos (Models)

#### `Order`
**Ubicación:** `app/Models/Order.php`
**Tipo:** Modelo DynamoDB personalizado

**Propiedades:**
- `id`: UUID único de la orden
- `order_number`: Número legible (ORD-XXXXXXXX)
- `status`: Estado actual de la orden
- `quantity`: Cantidad de platos solicitados
- `customer_name`: Nombre del cliente
- `selected_recipes`: Array de recetas seleccionadas (JSON)
- `required_ingredients`: Ingredientes calculados (JSON)
- `total_amount`: Monto total (decimal)
- `estimated_completion_at`: Tiempo estimado de completitud
- `created_at`: Timestamp de creación
- `updated_at`: Timestamp de última actualización

**Estados de Orden:**
```php
// Estados principales del flujo
STATUS_PENDING = 'pending'                          // Recién creada
STATUS_PROCESSING = 'processing'                    // Kitchen procesando
STATUS_IN_PREPARATION = 'in_preparation'            // Inventario suficiente
STATUS_READY = 'ready'                              // Lista para entrega
STATUS_DELIVERED = 'delivered'                      // Entregada

// Estados de error/espera
STATUS_FAILED = 'failed'                            // Error general
STATUS_WAITING_MARKETPLACE = 'waiting_marketplace'  // Esperando compra
STATUS_FAILED_UNAVAILABLE_INGREDIENTS = 'failed_unavailable_ingredients' // Sin ingredientes
```

**Métodos principales:**

1. **Métodos de acceso a datos:**
   - `create(array $attributes)`: Crear nueva orden
   - `find(string $id)`: Buscar por ID
   - `findOrFail(string $id)`: Buscar o lanzar excepción
   - `where(string $attribute, string $value)`: Filtrar órdenes
   - `orderBy(string $column, string $direction)`: Ordenar resultados

2. **Métodos de negocio:**
   - `generateOrderNumber()`: Genera número único
   - `calculateIngredients()`: Calcula ingredientes requeridos
   - `toArray()`: Serializa para respuestas JSON

## 📊 Base de Datos

### Tabla Principal: `restaurant-orders-dev`
**Tipo:** DynamoDB

**Estructura:**
```json
{
  "id": "uuid",                           // Clave primaria
  "order_number": "string",               // Número legible
  "status": "string",                     // Estado actual
  "quantity": "number",                   // Cantidad solicitada
  "customer_name": "string",              // Nombre cliente
  "selected_recipes": "json",             // Recetas seleccionadas
  "required_ingredients": "json",         // Ingredientes calculados
  "total_amount": "number",               // Monto total
  "estimated_completion_at": "timestamp", // Tiempo estimado
  "created_at": "timestamp",              // Creación
  "updated_at": "timestamp"               // Última actualización
}
```

**Índices:**
- **GSI:** `status-index` - Para consultas por estado
- **LSI:** Por `created_at` - Para ordenamiento temporal

### Migración SQL (Referencia)
**Archivo:** `database/migrations/2025_06_30_000000_create_orders_table.php`

```sql
CREATE TABLE orders (
    id UUID PRIMARY KEY,
    order_number VARCHAR UNIQUE,
    status VARCHAR DEFAULT 'pending',
    quantity INTEGER,
    customer_name VARCHAR,
    selected_recipes JSON,
    required_ingredients JSON,
    total_amount DECIMAL(10,2) DEFAULT 0,
    estimated_completion_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);
```

## 🔄 Flujo Completo de Orden

### 1. **Creación (PENDING → PROCESSING)**
```
Cliente → POST /api/orders
        ↓
OrderController::store()
        ↓
OrderService::createOrder()
        ↓
HTTP POST → Kitchen Service
        ↓
Estado: PROCESSING
```

### 2. **Procesamiento de Cocina (PROCESSING → PROCESSING)**
```
Kitchen Service → POST /api/callbacks/kitchen-completed
        ↓
CallbackController::kitchenCompleted()
        ↓
OrderService::updateOrderFromKitchen()
        ↓
HTTP POST → Warehouse Service
        ↓
Recetas y ingredientes actualizados
```

### 3. **Verificación de Inventario**
```
Warehouse Service → POST /api/callbacks/warehouse-completed
        ↓
CallbackController::warehouseCompleted()
        ↓
┌─ Inventario suficiente → STATUS_IN_PREPARATION
├─ Falta inventario → STATUS_WAITING_MARKETPLACE
├─ Sin ingredientes → STATUS_FAILED_UNAVAILABLE_INGREDIENTS
└─ Error → STATUS_FAILED
```

### 4. **Compra en Marketplace (opcional)**
```
Marketplace Service → POST /api/callbacks/marketplace-completed
        ↓
CallbackController::marketplaceCompleted()
        ↓
┌─ Compra exitosa → STATUS_IN_PREPARATION
└─ Compra fallida → STATUS_FAILED
```

### 5. **Finalización**
```
Sistema → POST /api/callbacks/order-ready
        ↓
CallbackController::orderReady()
        ↓
Estado: STATUS_READY
```

## 🌐 Variables de Entorno

```env
# Configuración de servicios
KITCHEN_SERVICE_URL=https://kitchen-service-url
WAREHOUSE_SERVICE_URL=https://warehouse-service-url  
MARKETPLACE_SERVICE_URL=https://marketplace-service-url

# Configuración DynamoDB
AWS_DEFAULT_REGION=us-east-1
DYNAMODB_TABLE=restaurant-orders-dev

# Configuración Laravel
APP_ENV=production
LOG_CHANNEL=stderr
```

## 📝 Logs y Monitoreo

### Eventos principales logged:
- Creación de nuevas órdenes
- Cambios de estado
- Comunicación con servicios externos
- Errores de procesamiento
- Callbacks recibidos

**Ejemplos de logs:**
```
Order created: ORD-12345678 with quantity 3
Kitchen service completed for order: uuid-xxxx
Order uuid-xxxx moved to preparation - all ingredients available
Warehouse callback failed: Connection timeout
```

## 🧪 Testing y API

### Endpoints para testing:
```http
# Crear orden de prueba
POST /api/orders
{
  "quantity": 2,
  "customer_name": "Test Customer"
}

# Consultar estado
GET /api/orders/status/pending
GET /api/orders/status/in_preparation
GET /api/orders/status/failed

# Simular callbacks (para testing)
POST /api/callbacks/kitchen-completed
POST /api/callbacks/warehouse-completed
```

### Casos de prueba críticos:
1. **Flujo exitoso completo** (pending → ready)
2. **Falta de inventario** (→ waiting_marketplace)
3. **Ingredientes no disponibles** (→ failed_unavailable_ingredients)
4. **Errores de comunicación** (→ failed)
5. **Compra exitosa en marketplace** (waiting_marketplace → in_preparation)

## 🔧 Gestión de Errores

### Estrategias implementadas:
- **Timeouts configurables** para llamadas HTTP
- **Logs detallados** de errores y excepciones
- **Estados de error específicos** para diagnosis
- **Callbacks de recuperación** para reintentos
- **Rollback de estados** en caso de fallas

### Manejo de estados fallidos:
- Orders en `failed` pueden re-procesarse manualmente
- Orders en `waiting_marketplace` se procesan automáticamente
- Sistema robusto ante fallos de red temporales
