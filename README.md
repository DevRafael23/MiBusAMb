# TransMecar App Usuario - Seguimiento Flota en Vivo

PWA de Monitoreo GPS con Geofencing y Arquitectura Micro-cron.

## 📝 Resumen
Aplicación Web Progresiva (PWA) instalable para pasajeros del transporte urbano. Muestra la flota en tiempo real sobre Leaflet/OpenStreetMap con marcadores personalizados, animaciones fluidas (easing cuadrático) y auto-expansión del radio de búsqueda. Incluye un sistema de "merge" de datos para evitar que los buses desaparezcan del mapa si la API falla, alertas de proximidad a destino (geofencing) y caché offline mediante Service Workers.

## 🛠️ Stack Tecnológico
- **Frontend:** Vue.js 3, Leaflet 1.9.4, JavaScript ES6+, Service Workers (PWA), Geolocation API, Web Audio API, Nominatim (Geocoding).
- **Backend:** PHP 8+, `curl_multi` (peticiones en paralelo), JSON Cache (escritura atómica).

## ✨ Características Principales
- **Mapa interactivo:** Visualización en tiempo real con marcadores animados y dirección dinámica.
- **Micro-cron jobs:** Backend dividido para alta eficiencia (un daemon rápido para GPS y uno lento para rutas), ahorrando hasta un 90% de peticiones a la API externa.
- **Sistema Anti-caídas:** Si la API falla, el sistema fusiona la nueva data con el JSON anterior para evitar parpadeos.
- **Alertas de llegada (Geofencing):** Notificaciones nativas push y alertas sonoras generadas con Web Audio API.
- **Soporte Offline:** Instalable en Android/iOS mediante Service Worker.

## 🚀 Retos Técnicos Resueltos
- Evitar el *rate-limiting* de la API externa mediante la separación de cron jobs (GPS masivo con `curl_multi` vs. rutas con caché).
- Garantizar la consistencia del JSON con escritura atómica (`temp + rename`).
- Interpolación de telemetría cada 4s para simular movimiento continuo y eliminar el efecto "salto".
