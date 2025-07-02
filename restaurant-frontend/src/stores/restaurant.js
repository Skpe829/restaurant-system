import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

// URLs de los microservicios
const API_URLS = {
  // Configuración para desarrollo local
  // Descomenta estas líneas para desarrollo local:
  /*
  orders: 'http://localhost:8000/api',
  kitchen: 'http://localhost:8001/api', 
  warehouse: 'http://localhost:8002/api',
  marketplace: 'http://localhost:8003/api'
  */

  // Configuración para producción (AWS Lambda)
  orders: 'https://hkg61nbow3.execute-api.us-east-1.amazonaws.com/api',
  kitchen: 'https://pgvdivqhbi.execute-api.us-east-1.amazonaws.com/api',
  warehouse: 'https://0fntnq3zqe.execute-api.us-east-1.amazonaws.com/api',
  marketplace: 'https://3euu6m4xs6.execute-api.us-east-1.amazonaws.com/api'
}

export const useRestaurantStore = defineStore('restaurant', () => {
  // Estado reactivo
  const orders = ref([])
  const inventory = ref([])
  const recipes = ref([])
  const purchases = ref([])
  const systemStatus = ref({
    orders: true,
    kitchen: true,
    warehouse: true,
    marketplace: true
  })
  const isLoading = ref(false)
  const lastUpdate = ref(new Date())

  // Computed properties
  const activeOrders = computed(() => 
    orders.value.filter(order => 
      !['delivered', 'failed', 'failed_unavailable_ingredients'].includes(order.status)
    )
  )

  const ordersInPreparation = computed(() =>
    orders.value.filter(order => order.status === 'in_preparation')
  )

  const ordersReady = computed(() =>
    orders.value.filter(order => order.status === 'ready')
  )

  const ordersInProgress = computed(() =>
    orders.value.filter(order => 
      ['pending', 'processing', 'in_preparation'].includes(order.status)
    )
  )

  const lowStockItems = computed(() =>
    inventory.value.filter(item => item.available_quantity < 3)
  )

  const recentPurchases = computed(() =>
    purchases.value.slice(0, 5)
  )

  const orderHistory = computed(() =>
    orders.value.slice().reverse().slice(0, 10)
  )

  // Actions
  const createOrder = async (orderData) => {
    try {
      isLoading.value = true
      
      const response = await axios.post(`${API_URLS.orders}/orders`, {
        quantity: orderData.quantity || 1,
        customer_name: orderData.customer_name || `Cliente ${Date.now().toString().slice(-4)}`
      })

      if (response.data.success) {
        orders.value.unshift(response.data.data)
        await refreshOrders() // Actualizar lista completa
        return response.data.data
      } else {
        throw new Error(response.data.message || 'Error al crear orden')
      }
    } catch (error) {
      console.error('Error creating order:', error)
      throw error
    } finally {
      isLoading.value = false
    }
  }

  const refreshOrders = async () => {
    try {
      const response = await axios.get(`${API_URLS.orders}/orders`)
      if (response.data.success) {
        orders.value = response.data.data
      }
    } catch (error) {
      console.error('Error fetching orders:', error)
      systemStatus.value.orders = false
    }
  }

  const refreshInventory = async () => {
    try {
      const response = await axios.get(`${API_URLS.warehouse}/inventory`)
      if (response.data.success) {
        inventory.value = response.data.data
        systemStatus.value.warehouse = true
      }
    } catch (error) {
      console.error('Error fetching inventory:', error)
      systemStatus.value.warehouse = false
    }
  }

  const refreshRecipes = async () => {
    try {
      const response = await axios.get(`${API_URLS.kitchen}/recipes`)
      if (response.data.success) {
        recipes.value = response.data.data
        systemStatus.value.kitchen = true
      }
    } catch (error) {
      console.error('Error fetching recipes:', error)
      systemStatus.value.kitchen = false
    }
  }

  const refreshPurchases = async () => {
    try {
      const response = await axios.get(`${API_URLS.marketplace}/purchase-history?limit=20`)
      if (response.data.success) {
        purchases.value = response.data.data
        systemStatus.value.marketplace = true
      }
    } catch (error) {
      console.error('Error fetching purchases:', error)
      systemStatus.value.marketplace = false
    }
  }

  const refreshData = async () => {
    lastUpdate.value = new Date()
    await Promise.all([
      refreshOrders(),
      refreshInventory(),
      refreshRecipes(),
      refreshPurchases()
    ])
  }

  const startAutoRefresh = () => {
    // Auto-refresh más frecuente para ver transiciones de estado en tiempo real
    return setInterval(async () => {
      await refreshOrders()
    }, 5000) // Cada 5 segundos
  }

  const initializeDashboard = async () => {
    isLoading.value = true
    try {
      // Inicializar inventario si está vacío
      try {
        await axios.post(`${API_URLS.warehouse}/inventory/initialize`)
      } catch (error) {
        console.log('Inventory already initialized or error:', error.message)
      }
      
      // Cargar todos los datos
      await refreshData()
    } catch (error) {
      console.error('Error initializing dashboard:', error)
    } finally {
      isLoading.value = false
    }
  }

  const getStatusColor = (status) => {
    const colors = {
      'pending': 'bg-yellow-100 text-yellow-800',
      'processing': 'bg-blue-100 text-blue-800',
      'in_preparation': 'bg-orange-100 text-orange-800',
      'ready': 'bg-green-100 text-green-800',
      'delivered': 'bg-gray-100 text-gray-800',
      'waiting_marketplace': 'bg-purple-100 text-purple-800',
      'needs_external_purchase': 'bg-purple-100 text-purple-800',
      'failed_unavailable_ingredients': 'bg-red-100 text-red-800',
      'failed': 'bg-red-100 text-red-800'
    }
    return colors[status] || 'bg-gray-100 text-gray-800'
  }

  const getStatusText = (status) => {
    const statusTexts = {
      'pending': 'Pendiente',
      'processing': 'Procesando',
      'in_preparation': 'Cocinando',
      'ready': 'Listo',
      'delivered': 'Entregado',
      'waiting_marketplace': 'Comprando',
      'needs_external_purchase': 'Comprar en otra tienda',
      'failed_unavailable_ingredients': 'Sin ingredientes',
      'failed': 'Fallido'
    }
    return statusTexts[status] || status
  }

  const getStatusDescription = (status) => {
    const descriptions = {
      'pending': 'Orden recibida, iniciando procesamiento',
      'processing': 'Seleccionando recetas y calculando ingredientes',
      'in_preparation': 'Los chefs están preparando tus platos',
      'ready': 'Tu orden está lista para entrega',
      'delivered': 'Orden entregada exitosamente',
      'failed': 'Hubo un error procesando la orden',
      'waiting_marketplace': 'Comprando ingredientes faltantes en el marketplace',
      'failed_unavailable_ingredients': 'No hay suficientes ingredientes disponibles'
    }
    return descriptions[status] || 'Estado desconocido'
  }

  const getDemoTime = (realTimeMinutes) => {
    // Convertir tiempo real de receta a tiempo de demo para prueba técnica
    // 15-35 min reales → 10-30 segundos demo
    return Math.max(10, Math.min(realTimeMinutes, 30))
  }

  const formatDemoTime = (realTimeMinutes) => {
    const demoSeconds = getDemoTime(realTimeMinutes)
    return `${demoSeconds}s (demo)`
  }

  return {
    // Estado
    orders,
    inventory,
    recipes,
    purchases,
    systemStatus,
    isLoading,
    lastUpdate,
    
    // Computed
    activeOrders,
    ordersInPreparation,
    ordersReady,
    ordersInProgress,
    lowStockItems,
    recentPurchases,
    orderHistory,
    
    // Actions
    createOrder,
    refreshData,
    startAutoRefresh,
    initializeDashboard,
    getStatusColor,
    getStatusText,
    getStatusDescription,
    getDemoTime,
    formatDemoTime
  }
}) 