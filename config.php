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

define('DB_HOST', 'sql113.infinityfree.com');
define('DB_USER', 'if0_42217493');
define('DB_PASS', 'kmShh7Q1o1f1Ae');
define('DB_NAME', 'if0_42217493_base_datos');

// --- Configuración SMTP (para recuperación de contraseña) ---
// Completá SMTP_PASS con la "Contraseña de aplicación" de 16 letras
// que generaste en myaccount.google.com/apppasswords (sin espacios).
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'proyectointermiami@gmail.com');
define('SMTP_PASS', 'sntysgoitbgkpjnx');
define('SMTP_FROM_NAME', 'Tres Reyes');

// Geocodificación y optimización de rutas (dirección → coordenadas,
// orden óptimo de paradas): key del SERVIDOR. Restringida por API a
// Geocoding + Routes, sin restricción de dominio (la usa el PHP, nunca
// se ve en el navegador).
define('GOOGLE_GEOCODING_KEY', 'AIzaSyAq7Me_OOtsjjh0Pf0nm0XomMfc6DeLS2o');
define('GOOGLE_ROUTES_KEY', 'AIzaSyAq7Me_OOtsjjh0Pf0nm0XomMfc6DeLS2o');
// Mapa del panel (Maps JavaScript): key del NAVEGADOR. Viaja al
// navegador; se protege con restricción por dominio en Google Cloud.
define('GOOGLE_MAPS_KEY', 'AIzaSyCO5nKrATJRFBlYvyO1_ZQLSs2w7MDoI1Q');

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

// El mail de "recuperar contraseña" SIEMPRE se manda a esta casilla,
// sin importar qué usuario/email haya pedido el reseteo. Así solo vos
// podés completar el cambio de contraseña, nunca otro empleado.
define('ADMIN_RECOVERY_EMAIL', 'proyectointermiami@gmail.com');

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
