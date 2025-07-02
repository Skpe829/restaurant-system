<template>
  <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
        <span class="text-xl">📋</span>
        Órdenes en Proceso
      </h2>
      <div class="flex items-center gap-2">
        <span v-if="store.ordersReady.length > 0" class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full">
          {{ store.ordersReady.length }} listas
        </span>
        <span class="bg-blue-100 text-blue-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
          {{ store.activeOrders.length }} total
        </span>
      </div>
    </div>
    
    <div class="space-y-3 max-h-64 overflow-y-auto">
      <div v-if="store.activeOrders.length === 0" class="text-center py-8 text-gray-500">
        <span class="text-4xl mb-2 block">🍽️</span>
        <p>No hay órdenes activas</p>
        <p class="text-sm mt-1">¡Presiona "Nueva Orden" para comenzar!</p>
      </div>
      
      <div 
        v-for="order in store.activeOrders.slice(0, 5)" 
        :key="order.id"
        class="flex justify-between items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors cursor-pointer"
        @click="showOrderDetails(order)"
      >
        <div class="flex-1">
          <div class="flex items-center gap-2 mb-1">
            <span class="font-medium text-gray-900">{{ order.order_number }}</span>
            <span 
              :class="['text-xs px-2 py-1 rounded-full font-medium', store.getStatusColor(order.status)]"
            >
              {{ store.getStatusText(order.status) }}
            </span>
          </div>
          <p class="text-sm text-gray-600">{{ order.customer_name }}</p>
          <div v-if="order.selected_recipes && order.selected_recipes.length > 0" class="mt-1">
            <p class="text-xs text-indigo-600 font-medium">Platos:</p>
            <p class="text-xs text-gray-700 truncate">{{ getRecipeNames(order.selected_recipes) }}</p>
          </div>
          <p class="text-xs text-gray-500">{{ formatTime(order.created_at) }}</p>
        </div>
        
        <div class="text-right">
          <p class="text-sm font-medium text-gray-900">{{ order.quantity }} platos</p>
          <div class="flex items-center gap-1 mt-1">
            <span class="w-2 h-2 rounded-full" :class="getStatusDot(order.status)"></span>
            <span class="text-xs text-gray-500">{{ getTimeElapsed(order.created_at) }}</span>
            <span v-if="order.status === 'in_preparation'" class="text-xs text-orange-600 ml-1">
              🔥 Cocinando
            </span>
            <span v-if="order.status === 'ready'" class="text-xs text-green-600 ml-1">
              ✅ Listo
            </span>
            <span v-if="order.status === 'needs_external_purchase'" class="text-xs text-purple-600 ml-1">
              🏪 Otra tienda
            </span>
          </div>
          <!-- Badge de presupuesto para needs_external_purchase -->
          <div v-if="order.status === 'needs_external_purchase'" class="mt-1">
            <span class="bg-purple-100 text-purple-800 text-xs font-medium px-2 py-1 rounded-full">
              💰 ~${{ calculateExternalBudget(order.required_ingredients) }}
            </span>
          </div>
        </div>
      </div>
      
      <div v-if="store.activeOrders.length > 5" class="text-center pt-2">
        <button class="text-blue-600 hover:text-blue-800 text-sm font-medium">
          Ver todas ({{ store.activeOrders.length - 5 }} más)
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRestaurantStore } from '../stores/restaurant'

const store = useRestaurantStore()
const currentTime = ref(new Date())
let timeInterval = null

// Actualizar tiempo cada segundo para mostrar tiempos en tiempo real
onMounted(() => {
  timeInterval = setInterval(() => {
    currentTime.value = new Date()
  }, 1000)
})

onUnmounted(() => {
  if (timeInterval) {
    clearInterval(timeInterval)
  }
})

const formatTime = (timestamp) => {
  return new Date(timestamp).toLocaleTimeString('es-ES', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const getTimeElapsed = (timestamp) => {
  const now = currentTime.value
  const orderTime = new Date(timestamp)
  const diffSeconds = Math.floor((now - orderTime) / 1000)
  
  if (diffSeconds < 30) return 'Ahora mismo'
  if (diffSeconds < 60) return `${diffSeconds}s`
  
  const diffMinutes = Math.floor(diffSeconds / 60)
  if (diffMinutes < 60) return `${diffMinutes}m`
  
  const hours = Math.floor(diffMinutes / 60)
  const minutes = diffMinutes % 60
  return `${hours}h ${minutes}m`
}

const getStatusDot = (status) => {
  const colors = {
    'pending': 'bg-yellow-400',
    'processing': 'bg-blue-400',
    'in_preparation': 'bg-orange-400',
    'ready': 'bg-green-400',
    'waiting_marketplace': 'bg-purple-400',
    'needs_external_purchase': 'bg-purple-500',
    'failed': 'bg-red-400'
  }
  return colors[status] || 'bg-gray-400'
}

const calculateExternalBudget = (requiredIngredients) => {
  if (!requiredIngredients) return '0'
  
  // Precios estimados para ingredientes que típicamente NO están en marketplace
  const externalPrices = {
    'croutons': 4.50,
    'flour': 3.20,
    'olive_oil': 8.50,
    'ketchup': 3.80,
    'sugar': 2.10,
    'salt': 1.50,
    'pepper': 6.20,
    'vinegar': 4.30,
    'butter': 7.80,
    'milk': 3.40
  }
  
  // Ingredientes disponibles en marketplace (precios más bajos)
  const marketplaceIngredients = [
    'tomato', 'lemon', 'potato', 'rice', 'lettuce', 
    'onion', 'cheese', 'meat', 'chicken'
  ]
  
  let totalBudget = 0
  
  Object.entries(requiredIngredients).forEach(([ingredient, quantity]) => {
    // Solo calcular presupuesto para ingredientes NO disponibles en marketplace
    if (!marketplaceIngredients.includes(ingredient)) {
      const price = externalPrices[ingredient] || 5.00 // Precio por defecto
      totalBudget += price * quantity
    }
  })
  
  return totalBudget.toFixed(0)
}

const getRecipeNames = (recipes) => {
  if (!recipes || recipes.length === 0) return ''
  
  const names = recipes.map(recipe => recipe.name).join(', ')
  
  // Truncar si es muy largo
  if (names.length > 50) {
    return names.substring(0, 47) + '...'
  }
  
  return names
}

const showOrderDetails = (order) => {
  console.log('Mostrando detalles de orden:', order)
  // Aquí puedes implementar un modal o vista detallada
}
</script> 