<?php
require_once 'config.php';
require_once 'lib_zonas.php';
headers();

$db     = getDB();
$input  = getInput();
$action = $input['action'] ?? '';

// Prepara una consulta y devuelve un error JSON legible si falla, en vez
// de romper el script (lo que antes hacía que el fetch() del navegador
// recibiera una respuesta vacía/no-JSON y el botón "no hiciera nada").
function prep($db, $sql) {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        jsonResponse(['success'=>false,'message'=>'Error de base de datos: '.$db->error], 500);
    }
    return $stmt;
}

// ---- NUEVO REPARTIDOR ----
if ($action === 'nuevo_repartidor') {
    $nombre   = trim($input['nombre']   ?? '');
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $camion   = !empty($input['camion_id']) ? intval($input['camion_id']) : null;
    $usuario  = strtolower(explode('@', $email)[0]);

    if (!$nombre || !$email || !$password)
        jsonResponse(['success'=>false,'message'=>'Completá todos los campos']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        jsonResponse(['success'=>false,'message'=>'Email inválido']);
    if (strlen($password) < 6)
        jsonResponse(['success'=>false,'message'=>'La contraseña debe tener al menos 6 caracteres']);

    $check = prep($db, 'SELECT id FROM usuarios WHERE email=? LIMIT 1');
    $check->bind_param('s', $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0)
        jsonResponse(['success'=>false,'message'=>'Ya existe un usuario con ese email']);

    // Asegurar username único
    $base = $usuario; $i = 1;
    while (true) {
        $c = prep($db, 'SELECT id FROM usuarios WHERE usuario=? LIMIT 1');
        $c->bind_param('s', $usuario); $c->execute();
        if ($c->get_result()->num_rows === 0) break;
        $usuario = $base . $i++;
    }

    // Hashear contraseña
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $rol    = 'repartidor';

    $stmt = prep($db, 'INSERT INTO usuarios (nombre, usuario, email, password, rol) VALUES (?,?,?,?,?)');
    $stmt->bind_param('sssss', $nombre, $usuario, $email, $hashed, $rol);
    $stmt->execute();
    $user_id = $db->insert_id;

    $stmt2 = prep($db, 'INSERT INTO repartidores (usuario_id, camion_id) VALUES (?,?)');
    $stmt2->bind_param('ii', $user_id, $camion);
    $stmt2->execute();

    jsonResponse(['success'=>true,'message'=>'Repartidor creado']);
}

// ---- EDITAR REPARTIDOR (datos + camión asignado + contraseña opcional) ----
if ($action === 'editar_repartidor') {
    $id       = intval($input['id']);
    $camion   = $input['camion_id'] ? intval($input['camion_id']) : null;
    $nombre   = trim($input['nombre'] ?? '');
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    $rep = $db->query("SELECT usuario_id FROM repartidores WHERE id=$id")->fetch_assoc();
    if (!$rep) jsonResponse(['success'=>false,'message'=>'Repartidor no encontrado']);
    $uid = intval($rep['usuario_id']);

    if ($nombre || $email) {
        $cur = $db->query("SELECT nombre, email FROM usuarios WHERE id=$uid")->fetch_assoc();
        $nombreF = $nombre ?: $cur['nombre'];
        $emailF  = $email  ?: $cur['email'];
        if (!filter_var($emailF, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success'=>false,'message'=>'Email inválido']);
        $check = prep($db, 'SELECT id FROM usuarios WHERE email=? AND id<>? LIMIT 1');
        $check->bind_param('si', $emailF, $uid);
        $check->execute();
        if ($check->get_result()->num_rows > 0)
            jsonResponse(['success'=>false,'message'=>'Ya existe otro usuario con ese email']);
        $stmt = prep($db, 'UPDATE usuarios SET nombre=?, email=? WHERE id=?');
        $stmt->bind_param('ssi', $nombreF, $emailF, $uid);
        $stmt->execute();
    }

    if ($password !== '') {
        if (strlen($password) < 6)
            jsonResponse(['success'=>false,'message'=>'La contraseña debe tener al menos 6 caracteres']);
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = prep($db, 'UPDATE usuarios SET password=? WHERE id=?');
        $stmt->bind_param('si', $hashed, $uid);
        $stmt->execute();
    }

    $stmt = prep($db, 'UPDATE repartidores SET camion_id=? WHERE id=?');
    $stmt->bind_param('ii', $camion, $id);
    $stmt->execute();
    jsonResponse(['success'=>true,'message'=>'Repartidor actualizado']);
}

// ---- ELIMINAR REPARTIDOR ----
if ($action === 'eliminar_repartidor') {
    $id     = intval($input['id']);
    $forzar = !empty($input['forzar']);
    $rep = $db->query("SELECT usuario_id FROM repartidores WHERE id=$id")->fetch_assoc();
    if (!$rep) jsonResponse(['success'=>false,'message'=>'Repartidor no encontrado']);

    // Solo bloquear si tiene un viaje REALMENTE vigente: en curso (manejando
    // ahora mismo) o confirmado para hoy/a futuro. Un viaje "confirmado" de
    // una fecha ya pasada es un registro viejo (típicamente de pruebas) y
    // no debería impedir borrar al repartidor.
    $activo = $db->query("
        SELECT id, zona, estado, fecha FROM viajes
        WHERE repartidor_id=$id
          AND (estado='en_curso' OR (estado='confirmado' AND fecha >= CURDATE()))
        ORDER BY id DESC LIMIT 1
    ")->fetch_assoc();

    if ($activo && !$forzar) {
        $estadoTxt = $activo['estado'] === 'en_curso' ? 'en curso ahora mismo' : "confirmado para el {$activo['fecha']}";
        jsonResponse([
            'success' => false,
            'requiere_confirmacion' => true,
            'message' => "Tiene el viaje #{$activo['id']} (Zona {$activo['zona']}) $estadoTxt. ¿Eliminar igual?",
        ]);
    }

    // Se fuerza la baja: el viaje NO se borra ni se cancela (sigue su
    // curso / queda en el historial), solo se desvincula de este
    // repartidor para poder eliminarlo.
    if ($activo && $forzar) {
        $db->query("UPDATE viajes SET repartidor_id=NULL WHERE repartidor_id=$id AND (estado='en_curso' OR estado='confirmado')");
    }

    $uid = intval($rep['usuario_id']);
    $db->query("DELETE FROM repartidores WHERE id=$id");
    $db->query("DELETE FROM usuarios WHERE id=$uid");
    jsonResponse(['success'=>true,'message'=>'Repartidor eliminado']);
}

// ---- ESTADO CAMIÓN ----
if ($action === 'estado_camion') {
    $id     = intval($input['id']);
    $estado = $input['estado'] ?? 'disponible';
    if (!in_array($estado, ['disponible','en_viaje','mantenimiento']))
        jsonResponse(['success'=>false,'message'=>'Estado inválido']);
    $stmt = prep($db, 'UPDATE camiones SET estado=? WHERE id=?');
    $stmt->bind_param('si', $estado, $id);
    $stmt->execute();
    jsonResponse(['success'=>true]);
}

// ---- NUEVO CAMIÓN ----
if ($action === 'nuevo_camion') {
    $patente   = strtoupper(trim($input['patente'] ?? ''));
    $marca     = trim($input['marca']  ?? '');
    $modelo    = trim($input['modelo'] ?? '');
    $capacidad = floatval($input['capacidad_kg'] ?? 0);

    if (!$patente || $capacidad <= 0)
        jsonResponse(['success'=>false,'message'=>'Completá al menos patente y capacidad (kg)']);

    $check = prep($db, 'SELECT id FROM camiones WHERE patente=? LIMIT 1');
    $check->bind_param('s', $patente);
    $check->execute();
    if ($check->get_result()->num_rows > 0)
        jsonResponse(['success'=>false,'message'=>'Ya existe un camión con esa patente']);

    $estado = 'disponible';
    $stmt = prep($db, 'INSERT INTO camiones (patente, marca, modelo, capacidad_kg, estado) VALUES (?,?,?,?,?)');
    $stmt->bind_param('sssds', $patente, $marca, $modelo, $capacidad, $estado);
    if (!$stmt->execute()) {
        jsonResponse(['success'=>false,'message'=>'No se pudo guardar el camión: '.$stmt->error], 500);
    }
    jsonResponse(['success'=>true,'message'=>'Camión agregado','id'=>$db->insert_id]);
}

// ---- EDITAR CAMIÓN ----
if ($action === 'editar_camion') {
    $id        = intval($input['id']);
    $patente   = strtoupper(trim($input['patente'] ?? ''));
    $marca     = trim($input['marca']  ?? '');
    $modelo    = trim($input['modelo'] ?? '');
    $capacidad = floatval($input['capacidad_kg'] ?? 0);

    if (!$id || !$patente || $capacidad <= 0)
        jsonResponse(['success'=>false,'message'=>'Completá al menos patente y capacidad (kg)']);

    $check = prep($db, 'SELECT id FROM camiones WHERE patente=? AND id<>? LIMIT 1');
    $check->bind_param('si', $patente, $id);
    $check->execute();
    if ($check->get_result()->num_rows > 0)
        jsonResponse(['success'=>false,'message'=>'Ya existe otro camión con esa patente']);

    $stmt = prep($db, 'UPDATE camiones SET patente=?, marca=?, modelo=?, capacidad_kg=? WHERE id=?');
    $stmt->bind_param('sssdi', $patente, $marca, $modelo, $capacidad, $id);
    if (!$stmt->execute()) {
        jsonResponse(['success'=>false,'message'=>'No se pudo actualizar el camión: '.$stmt->error], 500);
    }
    jsonResponse(['success'=>true,'message'=>'Camión actualizado']);
}

// ---- ELIMINAR CAMIÓN ----
if ($action === 'eliminar_camion') {
    $id     = intval($input['id']);
    $forzar = !empty($input['forzar']);
    $cam = $db->query("SELECT estado FROM camiones WHERE id=$id")->fetch_assoc();
    if (!$cam) jsonResponse(['success'=>false,'message'=>'Camión no encontrado']);

    $activo = $db->query("
        SELECT id, zona, estado, fecha FROM viajes
        WHERE camion_id=$id AND estado IN ('confirmado','en_curso')
        ORDER BY id DESC LIMIT 1
    ")->fetch_assoc();

    if ($activo && !$forzar) {
        $estadoTxt = $activo['estado'] === 'en_curso' ? 'en curso ahora mismo' : "confirmado para el {$activo['fecha']}";
        jsonResponse([
            'success' => false,
            'requiere_confirmacion' => true,
            'message' => "Tiene el viaje #{$activo['id']} (Zona {$activo['zona']}) $estadoTxt. ¿Eliminar igual?",
        ]);
    }

    // Se fuerza la baja: el viaje sigue existiendo (no se pierde el
    // historial ni se cancela), solo se desvincula de este camión.
    if ($activo && $forzar) {
        $db->query("UPDATE viajes SET camion_id=NULL WHERE camion_id=$id AND estado IN ('confirmado','en_curso')");
    }

    $db->query("UPDATE repartidores SET camion_id=NULL WHERE camion_id=$id");
    $db->query("DELETE FROM camiones WHERE id=$id");
    jsonResponse(['success'=>true,'message'=>'Camión eliminado']);
}

// ---- LISTAR PUNTOS DE FINALIZACIÓN ----
if ($action === 'listar_destinos') {
    $rows = $db->query('SELECT * FROM puntos_finalizacion ORDER BY nombre ASC')->fetch_all(MYSQLI_ASSOC);
    jsonResponse(['success'=>true,'destinos'=>$rows]);
}

// ---- NUEVO PUNTO DE FINALIZACIÓN ----
// Se geocodifica la dirección al crearlo, así el punto ya queda listo
// para usarse en el cálculo de rutas sin gastar una consulta cada vez.
if ($action === 'nuevo_destino') {
    $nombre    = trim($input['nombre'] ?? '');
    $calle     = trim($input['calle'] ?? '');
    $altura    = trim($input['altura'] ?? '');
    $localidad = trim($input['localidad'] ?? '');
    $provincia = trim($input['provincia'] ?? '');
    $cp        = trim($input['cp'] ?? '');

    if (!$nombre || !$calle || !$altura || !$localidad || !$provincia) {
        jsonResponse(['success'=>false,'message'=>'Completá nombre, calle, altura, localidad y provincia']);
    }

    $direccionCompleta = "$calle $altura" . ($cp ? ", $cp" : '') . ", $localidad, $provincia, Argentina";
    $geo = buscarGoogleGeocoding($direccionCompleta, null);
    $lat = null; $lon = null;
    if (!empty($geo['candidatos'])) {
        $lat = floatval($geo['candidatos'][0]['lat']);
        $lon = floatval($geo['candidatos'][0]['lon']);
    }

    $stmt = prep($db, 'INSERT INTO puntos_finalizacion (nombre, calle, altura, localidad, provincia, cp, lat, lon) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->bind_param('ssssssdd', $nombre, $calle, $altura, $localidad, $provincia, $cp, $lat, $lon);
    $stmt->execute();

    if ($lat === null) {
        jsonResponse(['success'=>true,'id'=>$db->insert_id,'message'=>'Guardado, pero no se pudo ubicar la dirección en el mapa — revisá que esté bien escrita. Podés editarlo después.']);
    }
    jsonResponse(['success'=>true,'id'=>$db->insert_id,'message'=>'Punto de finalización guardado y ubicado en el mapa.']);
}

// ---- EDITAR PUNTO DE FINALIZACIÓN ----
if ($action === 'editar_destino') {
    $id        = intval($input['id'] ?? 0);
    $nombre    = trim($input['nombre'] ?? '');
    $calle     = trim($input['calle'] ?? '');
    $altura    = trim($input['altura'] ?? '');
    $localidad = trim($input['localidad'] ?? '');
    $provincia = trim($input['provincia'] ?? '');
    $cp        = trim($input['cp'] ?? '');
    if (!$id || !$nombre || !$calle || !$altura || !$localidad || !$provincia) {
        jsonResponse(['success'=>false,'message'=>'Completá todos los campos']);
    }

    // Re-geocodificar con la dirección nueva (puede haber cambiado).
    $direccionCompleta = "$calle $altura" . ($cp ? ", $cp" : '') . ", $localidad, $provincia, Argentina";
    $geo = buscarGoogleGeocoding($direccionCompleta, null);
    $lat = null; $lon = null;
    if (!empty($geo['candidatos'])) {
        $lat = floatval($geo['candidatos'][0]['lat']);
        $lon = floatval($geo['candidatos'][0]['lon']);
    }

    $stmt = prep($db, 'UPDATE puntos_finalizacion SET nombre=?, calle=?, altura=?, localidad=?, provincia=?, cp=?, lat=?, lon=? WHERE id=?');
    $stmt->bind_param('ssssssddi', $nombre, $calle, $altura, $localidad, $provincia, $cp, $lat, $lon, $id);
    $stmt->execute();
    jsonResponse(['success'=>true,'message'=>$lat===null ? 'Guardado, pero no se pudo ubicar la nueva dirección en el mapa.' : 'Punto de finalización actualizado.']);
}

// ---- ELIMINAR PUNTO DE FINALIZACIÓN ----
if ($action === 'eliminar_destino') {
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID requerido']);
    // Los viajes que ya lo tenían elegido vuelven a terminar en la
    // cochera de siempre (no se pierde el viaje, solo el destino).
    $db->query("UPDATE viajes SET destino_id=NULL WHERE destino_id=$id");
    $db->query("DELETE FROM puntos_finalizacion WHERE id=$id");
    jsonResponse(['success'=>true,'message'=>'Punto de finalización eliminado. Los viajes que lo tenían vuelven a terminar en la cochera.']);
}

jsonResponse(['success'=>false,'message'=>'Acción no reconocida'], 400);
$db->close();
