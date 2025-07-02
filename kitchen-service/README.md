# 🍳 Kitchen Service - Servicio de Cocina

## 📋 Descripción General

El Kitchen Service es el microservicio responsable de la gestión de recetas y el procesamiento inicial de las órdenes. Se encarga de seleccionar recetas aleatorias según la cantidad solicitada y calcular los ingredientes necesarios.

## 🏗️ Arquitectura del Servicio

### Controladores (Controllers)

#### 1. `KitchenController`
**Ubicación:** `app/Http/Controllers/KitchenController.php`

**Responsabilidades:**
- Procesar órdenes entrantes del Order Service
- Validar datos de entrada (order_id, quantity)
- Coordinar con KitchenService para el procesamiento

**Endpoints:**
- `POST /api/process-order` - Procesa una orden de cocina
- `POST /api/start-preparation` - Inicia preparación de platos

**Validaciones:**
- `order_id`: Requerido, string
- `quantity`: Requerido, entero entre 1 y 100 (para process-order)
- `selected_recipes`: Array requerido con recetas y tiempos (para start-preparation)

#### 2. `RecipeController`
**Ubicación:** `app/Http/Controllers/RecipeController.php`

**Responsabilidades:**
- Gestionar consultas de recetas disponibles
- Proporcionar información detallada de recetas individuales
- Generar recetas aleatorias para testing

**Endpoints:**
- `GET /api/recipes` - Lista todas las recetas disponibles
- `GET /api/recipes/{id}` - Obtiene una receta específica
- `POST /api/recipes/random` - Genera recetas aleatorias

### Servicios (Services)

#### `KitchenService`
**Ubicación:** `app/Services/KitchenService.php`

**Funcionalidades principales:**

1. **Procesamiento de órdenes** (`processOrder`)
   - Selecciona recetas aleatorias según la cantidad
   - Calcula ingredientes totales necesarios
   - Notifica al Order Service sobre la completitud

2. **Preparación de platos** (`startPreparation`)
   - Calcula tiempo total de preparación basado en recetas
   - Simula proceso de cocina con timeouts realistas
   - Notifica automáticamente cuando los platos están listos
   - Maneja preparación asíncrona para no bloquear el sistema

3. **Cálculo de ingredientes** (`calculateTotalIngredients`)
   - Suma ingredientes de múltiples recetas
   - Multiplica por la cantidad de la orden
   - Retorna array consolidado de ingredientes

4. **Gestión de tiempos** (`calculateTotalPreparationTime`)
   - Determina tiempo máximo de preparación entre recetas
   - Considera preparación en paralelo (tiempo = max tiempo de receta)
   - Proporciona estimaciones realistas de completitud

5. **Simulación de cocina** (`simulatePreparation`, `notifyOrderReady`)
   - Implementa delays basados en tiempos de recetas
   - Para demo: usa tiempos reducidos (segundos en lugar de minutos)
   - Para producción: usaría Laravel Queues/Jobs
   - Envía callback automático cuando platos están listos

6. **Comunicación con servicios** (`notifyOrderService`, `notifyOrderReady`)
   - Envía callbacks al Order Service en diferentes etapas
   - Maneja errores de comunicación
   - Logs detallados de interacciones

7. **Gestión de recetas** (`getAvailableRecipes`, `getRecipeById`)
   - Acceso a catálogo de recetas
   - Búsqueda por ID
   - Validación de existencia

### Modelos (Models)

#### `Recipe`
**Ubicación:** `app/Models/Recipe.php`

**Propiedades:**
- `id`: Identificador único de la receta
- `name`: Nombre descriptivo
- `description`: Descripción detallada
- `ingredients`: Array de ingredientes con cantidades
- `preparation_time`: Tiempo de preparación en minutos
- `is_active`: Estado activo/inactivo

**Métodos importantes:**

1. **`getAvailableRecipes()`**: Retorna catálogo completo de 6 recetas predefinidas
2. **`getRandomRecipe()`**: Selecciona una receta aleatoria
3. **`selectMultipleRandomRecipes(int $quantity)`**: Selecciona múltiples recetas aleatorias

**Recetas Predefinidas:**

1. **Margherita Pizza** (25 min)
   - Ingredientes: tomate (3), queso (3), cebolla (2), harina (4), aceite de oliva (1)

2. **Caesar Salad** (15 min)
   - Ingredientes: lechuga (4), queso (2), cebolla (1), limón (1), crutones (2)

3. **Grilled Chicken** (35 min)
   - Ingredientes: pollo (5), limón (2), cebolla (2), papa (3), aceite de oliva (2)

4. **Classic Burger** (20 min)
   - Ingredientes: carne (4), queso (2), lechuga (2), tomate (2), cebolla (1)

5. **Meat and Rice Bowl** (18 min)
   - Ingredientes: arroz (4), carne (3), queso (2), cebolla (2), tomate (2)

6. **Chicken Rice Bowl** (22 min)
   - Ingredientes: pollo (4), arroz (3), limón (2), lechuga (2), queso (1)

## 📊 Base de Datos

### Tabla: `restaurant-recipes-dev`
**Tipo:** DynamoDB (conceptual, las recetas están hardcodeadas)

**Estructura:**
```php
[
    'id' => 'string',                    // UUID único
    'name' => 'string',                  // Nombre de la receta
    'description' => 'text',             // Descripción detallada
    'ingredients' => 'json',             // Array de ingredientes y cantidades
    'preparation_time' => 'integer',     // Tiempo en minutos
    'is_active' => 'boolean'             // Estado activo
]
```

## 🔄 Flujo de Procesamiento

### Fase 1: Procesamiento Inicial de Orden

1. **Recepción de orden:**
   - Order Service envía POST a `/api/process-order`
   - Se validan order_id y quantity

2. **Selección de recetas:**
   - Se seleccionan N recetas aleatorias (según quantity)
   - Cada selección es independiente (puede repetirse)

3. **Cálculo de ingredientes:**
   - Se suman todos los ingredientes de las recetas seleccionadas
   - Se multiplica por la cantidad de la orden

4. **Notificación inicial:**
   - Se envía callback al Order Service con:
     - order_id
     - selected_recipes (array de recetas)
     - Estado: `processing`

### Fase 2: Preparación de Platos

5. **Inicio de preparación:**
   - Order Service envía POST a `/api/start-preparation` cuando inventario está listo
   - Se reciben las recetas seleccionadas con sus tiempos

6. **Cálculo de tiempo total:**
   - Se determina el tiempo máximo entre todas las recetas
   - Ejemplo: Pizza (25 min) + Ensalada (15 min) = 25 min total (paralelo)

7. **Simulación de cocina:**
   - **Demo**: 10-30 segundos para prueba técnica  
   - **Producción**: Tiempo real de recetas (15-35 minutos)
   - Proceso asíncrono para no bloquear el sistema

8. **Notificación de completitud:**
   - Después del tiempo de preparación, se envía callback automático
   - POST a Order Service `/api/callbacks/order-ready`
   - Estado final: `ready`

## 🌐 Variables de Entorno

```env
# Configuración del Order Service
ORDER_SERVICE_URL=https://order-service-url

# Configuración de base de datos (si se usa DynamoDB real)
AWS_DEFAULT_REGION=us-east-1
DYNAMODB_TABLE=restaurant-recipes-dev
```

## 📝 Logs y Monitoreo

El servicio genera logs detallados para:
- Selección de recetas por orden
- Cálculo de ingredientes totales
- Comunicación con Order Service
- Errores de procesamiento

**Ejemplo de log:**
```
Kitchen: Selected 3 random recipes for order ORD-12345678
Kitchen: Calculated total ingredients for order ORD-12345678
Kitchen: Successfully notified order service for order ORD-12345678
```

## 🧪 Testing

### Endpoints de prueba:
- `GET /api/recipes` - Verificar catálogo de recetas
- `POST /api/recipes/random` - Generar recetas de prueba
- `GET /api/recipes/{id}` - Validar receta específica

### Casos de prueba importantes:
1. **Procesar orden inicial**: Cantidad mínima (1) y máxima (100)
2. **Validar cálculo de ingredientes**: Verificar suma correcta por cantidad
3. **Probar preparación de platos**: Validar tiempos y callback automático
4. **Verificar notificaciones**: Callbacks a Order Service en ambas fases

### Prueba del Flujo Completo:
```http
# 1. Procesar orden inicial
POST /api/process-order
{
  "order_id": "test-order-123",
  "quantity": 2
}

# 2. Simular inicio de preparación
POST /api/start-preparation  
{
  "order_id": "test-order-123",
  "selected_recipes": [
    {"name": "Margherita Pizza", "preparation_time": 25},
    {"name": "Caesar Salad", "preparation_time": 15}
  ]
}
→ Tiempo calculado: 25 min (real)
→ Demo: 25 segundos de simulación
→ Callback automático después del delay
```
