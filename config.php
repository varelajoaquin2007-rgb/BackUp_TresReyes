<?php
// Nunca mostrar advertencias/notices de PHP en la respuesta: si algo
// imprime texto antes del JSON, el navegador no puede interpretar la
// respuesta y el login (o cualquier otra pantalla) tira "Error del
// servidor". Los errores se siguen registrando en el log del hosting,
// solo que no se mezclan con la respuesta que ve el usuario.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Zona horaria de Argentina para TODO el sistema: si esto no se fija acá,
// el servidor (InfinityFree) usa UTC por defecto, y como el navegador
// calcula "hoy" con la hora local (Argentina, UTC-3), un viaje cargado
// de noche puede quedar con una fecha distinta a la que el servidor
// considera "hoy" — y por eso el repartidor no lo veía en su lista.
date_default_timezone_set('America/Argentina/Buenos_Aires');

define('DB_HOST', 'TU_HOST_MYSQL');
define('DB_USER', 'TU_USUARIO_MYSQL');
define('DB_PASS', 'TU_CLAVE_MYSQL');
define('DB_NAME', 'TU_BASE_DE_DATOS');

// --- Configuración SMTP (para recuperación de contraseña) ---
// Completá SMTP_PASS con la "Contraseña de aplicación" de 16 letras
// que generaste en myaccount.google.com/apppasswords (sin espacios).
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'tu_correo@gmail.com');
define('SMTP_PASS', 'CONTRASEÑA_DE_APLICACION_GMAIL');
define('SMTP_FROM_NAME', 'Tres Reyes');

// Geocodificación y optimización de rutas (dirección → coordenadas,
// orden óptimo de paradas): key del SERVIDOR. Restringida por API a
// Geocoding + Routes, sin restricción de dominio (la usa el PHP, nunca
// se ve en el navegador).
define('GOOGLE_GEOCODING_KEY', 'TU_KEY_DE_SERVIDOR');
define('GOOGLE_ROUTES_KEY', 'TU_KEY_DE_SERVIDOR');
// Mapa del panel (Maps JavaScript): key del NAVEGADOR. Viaja al
// navegador; se protege con restricción por dominio en Google Cloud.
define('GOOGLE_MAPS_KEY', 'TU_KEY_DE_NAVEGADOR');

// Ubicación de la fábrica (San Miguel) — punto de partida de todos los
// repartos. Se usa como límite de sanidad al geocodificar: si un
// resultado cae absurdamente lejos de acá, es señal de que el mapa
// agarró una calle homónima en otra parte del país, no de que la
// entrega esté realmente ahí.
define('FABRICA_LAT', -34.547362);
define('FABRICA_LON', -58.709439);
// NOTA: ya no hay un punto de finalización fijo por defecto ("cochera").
// Los puntos de finalización se cargan a mano en Configuración → Destinos
// y se eligen viaje por viaje al confirmar. Sin ninguno elegido, el
// viaje termina en la última entrega (ver optimizarOrdenRuta en
// lib_zonas.php).

// Clave secreta de la app Traccar Client (seguimiento GPS en segundo
// plano). Va en la URL del servidor que se carga en la app; sin ella
// tracking.php rechaza las posiciones, para que nadie mande ubicaciones
// falsas. Para cambiarla: poné otro valor acá y actualizá la URL en la app.
define('TRACCAR_KEY', 'CLAVE_SECRETA_LARGA_ALEATORIA');

// El mail de "recuperar contraseña" SIEMPRE se manda a esta casilla,
// sin importar qué usuario/email haya pedido el reseteo. Así solo vos
// podés completar el cambio de contraseña, nunca otro empleado.
define('ADMIN_RECOVERY_EMAIL', 'admin@tudominio.com');

function getDB() {
    // Restaura el comportamiento clásico de mysqli (sin excepciones):
    // en PHP 8.1+ mysqli tira excepciones por defecto ante cualquier error
    // de SQL, lo que puede cortar la respuesta JSON de los endpoints.
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Error BD: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');
    // La sesión de MySQL también debe usar horario Argentina, para que
    // CURDATE()/NOW() coincidan con lo que calcula PHP arriba. Con @ para
    // que, si el hosting no permite este comando (pasa en algunos
    // hostings compartidos), no rompa la respuesta JSON del endpoint.
    @$conn->query("SET time_zone = '-03:00'");
    return $conn;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function headers() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
}

function getInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

