<?php
require_once 'config.php';
headers();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$input  = getInput();
$action = $input['action'] ?? $_GET['action'] ?? '';

// POST: guardar posición del repartidor
if ($method === 'POST' && $action === 'update') {
    $viaje_id      = intval($input['viaje_id']);
    $repartidor_id = intval($input['repartidor_id']);
    $lat           = floatval($input['lat']);
    $lon           = floatval($input['lon']);
    $accuracy      = floatval($input['accuracy'] ?? 0);

    $stmt = $db->prepare('INSERT INTO posiciones (viaje_id, repartidor_id, lat, lon, accuracy) VALUES (?,?,?,?,?)');
    $stmt->bind_param('iiddd', $viaje_id, $repartidor_id, $lat, $lon, $accuracy);
    $stmt->execute();
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
    $res = $db->query('
        SELECT p.viaje_id, p.repartidor_id, p.lat, p.lon, p.accuracy, p.timestamp,
               u.nombre as repartidor, c.patente, v.zona,
               (SELECT COUNT(*) FROM boletas WHERE viaje_id=p.viaje_id AND estado="entregada") as entregadas,
               (SELECT COUNT(*) FROM boletas WHERE viaje_id=p.viaje_id) as total_paradas
        FROM posiciones p
        JOIN viajes v ON v.id = p.viaje_id
        JOIN repartidores r ON r.id = p.repartidor_id
        JOIN usuarios u ON u.id = r.usuario_id
        LEFT JOIN camiones c ON c.id = v.camion_id
        WHERE v.estado = "en_curso"
        AND p.id = (SELECT MAX(id) FROM posiciones WHERE viaje_id = p.viaje_id)
        GROUP BY p.viaje_id
    ');
    $positions = $res->fetch_all(MYSQLI_ASSOC);

    // Para cada viaje en curso, traer sus paradas EN ORDEN con su estado
    // (línea de progreso) y las coordenadas de dónde termina el viaje
    // (el punto de finalización elegido, o la cochera de siempre), para
    // poder mostrar ese punto en el mapa de tracking.
    foreach ($positions as &$p) {
        $vid = intval($p['viaje_id']);
        $v = $db->query("SELECT orden_paradas, destino_id FROM viajes WHERE id=$vid")->fetch_assoc();
        $bs = $db->query("SELECT id, cliente, estado, entregada_at, lat, lon, direccion FROM boletas WHERE viaje_id=$vid")->fetch_all(MYSQLI_ASSOC);
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
        if (!empty($v['destino_id'])) {
            $dp = $db->query("SELECT nombre, lat, lon FROM puntos_finalizacion WHERE id=".intval($v['destino_id']))->fetch_assoc();
            if ($dp && $dp['lat'] !== null) {
                $p['destino_lat'] = floatval($dp['lat']);
                $p['destino_lon'] = floatval($dp['lon']);
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
