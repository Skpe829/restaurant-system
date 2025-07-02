<template>
  <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
        <span class="text-xl">📜</span>
        Recetas Disponibles
      </h2>
      <span class="bg-green-100 text-green-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
        {{ store.recipes.length }}
      </span>
    </div>
    
    <div class="space-y-3 max-h-64 overflow-y-auto">
      <div v-if="store.recipes.length === 0" class="text-center py-8 text-gray-500">
        <span class="text-4xl mb-2 block">👨‍🍳</span>
        <p>Cargando recetas...</p>
      </div>
      
      <div 
        v-for="recipe in store.recipes" 
        :key="recipe.id"
        class="p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors cursor-pointer"
        @click="showRecipeDetails(recipe)"
      >
        <div class="flex items-start justify-between mb-2">
          <div class="flex items-center gap-2">
            <span class="text-lg">{{ getRecipeEmoji(recipe.name) }}</span>
            <div>
              <h3 class="font-medium text-gray-900">{{ recipe.name }}</h3>
              <p class="text-sm text-gray-600">{{ recipe.description }}</p>
            </div>
          </div>
          <div class="text-right">
            <span class="text-xs text-gray-500 bg-white px-2 py-1 rounded block">
              {{ store.formatDemoTime(recipe.preparation_time) }}
            </span>
            <span class="text-xs text-gray-400 mt-1 block">
              Real: {{ recipe.preparation_time }}min
            </span>
          </div>
        </div>
        
        <!-- Ingredientes -->
        <div class="mt-2">
          <div class="flex flex-wrap gap-1">
            <span 
              v-for="(quantity, ingredient) in recipe.ingredients" 
              :key="ingredient"
              :class="[
                'text-xs px-2 py-1 rounded-full',
                getIngredientAvailability(ingredient, quantity) ? 
                'bg-green-100 text-green-700' : 
                'bg-red-100 text-red-700'
              ]"
            >
              {{ ingredient }} ({{ quantity }})
            </span>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Info adicional -->
    <div class="mt-4 pt-4 border-t border-gray-200">
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600">Tiempo promedio:</span>
        <div class="text-right">
          <span class="font-medium text-gray-900 block">{{ averageDemoTime }}s (demo)</span>
          <span class="text-xs text-gray-500">Real: {{ averageTime }}min</span>
        </div>
      </div>
      <div class="flex items-center justify-between text-sm mt-1">
        <span class="text-gray-600">Recetas preparables:</span>
        <span 
          :class="[
            'font-medium',
            availableRecipesCount === store.recipes.length ? 'text-green-600' : 'text-orange-600'
          ]"
        >
          {{ availableRecipesCount }}/{{ store.recipes.length }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRestaurantStore } from '../stores/restaurant'

const store = useRestaurantStore()

const averageTime = computed(() => {
  if (store.recipes.length === 0) return 0
  const total = store.recipes.reduce((sum, recipe) => sum + recipe.preparation_time, 0)
  return Math.round(total / store.recipes.length)
})

const averageDemoTime = computed(() => {
  if (store.recipes.length === 0) return 0
  const total = store.recipes.reduce((sum, recipe) => sum + store.getDemoTime(recipe.preparation_time), 0)
  return Math.round(total / store.recipes.length)
})

const availableRecipesCount = computed(() => {
  return store.recipes.filter(recipe => 
    Object.entries(recipe.ingredients).every(([ingredient, quantity]) => 
      getIngredientAvailability(ingredient, quantity)
    )
  ).length
})

const getIngredientAvailability = (ingredient, requiredQuantity) => {
  const inventoryItem = store.inventory.find(item => item.ingredient === ingredient)
  return inventoryItem ? inventoryItem.available_quantity >= requiredQuantity : false
}

const getRecipeEmoji = (recipeName) => {
  const emojis = {
    'Margherita Pizza': '🍕',
    'Caesar Salad': '🥗',
    'Grilled Chicken': '🍗',
    'Classic Burger': '🍔',
    'Meat and Rice Bowl': '🍲',
    'Chicken Rice Bowl': '🍛'
  }
  return emojis[recipeName] || '🍽️'
}

const showRecipeDetails = (recipe) => {
  console.log('Mostrando detalles de receta:', recipe)
  // Implementar modal o vista detallada
}
</script> 