# 🛒 Marketplace Service - Servicio de Marketplace

## 📋 Descripción General

El Marketplace Service es responsable de gestionar la compra de ingredientes desde proveedores externos cuando el inventario interno es insuficiente. Se conecta con APIs externas (Farmers Market) para adquirir ingredientes y actualizar el stock del warehouse automáticamente.

## 🏗️ Arquitectura del Servicio

### Controladores (Controllers)

#### `MarketplaceController`
**Ubicación:** `app/Http/Controllers/MarketplaceController.php`

**Responsabilidades:**
- Procesar solicitudes de compra de ingredientes
- Gestionar historial de compras
- Proporcionar endpoints de testing y monitoreo
- Verificar estado de APIs externas

**Endpoints:**
- `POST /api/purchase-ingredients` - Comprar ingredientes faltantes
- `GET /api/purchase-history` - Consultar historial de compras
- `GET /api/test-connection` - Probar conexión con API externa
- `GET /api/status` - Estado de salud del marketplace

**Validaciones:**
- `order_id`: Requerido, string
- `missing_ingredients`: Array requerido con ingredientes y cantidades
- `limit`: Opcional, entero para limitar resultados de historial

### Servicios (Services)

#### `MarketplaceService`
**Ubicación:** `app/Services/MarketplaceService.php`

**Funcionalidades principales:**

1. **Compra de ingredientes** (`purchaseIngredients`)
   - Procesa compra de múltiples ingredientes para una orden
   - Valida ingredientes disponibles en marketplace externo
   - Gestiona compras parciales y completas
   - Almacena historial de transacciones

2. **Compra individual** (`purchaseSingleIngredient`)
   - Conecta con API externa para ingredientes específicos
   - Implementa sistema de reintentos con exponential backoff
   - Maneja circuit breaker para APIs no disponibles
   - Calcula costos de ingredientes

3. **Gestión de estado** (`checkApiHealth`)
   - Verifica disponibilidad de APIs externas
   - Monitorea tiempos de respuesta
   - Gestiona circuit breaker automático

4. **Comunicación con servicios** (`updateWarehouseInventory`, `notifyOrderService`)
   - Actualiza stock en Warehouse Service
   - Notifica Order Service sobre resultado de compras
   - Maneja errores de comunicación

### API Externa

#### Farmers Market API
**URL:** `https://recruitment.alegra.com/api/farmers-market/buy`
**Método:** GET
**Parámetro:** `ingredient` (string)

**Ingredientes disponibles:**
```php
VALID_INGREDIENTS = [
    'tomato', 'lemon', 'potato', 'rice', 'ketchup',
    'lettuce', 'onion', 'cheese', 'meat', 'chicken'
]
```

**Respuesta ejemplo:**
```json
{
    "quantitySold": 5,
    "supplier": "Farm Fresh Supplies"
}
```

## 🔄 Flujo de Compra de Ingredientes

### 1. **Recepción de solicitud**
```
Warehouse Service → POST /api/purchase-ingredients
{
  "order_id": "uuid",
  "missing_ingredients": {
    "tomato": 3,
    "cheese": 2,
    "chicken": 1
  }
}
```

### 2. **Validación y filtrado**
```
MarketplaceService::purchaseIngredients()
        ↓
Validar ingredientes disponibles en marketplace
        ↓
┌─ Válidos: ['tomato', 'cheese', 'chicken']
└─ Inválidos: ['flour', 'olive_oil'] → Fallar inmediatamente
```

### 3. **Proceso de compra**
```
Por cada ingrediente válido:
        ↓
Llamar API externa con reintentos
        ↓
┌─ Éxito: Almacenar resultado
├─ Sin stock: Continuar con siguiente
└─ Error: Reintentar con backoff
```

### 4. **Consolidación de resultados**
```
Calcular totales:
- total_requested: Suma de cantidades solicitadas
- total_obtained: Suma de cantidades obtenidas
- total_cost: Costo total calculado
        ↓
Generar respuesta con detalles completos
```

### 5. **Actualización de servicios**
```
Parallel execution:
├─ Warehouse Service → Agregar stock comprado
└─ Order Service → Notificar resultado de compra
```

## 💰 Sistema de Precios

### Precios por unidad (estimados):
```php
$pricePerUnit = [
    'tomato' => 2.50,
    'lemon' => 1.80, 
    'potato' => 1.20,
    'rice' => 3.00,
    'ketchup' => 4.50,
    'lettuce' => 2.20,
    'onion' => 1.50,
    'cheese' => 8.00,
    'meat' => 12.00,
    'chicken' => 10.00
];
```

### Cálculo de costos:
- **Costo total** = `sum(precio_unitario * cantidad_obtenida)`
- **Costo por ingrediente** = `precio_unitario * cantidad_comprada`

## 🔧 Circuit Breaker y Resiliencia

### Configuración del Circuit Breaker:
```php
CIRCUIT_BREAKER_THRESHOLD = 5      // Fallos consecutivos para abrir
CIRCUIT_BREAKER_TIMEOUT = 300      // Segundos antes de reintentar (5 min)
MAX_RETRIES = 3                    // Reintentos por ingrediente
TIMEOUT_SECONDS = 30               // Timeout por request HTTP
```

### Estados del Circuit Breaker:
1. **Cerrado** (Normal): Requests procesan normalmente
2. **Abierto** (Fallido): Rechaza requests por timeout configurado
3. **Semi-abierto** (Recuperando): Permite requests de prueba

### Estrategia de reintentos:
- **Exponential backoff**: 2^retry_count segundos (máx 8s)
- **Reintentos por ingrediente**: Hasta 3 intentos independientes
- **Timeout progresivo**: Incrementa con cada reintento

## 📊 Gestión de Historial

### Almacenamiento en Cache:
- **Cache individual**: `purchase_history_{order_id}` (1 hora)
- **Cache global**: `purchase_history_all` (1 hora, máx 100 entradas)

### Estructura del historial:
```json
{
  "success": true,
  "order_id": "uuid",
  "purchased": {
    "tomato": 3,
    "cheese": 2
  },
  "failed": {
    "chicken": "No stock available"
  },
  "total_cost": 23.50,
  "total_requested": 6,
  "total_obtained": 5,
  "timestamp": "2024-12-19T10:30:00Z"
}
```

## 🌐 Variables de Entorno

```env
# APIs externas
FARMERS_MARKET_API_URL=https://recruitment.alegra.com/api/farmers-market/buy

# Configuración de servicios
WAREHOUSE_SERVICE_URL=https://warehouse-service-url
ORDER_SERVICE_URL=https://order-service-url

# Configuración de resiliencia
MARKETPLACE_MAX_RETRIES=3
MARKETPLACE_TIMEOUT=30
CIRCUIT_BREAKER_THRESHOLD=5
CIRCUIT_BREAKER_TIMEOUT=300

# Cache
CACHE_DRIVER=redis
CACHE_PREFIX=marketplace_
```

## 📝 Logs y Monitoreo

### Eventos principales logged:
- Solicitudes de compra recibidas
- Llamadas individuales a API externa
- Resultados de compras (éxito/fallo)
- Estados del circuit breaker
- Comunicación con otros servicios
- Errores y reintentos

**Ejemplos de logs:**
```
Marketplace: Starting purchase for order ORD-12345678
Marketplace: Attempting purchase - tomato, needed: 3, attempt: 1
Marketplace: API response received - tomato, quantitySold: 3
Marketplace: Successfully purchased tomato - needed: 3, obtained: 3, cost: 7.50
Marketplace: Purchase history stored for order ORD-12345678
Marketplace: Successfully updated warehouse inventory
```

## 🧪 Testing y Monitoreo

### Endpoints de testing:
```http
# Probar conexión con API externa
GET /api/test-connection

# Verificar estado de salud
GET /api/status

# Compra de prueba
POST /api/purchase-ingredients
{
  "order_id": "test-order",
  "missing_ingredients": {
    "tomato": 1
  }
}

# Consultar historial
GET /api/purchase-history?order_id=test-order&limit=10
```

### Respuestas de testing:

#### Test de conexión exitoso:
```json
{
  "success": true,
  "message": "API connection successful! Got 3 units of tomato",
  "test_data": {
    "success": true,
    "quantity_sold": 3,
    "supplier": "Farm Fresh Supplies"
  },
  "api_info": {
    "endpoint": "https://recruitment.alegra.com/api/farmers-market/buy",
    "method": "GET",
    "parameter": "ingredient",
    "valid_ingredients": ["tomato", "lemon", "potato", ...]
  }
}
```

#### Estado de salud:
```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "response_time_ms": 245.67,
    "status_code": 200,
    "circuit_breaker": {
      "open": false,
      "failure_count": 0
    },
    "valid_ingredients": ["tomato", "lemon", ...]
  }
}
```

## 🔄 Estados de Respuesta

### Estados de compra individual:
- `success`: Ingrediente comprado exitosamente
- `no_stock`: API respondió con cantidad 0
- `api_error`: Error en comunicación con API
- `invalid_ingredient`: Ingrediente no válido
- `circuit_open`: Circuit breaker abierto

### Estados de compra múltiple:
- `success`: Al menos un ingrediente comprado
- `partial`: Algunos ingredientes comprados, otros fallaron
- `failed`: Ningún ingrediente pudo comprarse

## 📈 Métricas Importantes

### KPIs del servicio:
- **Success Rate**: Porcentaje de compras exitosas
- **Average Response Time**: Tiempo promedio de respuesta de API
- **Circuit Breaker Activations**: Frecuencia de activación del circuit breaker
- **Ingredient Availability**: Disponibilidad por tipo de ingrediente
- **Cost Tracking**: Costos totales por período

### Alertas configurables:
- Circuit breaker abierto por más de 10 minutos
- Success rate menor al 80% en una hora
- Response time mayor a 5 segundos
- Más de 10 fallos consecutivos

## 🔧 Gestión de Errores

### Tipos de errores manejados:
1. **API Externa no disponible** → Circuit breaker + reintentos
2. **Ingrediente no válido** → Respuesta inmediata sin reintentos
3. **Timeout de request** → Reintento con backoff
4. **Sin stock disponible** → Log + continuar con siguiente ingrediente
5. **Error de comunicación interna** → Log + notificación manual

### Estrategias de recuperación:
- **Graceful degradation**: Compra parcial mejor que fallo total
- **Async notifications**: No bloquear compra por fallos de notificación
- **Detailed logging**: Para debugging y análisis post-mortem
- **Health checks**: Endpoints de monitoreo proactivo
