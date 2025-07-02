<template>
  <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
        <span class="text-xl">📊</span>
        Historial de Pedidos
      </h2>
      <span class="bg-gray-100 text-gray-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
        Últimos {{ store.orderHistory.length }}
      </span>
    </div>
    
    <div class="space-y-2 max-h-64 overflow-y-auto">
      <div v-if="store.orders.length === 0" class="text-center py-8 text-gray-500">
        <span class="text-4xl mb-2 block">📋</span>
        <p>No hay historial aún</p>
        <p class="text-sm mt-1">Los pedidos aparecerán aquí</p>
      </div>
      
      <div 
        v-for="order in store.orderHistory" 
        :key="order.id"
        class="flex items-center justify-between p-2 bg-gray-50 rounded hover:bg-gray-100 transition-colors cursor-pointer"
        @click="showOrderSummary(order)"
      >
        <div class="flex items-center gap-3">
          <span class="text-lg">{{ getOrderEmoji(order.status) }}</span>
          <div>
            <p class="text-sm font-medium text-gray-900">{{ order.order_number }}</p>
            <p class="text-xs text-gray-600">{{ order.customer_name }}</p>
          </div>
        </div>
        
        <div class="text-right">
          <span 
            :class="[
              'text-xs px-2 py-1 rounded-full font-medium',
              store.getStatusColor(order.status)
            ]"
          >
            {{ store.getStatusText(order.status) }}
          </span>
          <p class="text-xs text-gray-500 mt-1">{{ formatOrderTime(order.created_at) }}</p>
        </div>
      </div>
    </div>
    
    <!-- Estadísticas del día -->
    <div class="mt-4 pt-4 border-t border-gray-200">
      <h3 class="text-sm font-medium text-gray-700 mb-2">Estadísticas del día</h3>
      <div class="grid grid-cols-2 gap-4">
        <div class="text-center">
          <p class="text-lg font-bold text-blue-600">{{ todayStats.total }}</p>
          <p class="text-xs text-gray-500">Total</p>
        </div>
        <div class="text-center">
          <p class="text-lg font-bold text-green-600">{{ todayStats.completed }}</p>
          <p class="text-xs text-gray-500">Completados</p>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4 mt-2">
        <div class="text-center">
          <p class="text-lg font-bold text-orange-600">{{ todayStats.pending }}</p>
          <p class="text-xs text-gray-500">Pendientes</p>
        </div>
        <div class="text-center">
          <p class="text-lg font-bold text-red-600">{{ todayStats.failed }}</p>
          <p class="text-xs text-gray-500">Fallidos</p>
        </div>
      </div>
      
      <!-- Eficiencia -->
      <div class="mt-3 text-center">
        <div class="w-full bg-gray-200 rounded-full h-2">
          <div 
            class="bg-green-600 h-2 rounded-full transition-all duration-500"
            :style="{ width: `${successRate}%` }"
          ></div>
        </div>
        <p class="text-xs text-gray-600 mt-1">
          Eficiencia: {{ successRate.toFixed(1) }}%
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRestaurantStore } from '../stores/restaurant'

const store = useRestaurantStore()

const todayStats = computed(() => {
  const today = new Date().toDateString()
  const todayOrders = store.orders.filter(order => 
    new Date(order.created_at).toDateString() === today
  )
  
  return {
    total: todayOrders.length,
    completed: todayOrders.filter(o => ['delivered', 'ready'].includes(o.status)).length,
    pending: todayOrders.filter(o => ['pending', 'processing', 'in_preparation'].includes(o.status)).length,
    failed: todayOrders.filter(o => o.status.includes('failed')).length
  }
})

const successRate = computed(() => {
  if (todayStats.value.total === 0) return 0
  return (todayStats.value.completed / todayStats.value.total) * 100
})

const formatOrderTime = (timestamp) => {
  const date = new Date(timestamp)
  const today = new Date()
  
  if (date.toDateString() === today.toDateString()) {
    return date.toLocaleTimeString('es-ES', {
      hour: '2-digit',
      minute: '2-digit'
    })
  } else {
    return date.toLocaleDateString('es-ES', {
      day: '2-digit',
      month: '2-digit'
    })
  }
}

const getOrderEmoji = (status) => {
  const emojis = {
    'pending': '⏳',
    'processing': '🔄',
    'in_preparation': '👨‍🍳',
    'ready': '✅',
    'delivered': '🎉',
    'failed': '❌',
    'waiting_marketplace': '🛒',
    'failed_unavailable_ingredients': '🚫'
  }
  return emojis[status] || '📋'
}

const showOrderSummary = (order) => {
  console.log('Mostrando resumen de orden:', order)
  // Implementar modal con resumen detallado
}
</script> 