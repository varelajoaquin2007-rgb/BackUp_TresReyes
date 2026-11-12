# Documentación Técnica del Proyecto Final

**Instituto Leonardo Murialdo** · Proyecto, Diseño e Implementación de Sistemas Computacionales

*Recorrido del código y herramientas*

**Proyecto:** Turbologistica para Tres Reyes (sistema de logística y reparto)  
**Curso:** 7mo Informatica A  
**Integrantes:** Crupi Gino; Garcia Facundo; Silva Mateo; Traverso Lautaro; Varela Joaquín  
**Fecha:** 5 de noviembre de 2026

Turbologistica digitaliza el reparto diario de la fábrica Tres Reyes (San Miguel, Buenos Aires). El administrador carga las boletas en PDF, el sistema las agrupa por zona y arma el viaje con camión y repartidor, y el repartidor recibe la ruta en el celular. El administrador sigue el camión en un mapa en vivo y recibe alertas. Es una aplicación web con API en PHP y base MySQL.

## 1. Las partes del sistema y cómo se relacionan

El sistema tiene cuatro partes: la página web, el servidor PHP, la base de datos MySQL y los servicios externos (Google, Gmail y la app Traccar Client). La página (index.html) se comunica con el servidor por HTTPS, enviando y recibiendo JSON con fetch() a través de la función api(). Solo los scripts PHP leen y escriben en la base de datos; el navegador nunca accede a ella. Los PHP, a su vez, consultan a Google y envían mails por SMTP.

| Parte | Dónde corre | Qué hace | Archivos principales |
| :- | :- | :- | :- |
| **Página web** | Navegador (PC del administrador y celular del repartidor), servida desde tresreyes.turbologistica.com | Login, carga de boletas en PDF, armado y confirmación de viajes, tracking en mapa, reportes, configuración y vista del repartidor | `index.html` (HTML, CSS y JS en un solo archivo), `.htaccess` |
| **Servidor** | Hostinger (plan Premium): PHP con Apache | API JSON, validaciones, agrupación por zona, geocodificación, optimización de ruta y tracking | `config.php`, `login.php`, `boletas.php`, `viajes.php`, `config_api.php`, `tracking.php`, `alertas.php`, `repartidores.php`, `lib_zonas.php` |
| **Base de datos** | MySQL (mysqli) en Hostinger (plan Premium) | Usuarios, repartidores, camiones, boletas, viajes, posiciones GPS, alertas y log de Traccar | No hay `schema.sql`: el repositorio tiene scripts `migracion_*.sql` (ALTER TABLE) y algunas columnas se crean desde `lib_zonas.php`, `recuperar.php` y `tracking.php` |
| **Integraciones** | Google (Geocoding, Routes, Maps JS), Gmail SMTP y app Traccar Client | Ubicar direcciones, ordenar paradas, mostrar mapas, enviar mails de recuperación y recibir el GPS en segundo plano | `lib_zonas.php`, `mailer.php`, `recuperar.php`, `tracking.php` |

## 2. La página web (Frontend)

Toda la interfaz está en index.html (HTML, CSS y JavaScript en un solo archivo de unas 3600 líneas). La función render() elige la vista según el rol del usuario: renderLogin, renderAdmin o renderRepartidor. Usa pdf.js 3.11 para leer PDFs, Leaflet y Google Maps JS para los mapas, y las fuentes Inter y DM Sans de Google Fonts.

- **Ingreso:** doLogin envía email y contraseña a login.php. La respuesta (usuario, rol, repartidor_id y camion_id) se guarda en sessionStorage o, si se tilda "mantener sesión", en localStorage con vencimiento. La validación de campos vacíos, formato de email, usuario y contraseña la hace login.php, y el formulario muestra el error que devuelve.
- **Operación principal:** Administrador: renderBoletas y processPDFs (carga), armarViajes y confirmarViaje (armado), renderViajesAdmin, renderTracking y renderConfig. Repartidor: loadViajesRep, iniciarViaje, finalizarViaje e iniciarSeguimientoUbicacion (GPS). Todo viaja al servidor con api(endpoint, método, body), que usa fetch() con JSON.
- **Reportes / Comprobantes:** La lectura es de entrada: extractPDFText y parseBoleta sacan cliente, dirección, zona, peso y número de boleta de los PDF con pdf.js. Los reportes (renderReportes y buscarReportes, que consulta viajes.php?reporte=1) se ven en pantalla: viajes completados, km, horas en ruta y combustible estimado (0,10 L/km). No se generan PDFs de salida ni se usa jsPDF.
- **Detalles comunes:** Diseño adaptable a celular (@media a 768 px), fuentes Inter y DM Sans, modo claro y oscuro (toggleModo) y avisos con toast(). La función api() captura las respuestas que no son JSON y los errores de red, y muestra un mensaje legible.

## 3. El servidor (Backend)

Cada script PHP incluye config.php, llama a headers() (respuesta JSON y CORS), abre la base con getDB(), lee el cuerpo con getInput() y decide qué hacer según el campo action. Responde siempre con jsonResponse(), y display_errors está apagado para que un aviso de PHP no rompa el JSON. La zona horaria es America/Argentina/Buenos_Aires, tanto en PHP como en MySQL.

| Rutas / Scripts | Para qué sirve |
| :- | :- |
| `login.php` | Ingreso: busca el usuario por email y verifica con password_verify. Si la clave estaba en texto plano, la acepta y la guarda con hash. |
| `recuperar.php` y `mailer.php` | solicitar: genera un token de 1 hora y envía el link por SMTP al mail del administrador. resetear: valida el token y guarda la nueva clave con hash. |
| `boletas.php` | Listar boletas (por fecha, pospuestas, sin asignar) y acciones crear, bulk (lote desde PDF), geocodificar, editar_cliente, update_coords y borrar_todo. |
| `viajes.php` | Listar viajes (admin, histórico, reporte, repartidor) y acciones armar, confirmar, iniciar, entregar_parada, finalizar, mover_boleta, quitar_parada y cancelar. |
| `config_api.php` | Alta, edición y baja de repartidores, camiones y puntos de finalización. |
| `tracking.php` | traccar (GPS de la app), update (GPS web), live (posiciones en curso), desvio, recalcular_ruta y alertas. |
| `alertas.php` y `repartidores.php` | alertas.php: viajes finalizados de los últimos 7 días. repartidores.php: repartidores con su camión y su cantidad de viajes activos, más la lista de camiones. |
| `lib_zonas.php` | Librería: agrupa boletas por zona, geocodifica con Google, optimiza el orden de paradas, calcula km y libera camiones trabados. |

- **Quién es quién (Permisos):** login.php devuelve el rol (admin o repartidor) y render() muestra la vista que corresponde. No se usan $_SESSION, cookies firmadas ni tokens: los endpoints no verifican el rol en el servidor. Es la principal limitación de seguridad y queda como mejora pendiente.
- **Secretos:** Las claves de usuario se guardan con password_hash (bcrypt). Las credenciales de la base, SMTP, Google y TRACCAR_KEY están definidas con define() en config.php; todavía no se usa un archivo .env, y pasarlas fuera del repositorio es lo primero que falta. El token de recuperación usa random_bytes(32) y vence a la hora.
- **Validación y cálculos:** Consultas preparadas (prepare y bind_param) con mysqli; filter_var para emails, clave de al menos 6 caracteres, patente única y capacidad mayor a 0. En confirmar, el servidor revisa que el camión esté disponible y sin otro viaje ese día, y calcula peso_total, km y orden de paradas; entregar_parada solo funciona si el viaje está en curso.

## 4. La base de datos

MySQL (mysqli, utf8mb4) en Hostinger (plan Premium), el mismo hosting del servidor. El repositorio incluye scripts migracion_*.sql (ALTER TABLE), pero no un schema completo: las tablas base se crearon a mano y el código agrega las que faltan con CREATE TABLE y ALTER TABLE.

| Tabla | Qué guarda |
| :- | :- |
| `usuarios` | Nombre, usuario, email, rol (admin o repartidor), contraseña cifrada y datos del token de recuperación (reset_token, reset_expira). |
| `repartidores` | Vínculo con el usuario y el camión, y domicilio de fin de jornada (dest_calle, dest_lat, dest_lon). |
| `camiones` | Patente, marca, modelo, capacidad_kg y estado (disponible, en_viaje, mantenimiento). |
| `boletas` | Cada pedido: cliente, dirección, zona, lat/lon, geo_aproximada, peso_kg, numero_boleta, fecha_carga, estado (pendiente, pospuesta, entregada) y viaje_id. |
| `viajes` | Cada reparto por zona: estado (armado, confirmado, en_curso, finalizado), fecha, camión, repartidor, orden_paradas, ruta_polyline, peso_total, km_estimado, started_at, finished_at y boletas_historico. |
| `posiciones` | Última posición GPS de cada viaje, con precisión y fuente (web o traccar). |
| `alertas_sistema` | Avisos al administrador: finalización de viaje y desvío de ruta. |
| `puntos_finalizacion` y `traccar_log` | Destinos cargados a mano con coordenadas, y registro de diagnóstico de los pedidos de la app Traccar. |

## 5. Integraciones externas, Pasarelas o Hardware

Este proyecto no usa pasarela de pagos ni hardware (ESP32). Las integraciones reales son estas:

- **Google Geocoding, Routes y Maps JS:** geocodificar en boletas.php convierte la dirección en coordenadas con buscarGoogleGeocoding (lib_zonas.php), limitado a Argentina; si no hay resultado exacto cae a la localidad, marca lo aproximado y descarta puntos a más de 80 km de la fábrica. optimizarOrdenRuta usa Routes API (optimizeWaypointOrder, hasta 25 paradas) desde la fábrica hasta el destino elegido. El mapa del panel carga Maps JS con una clave distinta, pensada para el navegador.
- **Traccar Client (GPS):** la app del celular envía la posición a tracking.php?action=traccar&key=..., y el id del dispositivo es el ID del repartidor. Con una clave incorrecta (TRACCAR_KEY) el servidor responde 403. Si se corta la conexión no llegan posiciones y queda guardada la última.
- **Gmail SMTP:** mailer.php es un cliente SMTP propio (STARTTLS, puerto 587) que envía el link de recuperación de contraseña sin depender de la función mail() de PHP.

## 6. El recorrido de punta a punta

Dos situaciones reales del sistema, explicadas en cinco pasos cada una:

- **Caso A (Acción desde el celular/PC):** El administrador confirma un viaje desde la PC (viajes.php, action: confirmar).
  1. **La página:** confirmarViaje() envía a viajes.php un POST con action: confirmar, el camión, el repartidor, el día de salida y el destino final.
  2. **El servidor:** comprueba que el camión exista, no esté en mantenimiento, esté disponible si sale hoy y no tenga otro viaje ese día. Si varias zonas comparten camión, las unifica en un solo viaje.
  3. **La base de datos:** viajes pasa de armado a confirmado con camión, repartidor y fecha; si sale hoy, camiones.estado pasa a en_viaje. Se guardan orden_paradas, peso_total y ruta_polyline.
  4. **Google:** optimizarOrdenRuta pide a Routes API el mejor orden de paradas desde la fábrica; si falla, queda el orden provisorio por cercanía.
  5. **La pantalla:** el servidor responde success, se muestra el aviso "Viaje confirmado. El camión ya figura en viaje.", armarViajes() recarga la lista y el viaje aparece en Viajes del día y en la lista del repartidor.
- **Caso B (Aviso externo o bloqueo):** llega la posición GPS de un repartidor desde la app Traccar.
  1. **La app:** Traccar Client envía id, lat, lon y accuracy a tracking.php?action=traccar&key=... en segundo plano.
  2. **El servidor:** compara la clave con hash_equals; si no coincide responde 403 "clave invalida". Si faltan id, lat o lon, responde 400 y lo anota en traccar_log.
  3. **La base de datos:** busca un viaje en_curso del repartidor. Si no hay, responde "sin viaje en curso" y no guarda nada; si hay, guardarUltimaPosicion actualiza posiciones y se anota OK en traccar_log.
  4. **La respuesta:** el servidor contesta OK en texto plano a la app. Si la pantalla de tracking detecta que el camión se salió del trazado, llama a desvio (una alerta cada 10 minutos como máximo) y a recalcular_ruta (una vez cada 5 minutos como máximo).
  5. **La pantalla:** refreshTracking consulta tracking.php?action=live y mueve el camión en el mapa; las alertas se listan con action=alertas.

## 7. Herramientas utilizadas

Resumen de las tecnologías reales del equipo:

| Herramienta | Para qué se usó |
| :- | :- |
| **Lenguajes** | PHP (API y lógica de negocio), JavaScript (interfaz y llamadas a la API), HTML y CSS (index.html), SQL (MySQL) y JSON como formato de intercambio. El video de presentación usa TypeScript y React (Remotion) en turbo-logistica-remotion/. |
| **Backend y Frontend** | PHP con mysqli, sin framework; cURL para llamar a Google; pdf.js 3.11 para leer PDFs; Leaflet y Google Maps JS para los mapas; Google Fonts (Inter y DM Sans). No se usan Bootstrap, SweetAlert ni jsPDF: los estilos son CSS propio. |
| **Base de datos y Hosting** | MySQL y servidor Apache con PHP en Hostinger (plan Premium); dominio tresreyes.turbologistica.com. El .htaccess redirige a index.html y evita que se guarde en caché la página principal. |
| **GitHub y Pruebas (QA)** | Repositorio: github.com/varelajoaquin2007-rgb/BackUp_TresReyes. Rama única: main, con 14 commits entre el 21/09/2026 y el 12/11/2026. El repositorio no usa Pull Requests ni Issues: los cambios se subieron directamente a main. El sistema se probó y se usó en la práctica durante más de un mes, y se fue probando cada cambio hasta que funcionara. |
| **Inteligencia Artificial** | Claude Code (Anthropic). Modelo principal: Claude Sonnet 5.5 (claude-sonnet-5-5). Tareas que asistió: desarrollo del código (PHP y JS), documentación y revisión de seguridad. Cómo se probó y validó: el equipo fue probando que todo funcione y lo puso a prueba en uso real durante más de un mes. |
