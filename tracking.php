<?php
require_once 'config.php';
require_once 'lib_zonas.php';
headers();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$input  = getInput();
$action = $input['action'] ?? $_GET['action'] ?? '';

// Posiciones que manda la app Traccar Client en segundo plano (acepta tanto
// el formato de parámetros —id, lat, lon— como el JSON de las versiones
// nuevas). El "id" del dispositivo es el ID del repartidor.
if ($action === 'traccar' || $action === 'traccar_log') {
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=utf-8');
    // Algunas versiones de la app pegan sus datos con "?" aunque la URL ya
    // tenga uno: la clave llega con "?id=..&lat=.." pegado. Se separa.
    $key = (string)($_GET['key'] ?? '');
    if (($q = strpos($key, '?')) !== false) {
        parse_str(substr($key, $q + 1), $extra);
        $key = substr($key, 0, $q);
        $_GET = array_merge($_GET, $extra);
    }
    if (!defined('TRACCAR_KEY') || !hash_equals(TRACCAR_KEY, $key)) {
        http_response_code(403); echo 'clave invalida'; exit();
    }
    $db->query("CREATE TABLE IF NOT EXISTS traccar_log (id INT AUTO_INCREMENT PRIMARY KEY, creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP, estado VARCHAR(40), detalle VARCHAR(300))");

    // Diagnóstico: últimos pedidos que llegaron desde la app.
    if ($action === 'traccar_log') {
        $rows = $db->query('SELECT creado, estado, detalle FROM traccar_log ORDER BY id DESC LIMIT 30')->fetch_all(MYSQLI_ASSOC);
        echo $rows ? implode("\n", array_map(fn($r) => "{$r['creado']}  {$r['estado']}  {$r['detalle']}", $rows)) : 'Todavia no llego ningun pedido de la app';
        exit();
    }

    asegurarColumnasTracking($db);
    $p = array_merge($_GET, $_POST, is_array($input) ? $input : []);
    unset($p['key'], $p['action']);
    $log = function($estado) use ($db, $p) {
        $d = substr(json_encode($p, JSON_UNESCAPED_UNICODE), 0, 290);
        $st = $db->prepare('INSERT INTO traccar_log (estado, detalle) VALUES (?,?)'); $st->bind_param('ss', $estado, $d); $st->execute();
        if (mt_rand(1, 50) === 1) $db->query('DELETE FROM traccar_log WHERE id < (SELECT m FROM (SELECT MAX(id)-100 m FROM traccar_log) t)');
    };

    $rid = intval($p['id'] ?? $p['device_id'] ?? 0);
    $lat = $p['lat'] ?? ($p['location']['coords']['latitude']  ?? null);
    $lon = $p['lon'] ?? ($p['location']['coords']['longitude'] ?? null);
    $acc = floatval($p['accuracy'] ?? ($p['location']['coords']['accuracy'] ?? 0));
    if (!$rid || !is_numeric($lat) || !is_numeric($lon)) { $log('DATOS INVALIDOS'); http_response_code(400); echo 'datos invalidos'; exit(); }
    $lat = floatval($lat); $lon = floatval($lon);

    // Solo se guarda si el repartidor tiene un viaje EN CURSO.
    $st = $db->prepare('SELECT id FROM viajes WHERE repartidor_id=? AND estado="en_curso" ORDER BY id DESC LIMIT 1');
    $st->bind_param('i', $rid); $st->execute();
    $viaje = $st->get_result()->fetch_assoc();
    if (!$viaje) { $log('SIN VIAJE EN CURSO'); echo 'sin viaje en curso'; exit(); }

    $vid = intval($viaje['id']);
    guardarUltimaPosicion($db, $vid, $rid, $lat, $lon, $acc, 'traccar');
    $log('OK');

    // Limpieza: de vez en cuando se borran las posiciones de viajes viejos.
    if (mt_rand(1, 200) === 1) $db->query('DELETE FROM posiciones WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    echo 'OK'; exit();
}

// POST: recalcular la ruta de un viaje desde donde está el camión AHORA
// (lo pide la pantalla de tracking solo cuando el camión se salió del
// trazado guardado). Máximo una vez cada 5 minutos por viaje.
if ($method === 'POST' && $action === 'recalcular_ruta') {
    asegurarColumnasTracking($db);
    $vid = intval($input['viaje_id'] ?? 0);
    $v = $db->query("SELECT orden_paradas, destino_id, destino_final_lat, destino_final_lon, (ruta_calc_at IS NOT NULL AND ruta_calc_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS reciente FROM viajes WHERE id=$vid AND estado='en_curso'")->fetch_assoc();
    if (!$v) jsonResponse(['success' => false, 'message' => 'Viaje no en curso']);
    if ($v['reciente']) jsonResponse(['success' => false, 'message' => 'Recalculado hace poco']);
    $pos = $db->query("SELECT lat, lon FROM posiciones WHERE viaje_id=$vid ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if (!$pos) jsonResponse(['success' => false, 'message' => 'Sin posición']);

    // Reservar el intento ya (aunque falle, no se reintenta antes de 5 min).
    $db->query("UPDATE viajes SET ruta_calc_at=NOW() WHERE id=$vid");

    $bs = $db->query("SELECT id, lat, lon FROM boletas WHERE viaje_id=$vid AND estado<>'entregada' AND lat IS NOT NULL AND lon IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
    $byId = []; foreach ($bs as $b) $byId[$b['id']] = $b;
    $ord = [];
    $orden = json_decode($v['orden_paradas'] ?? '[]', true) ?: [];
    foreach ($orden as $id) if (isset($byId[$id])) { $ord[] = $byId[$id]; unset($byId[$id]); }
    foreach ($byId as $b) $ord[] = $b;
    // Paradas consecutivas en el mismo punto cuentan como una sola.
    $pts = [];
    foreach ($ord as $b) {
        $q = [floatval($b['lat']), floatval($b['lon'])];
        $u = end($pts);
        if (!$u || abs($u[0]-$q[0]) > 0.00005 || abs($u[1]-$q[1]) > 0.00005) $pts[] = $q;
    }
    $dLat = $dLon = null;
    if (!empty($v['destino_final_lat'])) { $dLat = floatval($v['destino_final_lat']); $dLon = floatval($v['destino_final_lon']); }
    elseif (!empty($v['destino_id'])) {
        $dp = $db->query("SELECT lat, lon FROM puntos_finalizacion WHERE id=".intval($v['destino_id']))->fetch_assoc();
        if ($dp && $dp['lat'] !== null) { $dLat = floatval($dp['lat']); $dLon = floatval($dp['lon']); }
    }
    $poly = calcularPolylineRuta(floatval($pos['lat']), floatval($pos['lon']), $pts, $dLat, $dLon);
    if (!$poly) jsonResponse(['success' => false, 'message' => 'No se pudo calcular']);
    guardarRutaViaje($db, $vid, $poly);
    jsonResponse(['success' => true]);
}

// POST: guardar posición del repartidor
if ($method === 'POST' && $action === 'update') {
    $viaje_id      = intval($input['viaje_id']);
    $repartidor_id = intval($input['repartidor_id']);
    $lat           = floatval($input['lat']);
    $lon           = floatval($input['lon']);
    $accuracy      = floatval($input['accuracy'] ?? 0);

    asegurarColumnasTracking($db);
    guardarUltimaPosicion($db, $viaje_id, $repartidor_id, $lat, $lon, $accuracy, 'web');
    jsonResponse(['success' => true]);
}

// POST: registrar alerta de desvío
if ($method === 'POST' && $action === 'desvio') {
    $viaje_id      = intval($input['viaje_id']);
    $repartidor_id = intval($input['repartidor_id']);
    $mensaje       = trim($input['mensaje'] ?? 'Desvío detectado');
    $tipo          = 'desvio';

    // No crear alerta duplicada en los últimos 10 min
    $check = $db->prepare('SELECT id FROM alertas_sistema WHERE viaje_id=? AND tipo="desvio" AND timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $check->bind_param('i', $viaje_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $stmt = $db->prepare('INSERT INTO alertas_sistema (tipo, viaje_id, repartidor_id, mensaje) VALUES (?,?,?,?)');
        $stmt->bind_param('siis', $tipo, $viaje_id, $repartidor_id, $mensaje);
        $stmt->execute();
    }
    jsonResponse(['success' => true]);
}

// GET: posiciones actuales de todos los viajes en curso
if ($method === 'GET' && $action === 'live') {
    asegurarColumnaRuta($db);
    asegurarColumnasTracking($db);
    // Parte de VIAJES en_curso (no de posiciones) para que el viaje
    // aparezca aunque el repartidor aún no haya enviado ningún punto GPS.
    // La última posición se trae como LEFT JOIN opcional.
    $res = $db->query('
        SELECT v.id as viaje_id, v.zona,
               r.id as repartidor_id,
               u.nombre as repartidor,
               c.patente,
               p.lat, p.lon, p.accuracy, p.timestamp, p.fuente,
               (SELECT COUNT(*) FROM boletas WHERE viaje_id=v.id AND estado="entregada") as entregadas,
               (SELECT COUNT(*) FROM boletas WHERE viaje_id=v.id) as total_paradas
        FROM viajes v
        JOIN repartidores r ON r.id = v.repartidor_id
        JOIN usuarios u ON u.id = r.usuario_id
        LEFT JOIN camiones c ON c.id = v.camion_id
        LEFT JOIN posiciones p ON p.id = (SELECT MAX(id) FROM posiciones WHERE viaje_id = v.id)
        WHERE v.estado = "en_curso"
    ');
    $positions = $res->fetch_all(MYSQLI_ASSOC);

    // Para cada viaje en curso, traer sus paradas EN ORDEN con su estado
    // (línea de progreso) y las coordenadas de dónde termina el viaje
    // (el punto de finalización elegido, o la cochera de siempre), para
    // poder mostrar ese punto en el mapa de tracking.
    foreach ($positions as &$p) {
        $vid = intval($p['viaje_id']);
        $v = $db->query("SELECT orden_paradas, ruta_polyline, destino_id, destino_final_lat, destino_final_lon, destino_final_nombre FROM viajes WHERE id=$vid")->fetch_assoc();
        $p['ruta_polyline'] = $v['ruta_polyline'] ?? null;
        $bs = $db->query("SELECT id, cliente, numero_boleta, estado, entregada_at, lat, lon, direccion FROM boletas WHERE viaje_id=$vid")->fetch_all(MYSQLI_ASSOC);
        // Ordenar según orden_paradas (el orden óptimo del viaje).
        if (!empty($v['orden_paradas'])) {
            $orden = json_decode($v['orden_paradas'], true);
            if (is_array($orden)) {
                $byId = [];
                foreach ($bs as $b) $byId[$b['id']] = $b;
                $bsOrd = [];
                foreach ($orden as $bid) if (isset($byId[$bid])) $bsOrd[] = $byId[$bid];
                foreach ($bs as $b) if (!in_array($b['id'], $orden)) $bsOrd[] = $b;
                $bs = $bsOrd;
            }
        }
        $p['paradas'] = $bs;

        // Destino: el punto de finalización elegido, si eligió uno. Si
        // no, queda en null (el viaje termina en la última entrega, no
        // hay ningún punto por defecto).
        $p['destino_lat'] = null;
        $p['destino_lon'] = null;
        $p['destino_nombre'] = null;
        if (!empty($v['destino_final_lat'])) {
            $p['destino_lat']    = floatval($v['destino_final_lat']);
            $p['destino_lon']    = floatval($v['destino_final_lon']);
            $p['destino_nombre'] = $v['destino_final_nombre'] ?? 'Destino';
        } elseif (!empty($v['destino_id'])) {
            $dp = $db->query("SELECT nombre, lat, lon FROM puntos_finalizacion WHERE id=".intval($v['destino_id']))->fetch_assoc();
            if ($dp && $dp['lat'] !== null) {
                $p['destino_lat']    = floatval($dp['lat']);
                $p['destino_lon']    = floatval($dp['lon']);
                $p['destino_nombre'] = $dp['nombre'];
            }
        }
    }
    unset($p);

    jsonResponse(['success' => true, 'positions' => $positions]);
}

// GET: alertas no leídas
if ($method === 'GET' && $action === 'alertas') {
    $res = $db->query('
        SELECT a.*, u.nombre as repartidor, c.patente
        FROM alertas_sistema a
        LEFT JOIN repartidores r ON r.id = a.repartidor_id
        LEFT JOIN usuarios u ON u.id = r.usuario_id
        LEFT JOIN viajes v ON v.id = a.viaje_id
        LEFT JOIN camiones c ON c.id = v.camion_id
        ORDER BY a.timestamp DESC LIMIT 50
    ');
    $alertas = $res->fetch_all(MYSQLI_ASSOC);
    jsonResponse(['success' => true, 'alertas' => $alertas]);
}

// POST: marcar alertas como leídas
if ($method === 'POST' && $action === 'leer') {
    $db->query('UPDATE alertas_sistema SET leida=1 WHERE leida=0');
    jsonResponse(['success' => true]);
}

$db->close();
