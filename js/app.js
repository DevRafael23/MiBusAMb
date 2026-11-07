const { createApp, ref, onMounted, onBeforeUnmount } = Vue;

createApp({
    setup() {
        const cargando = ref(true);
        const conectado = ref(false);
        const filtro = ref('');
        const rutaSeleccionada = ref('');
        const listaRutasOficiales = ref(['CALLE_30', 'GRANABASTOS', 'CALLE_17', 'CARACOLI']);
        const selectedBus = ref(null);
        const direccionDetalle = ref('');
        const userCoords = ref(null);
        const totalBusesVisibles = ref(0);
        const seguirBusActivo = ref(false);
        const gpsDesactivado = ref(false);
        const panelOculto = ref(false);
        const verManual = ref(false);
        const darkMode = ref(false);
        const rutaFavorita = ref(localStorage.getItem('rutaFavorita') || '');
        const mostrarSitios = ref(false);

        const verAlertas = ref(false);
        const destinoTexto = ref('');
        const destinoCoords = ref(null);
        const destinoNombre = ref('');
        const cargandoDestino = ref(false);
        const radioAlerta = ref(1);
        const alertaDisparada = ref(false);
        
        const referenciasCargadas = ref([]);
        const mostrarSugerencias = ref(false);
        const sugerenciasFiltradas = ref([]);

        const textoManual = ref('Cargando manual...');

        const radioBase = ref(2);
        const radioMax = ref(5);
        const radioUsado = ref(2);
        const minBusesDeseados = ref(3);
        const maxBusesMostrar = ref(15);

        let map = null;
        let userMarker = null;
        let marcadores = {};
        let datosBuses = {};
        let pollInterval = null;
        const animacionesBus = {};
        let watchId = null;
        const cacheDirecciones = new Map();

        let capaSitios = L.layerGroup();
        let capaTrazado = L.layerGroup();
        let cacheRutasGeo = {};
        let primerCentrado = false;
        let hacerZoomRuta = false;

        const sitiosData = [
            { nombre: "Estadio Romelio Martínez", lat: 10.9648, lng: -74.7845 },
            { nombre: "Parque Venezuela", lat: 10.9780, lng: -74.7920 },
            { nombre: "Aeropuerto E. Cortissoz", lat: 10.8890, lng: -74.7810 },
            { nombre: "Gran Malecón", lat: 11.0180, lng: -74.8100 },
            { nombre: "Prado", lat: 10.9870, lng: -74.8000 }
        ];

        const cargarManual = async () => {
            try {
                const res = await fetch('./data/manual.txt?t=' + Date.now());
                if (res.ok) textoManual.value = await res.text();
            } catch (e) { textoManual.value = "Error cargando el manual."; }
        };

        const cargarRutasGeoJSON = async () => {
            try {
                const res = await fetch('./data/rutas_v2.json?t=' + Date.now());
                if (res.ok) {
                    cacheRutasGeo = await res.json();
                    if (rutaSeleccionada.value) actualizarTrazado();
                }
            } catch (error) { console.error("Error cargando rutas.json", error); }
        };

        const cargarReferencias = async () => {
            try {
                const res = await fetch('./data/referencias.json?t=' + Date.now());
                if (res.ok) referenciasCargadas.value = await res.json();
            } catch (e) { console.warn("No se encontró referencias.json"); }
        };

        const filtrarReferencias = () => {
            const q = destinoTexto.value.trim().toLowerCase();
            mostrarSugerencias.value = true;
            if (q.length < 2) { sugerenciasFiltradas.value = []; return; }
            sugerenciasFiltradas.value = referenciasCargadas.value.filter(ref => 
                ref.nombre.toLowerCase().includes(q) || (ref.categoria && ref.categoria.toLowerCase().includes(q))
            ).slice(0, 8);
        };

        const seleccionarReferencia = (ref) => {
            vibrar(60);
            destinoCoords.value = { lat: ref.lat, lng: ref.lng };
            destinoNombre.value = ref.nombre;
            destinoTexto.value = ref.nombre; 
            alertaDisparada.value = false;
            mostrarSugerencias.value = false;
            localStorage.setItem('destinoAlerta', JSON.stringify({
                lat: ref.lat, lng: ref.lng, nombre: ref.nombre, radio: radioAlerta.value
            }));
        };

        const vibrar = (ms = 40) => { if (navigator.vibrate) navigator.vibrate(ms); };
        const toggleDarkMode = () => { darkMode.value = !darkMode.value; document.body.classList.toggle('dark-mode', darkMode.value); vibrar(); };
        const guardarFavorita = () => {
            if (!rutaSeleccionada.value) { localStorage.removeItem('rutaFavorita'); rutaFavorita.value = ''; } 
            else { localStorage.setItem('rutaFavorita', rutaSeleccionada.value); rutaFavorita.value = rutaSeleccionada.value; }
            vibrar(60);
        };

        const toggleSitios = () => {
            vibrar();
            mostrarSitios.value = !mostrarSitios.value;
            if (mostrarSitios.value) {
                capaSitios.clearLayers();
                sitiosData.forEach(sitio => {
                    const icono = L.divIcon({
                        className: 'sitio-marker',
                        html: `<div style="background:#f59e0b; color:white; width:24px; height:24px; border-radius:50%; display:flex; align-items: center; justify-content: center; font-size:12px; border:2px solid white; box-shadow:0 2px 4px rgba(0,0,0,0.3);">★</div>`,
                        iconSize: [24, 24], iconAnchor: [12, 12]
                    });
                    const m = L.marker([sitio.lat, sitio.lng], { icon: icono });
                    m.bindPopup(`<b style="color:#f59e0b">${sitio.nombre}</b><br><span style="font-size:11px">Sitio representativo</span>`);
                    m.addTo(capaSitios);
                });
                capaSitios.addTo(map);
            } else { map.removeLayer(capaSitios); }
        };

        const actualizarTrazado = () => {
            capaTrazado.clearLayers();
            if (map.hasLayer(capaTrazado)) map.removeLayer(capaTrazado);
            if (rutaSeleccionada.value && cacheRutasGeo[rutaSeleccionada.value]) {
                const coords = cacheRutasGeo[rutaSeleccionada.value];
                const colorRuta = colorDeRuta(rutaSeleccionada.value);
                const polyline = L.polyline(coords, { color: colorRuta, weight: 5, opacity: 0.6, lineJoin: 'round', dashArray: '1, 0' }).addTo(capaTrazado);
                capaTrazado.addTo(map);
                if (hacerZoomRuta) { map.fitBounds(polyline.getBounds(), { padding: [30, 30], maxZoom: 15 }); hacerZoomRuta = false; }
            }
        };
        
        const cambiarRuta = () => { hacerZoomRuta = true; aplicarFiltro(); };

        const COLORES_RUTA = { 'CALLE_17': '#3b82f6', 'CALLE_30': '#eab308', 'CARACOLI': '#ef4444', 'GRANABASTOS': '#111827', 'DESCONOCIDA': '#94a3b8', 'Sin ruta': '#94a3b8' };
        const colorDeRuta = (ruta) => COLORES_RUTA[ruta] || '#94a3b8';

        const calcularDistanciaKm = (lat1, lon1, lat2, lon2) => {
            const R = 6371; const dLat = (lat2 - lat1) * Math.PI / 180; const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); return R * c;
        };

        const contarPorRuta = (ruta) => { let c = 0; Object.values(datosBuses).forEach(b => { if (b.ruta === ruta) c++; }); return c; };

        const calcularIdsCercanos = (qText, qRuta) => {
            if (!userCoords.value) return null;
            const candidatos = Object.values(datosBuses).filter(bus => {
                const coincideTexto = !qText || String(bus.nroMovil || '').toLowerCase().includes(qText) || String(bus.placa || '').toLowerCase().includes(qText);
                const coincideRuta = !qRuta || bus.ruta === qRuta; return coincideTexto && coincideRuta;
            });
            if (candidatos.length === 0) { radioUsado.value = radioBase.value; return new Set(); }
            const conDist = candidatos.map(bus => ({ id: bus.id, dist: calcularDistanciaKm(userCoords.value.lat, userCoords.value.lng, bus.lat, bus.lng) })).sort((a, b) => a.dist - b.dist);
            let radioPrueba = radioBase.value; let dentro = [];
            while (radioPrueba <= radioMax.value) {
                dentro = conDist.filter(b => b.dist <= radioPrueba);
                if (dentro.length >= minBusesDeseados.value) break;
                if (radioPrueba === radioMax.value) break; radioPrueba++;
            }
            radioUsado.value = radioPrueba;
            const seleccionados = dentro.slice(0, maxBusesMostrar.value); return new Set(seleccionados.map(b => b.id));
        };

        const userIcon = L.divIcon({
            className: 'custom-user-marker',
            html: `<div class="user-marker-box"><span class="user-badge">Estoy aquí</span><div class="user-dot"><div class="user-pulse"></div></div></div>`,
            iconSize: [80, 42], iconAnchor: [40, 36]
        });

        const obtenerIconoBusPng = (nroMovil, ruta, enMovimiento, heading) => {
            let num = String(nroMovil || '000').replace(/\D/g, '').padStart(3, '0'); const color = colorDeRuta(ruta);
            const indicador = enMovimiento ? '<div class="bus-marker-movimiento"></div>' : '';
            const flechaHTML = (heading !== null && heading !== undefined) ? `<div class="bus-marker-arrow" style="transform: rotate(${heading}deg)">▲</div>` : '';
            return L.divIcon({
                className: 'bus-custom-marker-wrapper',
                html: `<div class="bus-marker-inner"><div class="bus-marker-halo" style="box-shadow: 0 0 0 3px ${color}, 0 0 14px ${color};"></div><img src="./imagenes/buses/carro${num}.png" onerror="this.onerror=null; this.src='./imagenes/buses/carro000.png';" class="bus-marker-img" />${flechaHTML}${indicador}</div>`,
                iconSize: [44, 44], iconAnchor: [22, 22]
            });
        };

        const initMap = () => {
            map = L.map('map', { zoomControl: false }).setView([10.9685, -74.7980], 14);
            // Movemos el Zoom a la esquina superior izquierda
            L.control.zoom({ position: 'topleft' }).addTo(map);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
                attribution: '&copy; OpenStreetMap', maxZoom: 19 
            }).addTo(map);
        };

        const obtenerDireccion = async (lat, lng) => {
            const key = `${lat.toFixed(4)},${lng.toFixed(4)}`; if (cacheDirecciones.has(key)) return cacheDirecciones.get(key);
            try {
                const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, { headers: { 'User-Agent': 'Transmecar' } });
                const data = await res.json(); const addr = data.address || {};
                let dir = addr.road || addr.pedestrian || addr.path || '';
                if (addr.neighbourhood || addr.suburb) dir += `, B/ ${addr.neighbourhood || addr.suburb}`;
                if (!dir) dir = data.display_name ? data.display_name.split(',').slice(0, 2).join(',') : 'Ubicación';
                cacheDirecciones.set(key, dir); return dir;
            } catch { return 'No disponible'; }
        };

        const autoCentrarUser = ref(true);

        const renderizarOActualizarUsuario = (lat, lng, accuracy) => {
            userCoords.value = { lat, lng }; 
            gpsDesactivado.value = false;

            const popupContent = `<div style="color:#0f172a; font-family:'Inter',sans-serif; padding:4px; font-size:13px; min-width: 150px;"><b style="color:#1d4ed8; font-size:14px;">📍 Tu Ubicación</b><br><span id="user-dir-text" style="font-size:12px; color:#475569;">Obteniendo dirección...</span></div>`;

            if (userMarker) userMarker.setLatLng([lat, lng]);
            else {
                userMarker = L.marker([lat, lng], { icon: userIcon, zIndexOffset: 1000 }).addTo(map);
                userMarker.bindPopup(popupContent);
                userMarker.on('click', async () => {
                    userMarker.openPopup(); const coords = userCoords.value; if (!coords) return;
                    const dir = await obtenerDireccion(coords.lat, coords.lng); const elem = document.getElementById('user-dir-text'); if (elem) elem.innerText = dir;
                });
            }
            if (autoCentrarUser.value && !primerCentrado) { map.panTo([lat, lng], { animate: true, duration: 0.5 }); primerCentrado = true; }
            comprobarAlerta(); aplicarFiltro();
        };

        const solicitarGPS = () => {
            if (!navigator.geolocation) { gpsDesactivado.value = true; return; }
            const onSuccess = (pos) => renderizarOActualizarUsuario(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
            const onError = (err) => { if (err && err.code === 1) gpsDesactivado.value = true; };
            navigator.geolocation.getCurrentPosition(onSuccess, () => {
                navigator.geolocation.getCurrentPosition(onSuccess, onError, { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 });
            }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 });
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            watchId = navigator.geolocation.watchPosition(onSuccess, onError, { enableHighAccuracy: true, timeout: 20000, maximumAge: 15000 });
        };

        const centrarEnPasajero = () => { vibrar(); autoCentrarUser.value = true; if (userCoords.value) map.flyTo([userCoords.value.lat, userCoords.value.lng], 16, { duration: 0.8 }); else solicitarGPS(); };
        const centrarEnBus = (bus) => { vibrar(); autoCentrarUser.value = false; map.flyTo([bus.lat, bus.lng], 16, { duration: 0.8 }); };

        const animarMarcador = (id, marker, desde, hasta, duracionMs = 3000) => {
            if (animacionesBus[id]) clearInterval(animacionesBus[id]);
            const dLat = Math.abs(hasta[0] - desde[0]); const dLng = Math.abs(hasta[1] - desde[1]);
            if (dLat < 1e-7 && dLng < 1e-7) { marker.setLatLng(hasta); return; }
            const pasos = 20, pasoMs = duracionMs / pasos; let i = 0;
            animacionesBus[id] = setInterval(() => {
                i++; const f = Math.min(i / pasos, 1); const fe = f < 0.5 ? 2 * f * f : 1 - Math.pow(-2 * f + 2, 2) / 2;
                const lat = desde[0] + (hasta[0] - desde[0]) * fe; const lng = desde[1] + (hasta[1] - desde[1]) * fe;
                if (!marker || !map.hasLayer(marker)) { clearInterval(animacionesBus[id]); return; }
                marker.setLatLng([lat, lng]);
                if (seguirBusActivo.value && selectedBus.value && selectedBus.value.id === id) map.panTo([lat, lng], { animate: false });
                if (i >= pasos) clearInterval(animacionesBus[id]);
            }, pasoMs);
        };

        const calcularMetricasBus = (vehiculo) => {
            if (userCoords.value) {
                const dist = calcularDistanciaKm(userCoords.value.lat, userCoords.value.lng, vehiculo.lat, vehiculo.lng);
                vehiculo.distanciaKm = dist.toFixed(1); const velPromedio = vehiculo.speed > 5 ? vehiculo.speed : 20;
                vehiculo.tiempoEstimadoMin = Math.ceil((dist / velPromedio) * 60);
            } else { vehiculo.distanciaKm = null; vehiculo.tiempoEstimadoMin = null; }
        };

        const seleccionarBus = async (bus) => {
            vibrar(50); autoCentrarUser.value = false; calcularMetricasBus(bus);
            selectedBus.value = bus; direccionDetalle.value = 'Cargando...';
            map.flyTo([bus.lat, bus.lng], 15, { duration: 0.8 });
            direccionDetalle.value = await obtenerDireccion(bus.lat, bus.lng);
        };

        const cerrarTarjeta = () => { vibrar(); selectedBus.value = null; };
        const abrirAlertas = () => { vibrar(); verAlertas.value = true; };
        const cerrarModalAlertas = () => { verAlertas.value = false; mostrarSugerencias.value = false; };

        const buscarDestino = async () => {
            if (!destinoTexto.value.trim()) return;
            cargandoDestino.value = true;
            try {
                const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(destinoTexto.value)}&limit=1&countrycodes=co`;
                const res = await fetch(url, { headers: { 'User-Agent': 'Transmecar-Pasajero' } });
                const data = await res.json();
                if (data && data.length > 0) {
                    destinoCoords.value = { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
                    destinoNombre.value = data[0].display_name; alertaDisparada.value = false;
                    localStorage.setItem('destinoAlerta', JSON.stringify({ lat: destinoCoords.value.lat, lng: destinoCoords.value.lng, nombre: destinoNombre.value, radio: radioAlerta.value }));
                    vibrar(100);
                } else alert('No encontramos esa dirección.');
            } catch (error) { alert('Error de conexión.'); } 
            finally { cargandoDestino.value = false; }
        };

        const borrarDestino = () => {
            destinoCoords.value = null; destinoNombre.value = ''; destinoTexto.value = ''; alertaDisparada.value = false;
            localStorage.removeItem('destinoAlerta'); vibrar(60);
        };

        const comprobarAlerta = () => {
            if (!destinoCoords.value || alertaDisparada.value || !userCoords.value) return;
            const distanciaAlDestino = calcularDistanciaKm(userCoords.value.lat, userCoords.value.lng, destinoCoords.value.lat, destinoCoords.value.lng);
            if (distanciaAlDestino <= radioAlerta.value) {
                alertaDisparada.value = true;
                if (navigator.vibrate) navigator.vibrate([300, 100, 300, 100, 300]);
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const oscillator = ctx.createOscillator(); const gainNode = ctx.createGain();
                    oscillator.connect(gainNode); gainNode.connect(ctx.destination);
                    oscillator.frequency.value = 880; oscillator.type = 'sine'; gainNode.gain.value = 0.3;
                    oscillator.start(); setTimeout(() => { oscillator.stop(); ctx.close(); }, 400);
                } catch (e) { console.warn('Audio no soportado', e); }
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('🚌 ¡Estás llegando a tu destino!', { body: `Estás a ${(distanciaAlDestino * 1000).toFixed(0)} metros de ${destinoNombre.value.split(',')[0]}.`, icon: './imagenes/buses/carro000.png' });
                }
                setTimeout(() => { alert(`🎯 ¡Estás llegando!\nTe encuentras a ${(distanciaAlDestino * 1000).toFixed(0)} metros de tu destino.`); }, 100);
            }
        };

        const consultarTelemetria = async () => {
            try {
                const res = await fetch('./api/telemetria_api.php?t=' + Date.now());
                const result = await res.json();
                if (result.status === "success" && Array.isArray(result.data)) {
                    if (result.data.length === 0 && Object.keys(marcadores).length > 5) return;
                    conectado.value = true;
                    result.data.forEach(vehiculo => {
                        const id = vehiculo.id; const previo = datosBuses[id]; datosBuses[id] = vehiculo; calcularMetricasBus(vehiculo); const enMovimiento = vehiculo.speed >= 3;
                        if (marcadores[id]) {
                            const rutaCambio = !previo || previo.ruta !== vehiculo.ruta || (previo.speed >= 3) !== enMovimiento;
                            if (rutaCambio) marcadores[id].setIcon(obtenerIconoBusPng(vehiculo.nroMovil, vehiculo.ruta, enMovimiento, vehiculo.heading));
                            const pos = marcadores[id].getLatLng();
                            if (enMovimiento && previo) animarMarcador(id, marcadores[id], [pos.lat, pos.lng], [vehiculo.lat, vehiculo.lng]);
                            else marcadores[id].setLatLng([vehiculo.lat, vehiculo.lng]);
                        } else {
                            const m = L.marker([vehiculo.lat, vehiculo.lng], { icon: obtenerIconoBusPng(vehiculo.nroMovil, vehiculo.ruta, enMovimiento, vehiculo.heading) });
                            m.on('click', () => seleccionarBus(vehiculo)); marcadores[id] = m;
                        }
                        if (selectedBus.value && selectedBus.value.id === id) selectedBus.value = vehiculo;
                    });
                    Object.keys(marcadores).forEach(id => {
                        if (!result.data.find(b => b.id === id)) {
                            if (map.hasLayer(marcadores[id])) map.removeLayer(marcadores[id]);
                            delete marcadores[id]; delete datosBuses[id];
                        }
                    });
                    aplicarFiltro();
                }
            } catch (e) { conectado.value = false; } 
            finally { cargando.value = false; }
        };

        let filtroTimeout = null;
        const aplicarFiltroDebounced = () => { clearTimeout(filtroTimeout); filtroTimeout = setTimeout(aplicarFiltro, 200); };

        const aplicarFiltro = () => {
            const qText = filtro.value.trim().toLowerCase(); const qRuta = rutaSeleccionada.value; let visibles = 0;
            const idsPermitidos = calcularIdsCercanos(qText, qRuta);
            Object.keys(marcadores).forEach(id => {
                const m = marcadores[id], bus = datosBuses[id]; if (!bus) return;
                const coincideTexto = !qText || String(bus.nroMovil).includes(qText) || String(bus.placa).includes(qText);
                const coincideRuta = !qRuta || (bus.ruta === qRuta); const permitido = !idsPermitidos || idsPermitidos.has(id);
                if (coincideTexto && coincideRuta && permitido) { if (!map.hasLayer(m)) m.addTo(map); visibles++; } 
                else { if (map.hasLayer(m)) map.removeLayer(m); }
            });
            totalBusesVisibles.value = visibles; actualizarTrazado();
        };

        const limpiarFiltro = () => { vibrar(); filtro.value = ''; rutaSeleccionada.value = ''; aplicarFiltro(); selectedBus.value = null; };

        const textoFrescura = (id) => {
            if (!timestampsBus[id]) return '';
            const fechaBogota = new Date(new Date().toLocaleString("en-US", {timeZone: "America/Bogota"}));
            const fechaBus = new Date(new Date(timestampsBus[id]).toLocaleString("en-US", {timeZone: "America/Bogota"}));
            const seg = Math.floor((fechaBogota - fechaBus) / 1000);
            if (seg < 5) return 'Actualizado ahora';
            if (seg < 60) return `Actualizado hace ${seg}s`;
            return `Actualizado hace ${Math.floor(seg/60)} min`;
        };

        onMounted(() => {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                darkMode.value = true; document.body.classList.add('dark-mode');
            }
            if (rutaFavorita.value) rutaSeleccionada.value = rutaFavorita.value;

            const alertaGuardada = localStorage.getItem('destinoAlerta');
            if (alertaGuardada) {
                try {
                    const parsed = JSON.parse(alertaGuardada);
                    destinoCoords.value = { lat: parsed.lat, lng: parsed.lng };
                    destinoNombre.value = parsed.nombre; radioAlerta.value = parsed.radio || 1;
                } catch (e) { console.warn(e); }
            }

            if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();

            initMap();
            cargarManual();
            cargarRutasGeoJSON(); 
            cargarReferencias();
            setTimeout(solicitarGPS, 500);
            consultarTelemetria();
            pollInterval = setInterval(consultarTelemetria, 4000);
        });

        onBeforeUnmount(() => {
            if (pollInterval) clearInterval(pollInterval);
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            Object.values(animacionesBus).forEach(clearInterval);
        });

        return {
            cargando, conectado, filtro, rutaSeleccionada, listaRutasOficiales,
            selectedBus, direccionDetalle, gpsDesactivado, totalBusesVisibles, 
            panelOculto, verManual, textoManual, darkMode, rutaFavorita, mostrarSitios,
            radioBase, radioMax, radioUsado, colorDeRuta, contarPorRuta,
            toggleDarkMode, guardarFavorita, vibrar, toggleSitios, solicitarGPS,
            centrarEnPasajero, centrarEnBus, cerrarTarjeta, aplicarFiltro, aplicarFiltroDebounced, 
            limpiarFiltro, cambiarRuta, verAlertas, destinoTexto, destinoCoords, destinoNombre,
            cargandoDestino, radioAlerta, alertaDisparada, abrirAlertas, buscarDestino, borrarDestino,
            mostrarSugerencias, sugerenciasFiltradas, filtrarReferencias, seleccionarReferencia, cerrarModalAlertas,
            textoFrescura
        };
    }
}).mount('#app');