<template>
  <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
        <span class="text-xl">⚡</span>
        Estado del Sistema
      </h2>
      <span 
        :class="[
          'text-sm font-medium px-2.5 py-0.5 rounded-full',
          allServicesUp ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
        ]"
      >
        {{ allServicesUp ? 'Operativo' : 'Problemas' }}
      </span>
    </div>
    
    <div class="space-y-4">
      <!-- Estado de servicios -->
      <div class="space-y-3">
        <div 
          v-for="(status, service) in store.systemStatus" 
          :key="service"
          class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
        >
          <div class="flex items-center gap-3">
            <span class="text-lg">{{ getServiceEmoji(service) }}</span>
            <div>
              <p class="font-medium text-gray-900 capitalize">{{ getServiceName(service) }}</p>
              <p class="text-sm text-gray-600">{{ getServiceDescription(service) }}</p>
            </div>
          </div>
          
          <div class="flex items-center gap-2">
            <span 
              :class="[
                'w-3 h-3 rounded-full',
                status ? 'bg-green-500' : 'bg-red-500'
              ]"
            ></span>
            <span 
              :class="[
                'text-sm font-medium',
                status ? 'text-green-700' : 'text-red-700'
              ]"
            >
              {{ status ? 'Activo' : 'Inactivo' }}
            </span>
          </div>
        </div>
      </div>
      
      <!-- Métricas del sistema -->
      <div class="pt-4 border-t border-gray-200">
        <h3 class="text-sm font-medium text-gray-700 mb-3">Métricas en Tiempo Real</h3>
        <div class="grid grid-cols-2 gap-4">
          <div class="text-center p-3 bg-blue-50 rounded-lg">
            <p class="text-lg font-bold text-blue-600">{{ responseTime }}ms</p>
            <p class="text-xs text-gray-600">Tiempo Respuesta</p>
          </div>
          <div class="text-center p-3 bg-green-50 rounded-lg">
            <p class="text-lg font-bold text-green-600">{{ uptime }}%</p>
            <p class="text-xs text-gray-600">Disponibilidad</p>
          </div>
        </div>
        
        <div class="grid grid-cols-2 gap-4 mt-2">
          <div class="text-center p-3 bg-orange-50 rounded-lg">
            <p class="text-lg font-bold text-orange-600">{{ activeConnections }}</p>
            <p class="text-xs text-gray-600">Conexiones</p>
          </div>
          <div class="text-center p-3 bg-purple-50 rounded-lg">
            <p class="text-lg font-bold text-purple-600">{{ requestsPerMinute }}</p>
            <p class="text-xs text-gray-600">Req/min</p>
          </div>
        </div>
      </div>
      
      <!-- Última actualización -->
      <div class="pt-3 border-t border-gray-200 text-center">
        <p class="text-xs text-gray-500">
          Última actualización: {{ formatLastUpdate(store.lastUpdate) }}
        </p>
        <button 
          @click="refreshSystemStatus"
          class="mt-2 text-blue-600 hover:text-blue-800 text-sm font-medium"
        >
          🔄 Actualizar Estado
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRestaurantStore } from '../stores/restaurant'

const store = useRestaurantStore()

// Métricas simuladas (en una implementación real vendrían de APIs)
const responseTime = ref(250)
const uptime = ref(99.8)
const activeConnections = ref(12)
const requestsPerMinute = ref(45)

const allServicesUp = computed(() => 
  Object.values(store.systemStatus).every(status => status)
)

const getServiceEmoji = (service) => {
  const emojis = {
    orders: '📦',
    kitchen: '👨‍🍳',
    warehouse: '🏪',
    marketplace: '🛒'
  }
  return emojis[service] || '⚙️'
}

const getServiceName = (service) => {
  const names = {
    orders: 'Servicio de Órdenes',
    kitchen: 'Servicio de Cocina',
    warehouse: 'Servicio de Bodega',
    marketplace: 'Marketplace'
  }
  return names[service] || service
}

const getServiceDescription = (service) => {
  const descriptions = {
    orders: 'Gestión de pedidos',
    kitchen: 'Selección de recetas',
    warehouse: 'Control de inventario',
    marketplace: 'Compra de ingredientes'
  }
  return descriptions[service] || 'Servicio del sistema'
}

const formatLastUpdate = (timestamp) => {
  if (!timestamp) return 'Nunca'
  return new Date(timestamp).toLocaleTimeString('es-ES', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  })
}

const refreshSystemStatus = async () => {
  // Simular actualización de métricas
  responseTime.value = Math.floor(Math.random() * 400) + 100
  activeConnections.value = Math.floor(Math.random() * 20) + 5
  requestsPerMinute.value = Math.floor(Math.random() * 60) + 20
  
  // Actualizar datos del store
  await store.refreshData()
}
</script> 