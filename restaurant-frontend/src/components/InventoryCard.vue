<template>
  <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
        <span class="text-xl">🏪</span>
        Inventario Bodega
      </h2>
      <span 
        :class="[
          'text-sm font-medium px-2.5 py-0.5 rounded-full',
          store.lowStockItems.length > 5 ? 'bg-red-100 text-red-800' :
          store.lowStockItems.length > 2 ? 'bg-yellow-100 text-yellow-800' :
          'bg-green-100 text-green-800'
        ]"
      >
        {{ store.lowStockItems.length }} bajo stock
      </span>
    </div>
    
    <div class="space-y-3 max-h-64 overflow-y-auto">
      <div v-if="store.inventory.length === 0" class="text-center py-8 text-gray-500">
        <span class="text-4xl mb-2 block">📦</span>
        <p>Cargando inventario...</p>
      </div>
      
      <div 
        v-for="item in sortedInventory" 
        :key="item.ingredient"
        class="flex justify-between items-center p-3 bg-gray-50 rounded-lg"
      >
        <div class="flex items-center gap-3">
          <span class="text-lg">{{ getIngredientEmoji(item.ingredient) }}</span>
          <div>
            <p class="font-medium text-gray-900 capitalize">{{ item.ingredient }}</p>
            <p class="text-sm text-gray-600">{{ item.unit }}</p>
          </div>
        </div>
        
        <div class="text-right">
          <div class="flex items-center gap-2">
            <span 
              :class="[
                'w-2 h-2 rounded-full',
                getStockColor(item.available_quantity)
              ]"
            ></span>
            <span class="font-semibold text-gray-900">{{ item.available_quantity }}</span>
          </div>
          <p class="text-xs text-gray-500" v-if="item.reserved_quantity > 0">
            {{ item.reserved_quantity }} reservados
          </p>
          <p class="text-xs text-gray-400">
            Total: {{ item.total_quantity }}
          </p>
        </div>
      </div>
    </div>
    
    <!-- Resumen rápido -->
    <div class="mt-4 pt-4 border-t border-gray-200">
      <div class="grid grid-cols-3 gap-4 text-center">
        <div>
          <p class="text-2xl font-bold text-green-600">{{ normalStockCount }}</p>
          <p class="text-xs text-gray-500">Normal</p>
        </div>
        <div>
          <p class="text-2xl font-bold text-yellow-600">{{ lowStockCount }}</p>
          <p class="text-xs text-gray-500">Bajo</p>
        </div>
        <div>
          <p class="text-2xl font-bold text-red-600">{{ criticalStockCount }}</p>
          <p class="text-xs text-gray-500">Crítico</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRestaurantStore } from '../stores/restaurant'

const store = useRestaurantStore()

const sortedInventory = computed(() => {
  return [...store.inventory].sort((a, b) => a.available_quantity - b.available_quantity)
})

const normalStockCount = computed(() => 
  store.inventory.filter(item => item.available_quantity >= 5).length
)

const lowStockCount = computed(() => 
  store.inventory.filter(item => item.available_quantity >= 3 && item.available_quantity < 5).length
)

const criticalStockCount = computed(() => 
  store.inventory.filter(item => item.available_quantity < 3).length
)

const getStockColor = (quantity) => {
  if (quantity < 3) return 'bg-red-500'
  if (quantity < 5) return 'bg-yellow-500'
  return 'bg-green-500'
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
    flour: '🌾',
    olive_oil: '🫒',
    croutons: '🍞',
    ketchup: '🍅'
  }
  return emojis[ingredient] || '📦'
}
</script> 