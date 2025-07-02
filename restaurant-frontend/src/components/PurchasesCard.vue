<template>
  <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
        <span class="text-xl">🛒</span>
        Compras Marketplace
      </h2>
      <span 
        :class="[
          'text-sm font-medium px-2.5 py-0.5 rounded-full',
          store.systemStatus.marketplace ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
        ]"
      >
        {{ store.systemStatus.marketplace ? 'Conectado' : 'Desconectado' }}
      </span>
    </div>
    
    <div class="space-y-3 max-h-64 overflow-y-auto">
      <div v-if="store.purchases.length === 0" class="text-center py-8 text-gray-500">
        <span class="text-4xl mb-2 block">📦</span>
        <p>No hay compras recientes</p>
        <p class="text-sm mt-1">Las compras aparecerán aquí automáticamente</p>
      </div>
      
      <div 
        v-for="purchase in store.recentPurchases" 
        :key="purchase.id"
        class="p-3 bg-gray-50 rounded-lg"
      >
        <div class="flex items-start justify-between mb-2">
          <div class="flex items-center gap-2">
            <span 
              :class="[
                'w-2 h-2 rounded-full',
                purchase.success ? 'bg-green-500' : 'bg-red-500'
              ]"
            ></span>
            <div>
              <p class="text-sm font-medium text-gray-900">
                Orden #{{ purchase.order_id?.slice(-8) || 'N/A' }}
              </p>
              <p class="text-xs text-gray-500">{{ formatPurchaseTime(purchase.timestamp) }}</p>
            </div>
          </div>
          <span 
            :class="[
              'text-xs px-2 py-1 rounded-full font-medium',
              purchase.success ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
            ]"
          >
            {{ purchase.success ? 'Exitosa' : 'Fallida' }}
          </span>
        </div>
        
        <!-- Ingredientes comprados -->
        <div v-if="purchase.purchased && Object.keys(purchase.purchased).length > 0" class="mt-2">
          <div class="flex flex-wrap gap-1">
            <span 
              v-for="(quantity, ingredient) in purchase.purchased" 
              :key="ingredient"
              class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded-full"
            >
              {{ getIngredientEmoji(ingredient) }} {{ ingredient }} ({{ quantity }})
            </span>
          </div>
        </div>
        
        <!-- Ingredientes fallidos -->
        <div v-if="purchase.failed && Object.keys(purchase.failed).length > 0" class="mt-2">
          <div class="flex flex-wrap gap-1">
            <span 
              v-for="(error, ingredient) in purchase.failed" 
              :key="ingredient"
              class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded-full"
            >
              {{ getIngredientEmoji(ingredient) }} {{ ingredient }} ❌
            </span>
          </div>
        </div>
        
        <!-- Costo total -->
        <div v-if="purchase.total_cost" class="mt-2 text-right">
          <span class="text-sm font-medium text-gray-900">
            ${{ purchase.total_cost.toFixed(2) }}
          </span>
        </div>
      </div>
    </div>
    
    <!-- Estadísticas de compras -->
    <div class="mt-4 pt-4 border-t border-gray-200">
      <div class="grid grid-cols-2 gap-4 text-center">
        <div>
          <p class="text-lg font-bold text-green-600">{{ successfulPurchases }}</p>
          <p class="text-xs text-gray-500">Exitosas</p>
        </div>
        <div>
          <p class="text-lg font-bold text-red-600">{{ failedPurchases }}</p>
          <p class="text-xs text-gray-500">Fallidas</p>
        </div>
      </div>
      <div class="mt-2 text-center">
        <p class="text-sm text-gray-600">
          Total gastado: 
          <span class="font-medium text-gray-900">${{ totalSpent.toFixed(2) }}</span>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRestaurantStore } from '../stores/restaurant'

const store = useRestaurantStore()

const successfulPurchases = computed(() => 
  store.purchases.filter(p => p.success).length
)

const failedPurchases = computed(() => 
  store.purchases.filter(p => !p.success).length
)

const totalSpent = computed(() => 
  store.purchases.reduce((sum, p) => sum + (p.total_cost || 0), 0)
)

const formatPurchaseTime = (timestamp) => {
  if (!timestamp) return 'N/A'
  return new Date(timestamp).toLocaleTimeString('es-ES', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const getIngredientEmoji = (ingredient) => {
  const emojis = {
    tomato: '🍅',
    cheese: '🧀',
    onion: '🧅',
    lettuce: '🥬',
    meat: '🥩',
    chicken: '🐔',
    rice: '🍚',
    lemon: '🍋',
    potato: '🥔',
    ketchup: '🍅'
  }
  return emojis[ingredient] || '📦'
}
</script> 