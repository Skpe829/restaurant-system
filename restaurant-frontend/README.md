# 🍔 Restaurant Manager Dashboard

Frontend Vue.js para el sistema de gestión de restaurante con arquitectura serverless.

## 🎯 Características

- **Dashboard en tiempo real** para gerentes de restaurante
- **Interfaz intuitiva** con UX/UI simplificado
- **Botón de alta demanda** para crear órdenes rápidamente
- **Monitoreo en vivo** de inventario, órdenes y compras
- **Conexión a microservicios** serverless en AWS

## 🚀 Instalación Rápida

```bash
# Instalar dependencias
npm install

# Desarrollo
npm run dev

# Build para producción
npm run build
```

## 📱 Visualizaciones del Dashboard

### ✅ Implementadas según requisitos:

1. **📋 Órdenes en Preparación** - Status en tiempo real
2. **🏪 Inventario de la Bodega** - Stock con alertas visuales  
3. **📜 Recetas Disponibles** - 6 recetas con ingredientes
4. **🛒 Historial de Compras Plaza** - Marketplace automático
5. **📊 Historial de Pedidos** - Con estadísticas del día
6. **⚡ Estado del Sistema** - Monitoreo de microservicios

## 🎨 Stack Tecnológico

- **Vue.js 3** + Composition API
- **Pinia** para gestión de estado
- **Tailwind CSS** para estilos
- **Vite** para build optimizado
- **Axios** para APIs REST

## 🔧 Configuración

Las APIs serverless están pre-configuradas:

```javascript
const API_URLS = {
  orders: 'https://hkg61nbow3.execute-api.us-east-1.amazonaws.com/api',
  kitchen: 'https://pgvdivqhbi.execute-api.us-east-1.amazonaws.com/api', 
  warehouse: 'https://0fntnq3zqe.execute-api.us-east-1.amazonaws.com/api',
  marketplace: 'https://3euu6m4xs6.execute-api.us-east-1.amazonaws.com/api'
}
```

## 📦 Deploy

```bash
# Build de producción
npm run build

# Deploy en Vercel/Netlify
# Los archivos están en ./dist/
```

## 🎮 Uso para Gerente

1. **Crear Nueva Orden**: Botón principal en header
2. **Monitorear Inventario**: Alertas automáticas de stock bajo
3. **Ver Progreso**: Estados de órdenes en tiempo real
4. **Revisar Compras**: Transacciones automáticas del marketplace
5. **Estadísticas**: Eficiencia y métricas del día

## 🔄 Auto-refresh

- **30 segundos**: Actualización automática de datos
- **Tiempo real**: Indicadores de estado
- **Throttling**: Prevención de spam en botón de órden 