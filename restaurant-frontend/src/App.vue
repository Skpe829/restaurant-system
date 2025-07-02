<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
          <div class="flex items-center gap-3">
            <span class="text-2xl">🍔</span>
            <h1 class="text-xl font-bold text-gray-900">Restaurant Manager Dashboard</h1>
          </div>
          
          <!-- Botón Principal para Nueva Orden -->
          <button 
            @click="placeOrder"
            :disabled="isPlacingOrder"
            class="btn-primary"
          >
            <span class="text-xl">+</span>
            <span v-if="isPlacingOrder">Procesando...</span>
            <span v-else>Nueva Orden</span>
          </button>
        </div>
      </div>
    </header>

    <!-- Dashboard Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      
      <!-- Status Summary Bar -->
      <div class="mb-8 bg-white rounded-lg shadow-sm p-4 border border-gray-200">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-6">
            <div class="flex items-center gap-2">
              <span class="status-dot status-green"></span>
              <span class="text-sm text-gray-600">Sistema Conectado</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="inventoryStatus"></span>
              <span class="text-sm text-gray-600">Inventario: {{ inventoryStatusText }}</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="ordersStatus"></span>
              <span class="text-sm text-gray-600">{{ activeOrdersCount }} órdenes activas</span>
            </div>
          </div>
          <div class="text-sm text-gray-500">
            Última actualización: {{ lastUpdate }}
          </div>
        </div>
      </div>

      <!-- Dashboard Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        
        <!-- Órdenes en Proceso -->
        <OrdersCard />
        
        <!-- Inventario de Bodega -->
        <InventoryCard />
        
        <!-- Recetas Disponibles -->
        <RecipesCard />
        
        <!-- Compras Marketplace -->
        <PurchasesCard />
        
        <!-- Historial de Pedidos -->
        <HistoryCard />
        
        <!-- Estado del Sistema -->
        <SystemStatusCard />
        
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRestaurantStore } from './stores/restaurant'
import OrdersCard from './components/OrdersCard.vue'
import InventoryCard from './components/InventoryCard.vue'
import RecipesCard from './components/RecipesCard.vue'
import PurchasesCard from './components/PurchasesCard.vue'
import HistoryCard from './components/HistoryCard.vue'
import SystemStatusCard from './components/SystemStatusCard.vue'

const store = useRestaurantStore()
const isPlacingOrder = ref(false)

// Computed properties para el status
const inventoryStatus = computed(() => {
  const lowStock = store.inventory.filter(item => item.available_quantity < 3).length
  if (lowStock > 5) return 'status-red'
  if (lowStock > 2) return 'status-yellow'
  return 'status-green'
})

const inventoryStatusText = computed(() => {
  const lowStock = store.inventory.filter(item => item.available_quantity < 3).length
  if (lowStock > 5) return 'Stock Crítico'
  if (lowStock > 2) return 'Stock Bajo'
  return 'Stock Normal'
})

const ordersStatus = computed(() => {
  const active = store.activeOrders.length
  if (active > 10) return 'status-red'
  if (active > 5) return 'status-yellow'
  return 'status-green'
})

const activeOrdersCount = computed(() => store.activeOrders.length)

const lastUpdate = computed(() => {
  return store.lastUpdate.toLocaleTimeString('es-ES', { 
    hour: '2-digit', 
    minute: '2-digit',
    second: '2-digit'
  })
})

// Función para crear nueva orden
const placeOrder = async () => {
  isPlacingOrder.value = true
  
  try {
    // Simular throttling para alta demanda
    await new Promise(resolve => setTimeout(resolve, 1000))
    
    await store.createOrder({
      quantity: 1,
      customer_name: `Cliente ${Date.now().toString().slice(-4)}`
    })
    
    // Mostrar feedback visual
    showSuccessMessage('¡Orden creada exitosamente!')
    
  } catch (error) {
    console.error('Error al crear orden:', error)
    showErrorMessage('Error al crear la orden. Intenta nuevamente.')
  } finally {
    isPlacingOrder.value = false
  }
}

const showSuccessMessage = (message) => {
  // Implementar toast notification
  console.log('✅', message)
}

const showErrorMessage = (message) => {
  // Implementar toast notification
  console.log('❌', message)
}

// Inicializar datos al montar
onMounted(() => {
  store.initializeDashboard()
  
  // Iniciar auto-refresh cada 5 segundos para ver transiciones en tiempo real
  store.startAutoRefresh()
})
</script> 