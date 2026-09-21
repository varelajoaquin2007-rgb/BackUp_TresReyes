<?php
require_once 'config.php';
require_once 'lib_zonas.php';
headers();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

// GET - listar viajes
if ($method === 'GET') {
    liberarCamionesTrabados($db);
    $fecha = $_GET['fecha'] ?? date('Y-m-d');
    $repartidor_id = isset($_GET['repartidor_id']) ? intval($_GET['repartidor_id']) : null;
    $historico     = isset($_GET['historico']) && $_GET['historico'] == '1';

    if ($historico) {
        // Histórico: TODOS los viajes (finalizados, en curso, confirmados),
        // sin filtrar por fecha, para la pestaña de administrador.
        $stmt = $db->prepare('
            SELECT v.*, c.patente, c.modelo, u.nombre as repartidor_nombre,
                   COALESCE(v.km_estimado, 0) as km_estimado
            FROM viajes v
            LEFT JOIN camiones c ON c.id = v.camion_id
            LEFT JOIN repartidores r ON r.id = v.repartidor_id
            LEFT JOIN usuarios u ON u.id = r.usuario_id
            WHERE v.estado <> "armado"
            ORDER BY v.id DESC
            LIMIT 300
        ');
    } elseif ($repartidor_id) {
        // Vista repartidor: cualquier viaje que todavía esté "vivo" para él
        // (confirmado o en curso) SIEMPRE tiene que aparecer, sin importar
        // la fecha guardada — así un desfasaje de horario no lo esconde.
        // El historial de finalizados sí se limita a las últimas 48 hs.
        $stmt = $db->prepare('
            SELECT v.*, c.patente, c.modelo, u.nombre as repartidor_nombre
            FROM viajes v
            LEFT JOIN camiones c ON c.id = v.camion_id
            LEFT JOIN repartidores r ON r.id = v.repartidor_id
            LEFT JOIN usuarios u ON u.id = r.usuario_id
            WHERE (v.repartidor_id = ? OR v.repartidor_id IS NULL)
              AND (
                    v.estado IN ("confirmado","en_curso")
                 OR (v.estado = "finalizado" AND v.finished_at >= NOW() - INTERVAL 48 HOUR)
              )
            ORDER BY v.fecha DESC, v.id DESC
        ');
        $stmt->bind_param('i', $repartidor_id);
    } else {
        // Vista admin
        $reporte = isset($_GET['reporte']) && $_GET['reporte'] == '1';
        $hasta   = $_GET['hasta'] ?? $fecha;
        if ($reporte) {
            $stmt = $db->prepare('
                SELECT v.*, c.patente, c.modelo, u.nombre as repartidor_nombre,
                       COALESCE(v.km_estimado, 0) as km_estimado
                FROM viajes v
                LEFT JOIN camiones c ON c.id = v.camion_id
                LEFT JOIN repartidores r ON r.id = v.repartidor_id
                LEFT JOIN usuarios u ON u.id = r.usuario_id
                WHERE v.fecha BETWEEN ? AND ? AND v.estado = "finalizado"
                ORDER BY v.fecha DESC, v.id DESC
            ');
            $stmt->bind_param('ss', $fecha, $hasta);
        } else {
            $stmt = $db->prepare('
                SELECT v.*, c.patente, c.modelo, u.nombre as repartidor_nombre,
                       COALESCE(v.km_estimado, 0) as km_estimado
                FROM viajes v
                LEFT JOIN camiones c ON c.id = v.camion_id
                LEFT JOIN repartidores r ON r.id = v.repartidor_id
                LEFT JOIN usuarios u ON u.id = r.usuario_id
                WHERE v.fecha = ?
                ORDER BY v.id DESC
            ');
            $stmt->bind_param('s', $fecha);
        }
    }

    $stmt->execute();
    $viajes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Para cada viaje traer sus boletas YA EN EL ORDEN ÓPTIMO
    // (orden_paradas) — antes se traían ordenadas por ID de carga, que
    // no tiene nada que ver con el orden real de la ruta. Esto pasaba
    // justo en la lista que ve el repartidor: podía marcar como
    // entregada una parada que no era la que tenía adelante en Maps.
    foreach ($viajes as &$v) {
        $vc = obtenerViajeConBoletas($db, $v['id']);
        $v['boletas'] = $vc['boletas'] ?? [];
        $v['destino_lat'] = $vc['destino_lat'] ?? null;
        $v['destino_lon'] = $vc['destino_lon'] ?? null;
        $v['destino_nombre'] = $vc['destino_nombre'] ?? 'Cochera';
        if ($v['orden_paradas']) {
            $v['orden_paradas'] = json_decode($v['orden_paradas'], true);
        }
    }

    jsonResponse(['success'=>true,'viajes'=>$viajes]);
}

if ($method === 'POST') {
    $input  = getInput();
    $action = $input['action'] ?? '';

    // Sincronizar/armar hojas de ruta por zona (una por zona pendiente)
    if ($action === 'armar') {
        // Libera camiones que quedaron trabados (no están realmente en viaje)
        liberarCamionesTrabados($db);

        // Agrupa TODAS las boletas pendientes por zona: si la zona ya tenía
        // un viaje armado (de hoy o de cualquier día anterior sin salir) le
        // suma las boletas nuevas; si no, crea uno.
        sincronizarZonasPendientes($db);

        // Devuelve TODOS los viajes pendientes de confirmar (uno por zona),
        // sin importar qué día se empezaron a armar.
        $viajes = $db->query("SELECT * FROM viajes WHERE estado='armado' ORDER BY zona ASC")->fetch_all(MYSQLI_ASSOC);

        foreach ($viajes as &$v) {
            $vc = obtenerViajeConBoletas($db, $v['id']);
            $v['boletas'] = $vc['boletas'];
        }
        unset($v);

        if (empty($viajes)) {
            jsonResponse(['success'=>true,'viajes'=>[],'message'=>'No hay boletas pendientes para armar viajes']);
        }

        jsonResponse(['success'=>true,'viajes'=>$viajes]);
    }

    // Confirmar viaje(s): el admin elige el camión (y opcionalmente el
    // repartidor) para la zona que va a salir. El viaje NO se borra: pasa
    // a "confirmado" y queda visible en Viajes del día hasta que finalice.
    // Las demás zonas armadas (pendientes) quedan intactas para otro día.
    // Se puede mandar más de una asignación en la misma llamada para
    // confirmar 2 (o más) zonas juntas CON EL MISMO CAMIÓN el mismo día
    // (ej. zonas cercanas que conviene hacer en un solo recorrido).
    if ($action === 'confirmar') {
        $asignaciones = $input['asignaciones'] ?? [];
        if (empty($asignaciones) && isset($input['viaje_id'])) {
            $asignaciones = [[
                'viaje_id'      => $input['viaje_id'],
                'camion_id'     => $input['camion_id'] ?? null,
                'repartidor_id' => $input['repartidor_id'] ?? null,
            ]];
        }

        // Se valida la disponibilidad de los camiones UNA sola vez, con la
        // foto de "antes" de esta confirmación.
        $camionIds = array_values(array_unique(array_filter(array_map(
            fn($a) => intval($a['camion_id'] ?? 0), $asignaciones
        ))));
        $disponibles = [];
        if ($camionIds) {
            $ids = implode(',', $camionIds);
            $res = $db->query("SELECT id FROM camiones WHERE id IN ($ids) AND estado='disponible'");
            while ($row = $res->fetch_assoc()) $disponibles[intval($row['id'])] = true;
        }

        // Agrupar las asignaciones por camión: si 2+ zonas comparten el
        // mismo camión, se UNIFICAN en un solo viaje antes de confirmar
        // — todas las boletas pasan a un único registro, con una sola
        // ruta optimizada de punta a punta. Así "Viajes del día" y el
        // "Histórico" ya los muestran unificados solos, sin nada aparte,
        // porque a partir de acá es literalmente un solo viaje en la base.
        $porCamion = [];
        foreach ($asignaciones as $a) {
            $cid = !empty($a['camion_id']) ? intval($a['camion_id']) : 0;
            $porCamion[$cid][] = $a;
        }

        $resultados = [];
        foreach ($porCamion as $cid => $grupo) {
            if (!$cid) {
                foreach ($grupo as $a) $resultados[] = ['viaje_id'=>intval($a['viaje_id']??0),'success'=>false,'message'=>'Elegí el camión que va a salir'];
                continue;
            }
            if (!isset($disponibles[$cid])) {
                foreach ($grupo as $a) $resultados[] = ['viaje_id'=>intval($a['viaje_id']??0),'success'=>false,'message'=>'Ese camión ya no está disponible'];
                continue;
            }

            $rid = !empty($grupo[0]['repartidor_id']) ? intval($grupo[0]['repartidor_id']) : null;
            // Punto de finalización elegido (uno solo compartido por todo
            // el grupo, igual que el camión). Si no se eligió ninguno,
            // el viaje termina en la cochera de siempre (destino_id NULL).
            $destinoId = !empty($grupo[0]['destino_id']) ? intval($grupo[0]['destino_id']) : null;
            $destLat = null; $destLon = null;
            if ($destinoId) {
                $dp = $db->query("SELECT lat, lon FROM puntos_finalizacion WHERE id=$destinoId")->fetch_assoc();
                if ($dp && $dp['lat'] !== null) { $destLat = floatval($dp['lat']); $destLon = floatval($dp['lon']); }
            }
            $viajeIds = array_values(array_unique(array_filter(array_map(fn($a)=>intval($a['viaje_id']??0), $grupo))));
            if (!$viajeIds) continue;

            $vidPrimario = $viajeIds[0];
            $secundarios = array_slice($viajeIds, 1);

            if ($secundarios) {
                // Armar la etiqueta de zona combinada (ej. "1 + 3") ANTES
                // de mover nada, mientras todavía existen los viajes
                // secundarios con su propio dato de zona.
                $todosIds = implode(',', $viajeIds);
                $zonasRows = $db->query("SELECT id, zona FROM viajes WHERE id IN ($todosIds)")->fetch_all(MYSQLI_ASSOC);
                $zonasPorId = [];
                foreach ($zonasRows as $zr) $zonasPorId[$zr['id']] = $zr['zona'];
                $zonaUnificada = implode(' + ', array_values(array_unique(array_filter(array_map(fn($id)=>$zonasPorId[$id]??null, $viajeIds)))));

                $secIds = implode(',', $secundarios);
                $db->query("UPDATE boletas SET viaje_id=$vidPrimario WHERE viaje_id IN ($secIds)");
                $db->query("DELETE FROM viajes WHERE id IN ($secIds)");
                if ($zonaUnificada !== '') {
                    $zonaEsc = $db->real_escape_string($zonaUnificada);
                    $db->query("UPDATE viajes SET zona='$zonaEsc' WHERE id=$vidPrimario");
                }
            }

            if ($destinoId) {
                $db->query("UPDATE viajes SET destino_id=$destinoId WHERE id=$vidPrimario");
            }

            $stmt = $db->prepare('UPDATE viajes SET estado="confirmado", camion_id=?, repartidor_id=?, fecha=CURDATE() WHERE id=? AND estado="armado"');
            $stmt->bind_param('iii', $cid, $rid, $vidPrimario);
            $stmt->execute();
            if ($db->affected_rows === 0) {
                foreach ($viajeIds as $vid) $resultados[] = ['viaje_id'=>$vid,'success'=>false,'message'=>'El viaje ya no está pendiente de confirmar'];
                continue;
            }

            $db->query("UPDATE camiones SET estado='en_viaje' WHERE id=$cid");

            // Optimizar el orden de TODAS las paradas juntas (las de las
            // zonas unificadas incluidas) — una sola ruta de punta a
            // punta, no una por zona. Termina en el punto de finalización
            // elegido (o la cochera, si no se eligió ninguno).
            $bs = $db->query("SELECT id, lat, lon, peso_kg FROM boletas WHERE viaje_id=$vidPrimario")->fetch_all(MYSQLI_ASSOC);
            $peso_total = array_sum(array_column($bs, 'peso_kg'));
            $ordenOptimo = optimizarOrdenRuta($bs, null, null, $destLat, $destLon);
            if ($ordenOptimo) {
                $orden_json = json_encode($ordenOptimo);
                $st = $db->prepare('UPDATE viajes SET orden_paradas=?, peso_total=? WHERE id=?');
                $st->bind_param('sdi', $orden_json, $peso_total, $vidPrimario);
                $st->execute();
            } else {
                $st = $db->prepare('UPDATE viajes SET peso_total=? WHERE id=?');
                $st->bind_param('di', $peso_total, $vidPrimario);
                $st->execute();
            }

            foreach ($viajeIds as $vid) {
                $resultados[] = $vid === $vidPrimario
                    ? ['viaje_id'=>$vid, 'success'=>true]
                    : ['viaje_id'=>$vid, 'success'=>true, 'message'=>"Unificado con el viaje #$vidPrimario"];
            }
        }

        $ok = count(array_filter($resultados, fn($r) => $r['success']));
        jsonResponse([
            'success'    => $ok > 0,
            'message'    => $ok > 0 ? "$ok viaje(s) confirmado(s). Los repartidores ya pueden verlos." : ($resultados[0]['message'] ?? 'No se pudo confirmar'),
            'resultados' => $resultados,
        ]);
    }

    // Eliminar un viaje TODAVÍA PENDIENTE de confirmar (zona "armada"),
    // junto con sus boletas. A diferencia de "cancelar" (que se usa con
    // viajes ya confirmados/en curso y devuelve las boletas a pendiente
    // para reasignarlas solas), acá se borran directamente: sirve para
    // sacar boletas de prueba o mal cargadas (ej. sin zona real 1-6) que
    // no queremos que se vuelvan a agrupar. Si después hacen falta, se
    // tienen que volver a cargar en el sistema.
    if ($action === 'eliminar_armado') {
        $viaje_id = intval($input['viaje_id'] ?? 0);
        if (!$viaje_id) jsonResponse(['success'=>false,'message'=>'Viaje inválido']);

        $v = $db->query("SELECT * FROM viajes WHERE id=$viaje_id")->fetch_assoc();
        if (!$v) jsonResponse(['success'=>false,'message'=>'Viaje no encontrado']);
        if ($v['estado'] !== 'armado') {
            jsonResponse(['success'=>false,'message'=>'Este viaje ya no está pendiente de confirmar (usá "Cancelar" desde Viajes del día / Histórico)']);
        }

        $db->query("DELETE FROM boletas WHERE viaje_id=$viaje_id");
        $db->query("DELETE FROM viajes WHERE id=$viaje_id");

        jsonResponse(['success'=>true,'message'=>'Viaje y sus boletas eliminados. Si hacen falta, hay que volver a cargarlas.']);
    }

    // Repartidor inicia viaje
    if ($action === 'iniciar') {
        $viaje_id      = intval($input['viaje_id']);
        $repartidor_id = intval($input['repartidor_id']);
        $camion_id     = intval($input['camion_id']);
        $stmt = $db->prepare('UPDATE viajes SET estado="en_curso", started_at=NOW(), repartidor_id=?, camion_id=? WHERE id=? AND estado="confirmado"');
        $stmt->bind_param('iii', $repartidor_id, $camion_id, $viaje_id);
        $stmt->execute();

        if ($db->affected_rows === 0) jsonResponse(['success'=>false,'message'=>'No se pudo iniciar el viaje']);

        // Marcar camión como en viaje
        $db->query("UPDATE camiones SET estado='en_viaje' WHERE id=$camion_id");

        // El orden óptimo ya se calculó al CONFIRMAR el viaje (arranca de
        // la fábrica, no depende de dónde esté el repartidor), así que
        // acá no hace falta volver a calcularlo — se devuelve tal cual.
        $vc = obtenerViajeConBoletas($db, $viaje_id);
        jsonResponse(['success'=>true,'message'=>'Viaje iniciado','boletas'=>$vc['boletas'] ?? []]);
    }

    // Repartidor finaliza viaje
    if ($action === 'finalizar') {
        $viaje_id = intval($input['viaje_id']);

        $stmt = $db->prepare('UPDATE viajes SET estado="finalizado", finished_at=NOW() WHERE id=? AND estado="en_curso"');
        $stmt->bind_param('i', $viaje_id);
        $stmt->execute();

        if ($db->affected_rows === 0) jsonResponse(['success'=>false,'message'=>'No se pudo finalizar']);

        // Calcular km estimado basado en paradas del viaje
        $bols = $db->query("SELECT lat, lon FROM boletas WHERE viaje_id=$viaje_id AND lat IS NOT NULL ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
        $km_est = 0;
        if (count($bols) > 1) {
            // Distancia desde fábrica + entre paradas
            $prev_lat = -34.5467; $prev_lon = -58.7134; // Fábrica
            foreach ($bols as $b) {
                $km_est += haversine($prev_lat, $prev_lon, floatval($b['lat']), floatval($b['lon']));
                $prev_lat = floatval($b['lat']); $prev_lon = floatval($b['lon']);
            }
            $km_est += haversine($prev_lat, $prev_lon, -34.5467, -58.7134); // Vuelta
            $km_est = round($km_est, 1);
        }
        $db->query("UPDATE viajes SET km_estimado=$km_est WHERE id=$viaje_id");

        // Liberar camión
        $cam = $db->query("SELECT camion_id FROM viajes WHERE id=$viaje_id")->fetch_assoc();
        if ($cam && $cam['camion_id']) {
            $db->query("UPDATE camiones SET estado='disponible' WHERE id=".$cam['camion_id']);
        }

        // Marcar boletas como entregadas
        $db->query("UPDATE boletas SET estado='entregada' WHERE viaje_id=$viaje_id");

        // Alerta de finalización
        $v = $db->query("SELECT repartidor_id FROM viajes WHERE id=$viaje_id")->fetch_assoc();
        if ($v && $v['repartidor_id']) {
            $rid = $v['repartidor_id'];
            $msg = "Viaje #$viaje_id finalizado correctamente";
            $tipo = 'finalizacion';
            $stmt = $db->prepare('INSERT INTO alertas_sistema (tipo, viaje_id, repartidor_id, mensaje) VALUES (?,?,?,?)');
            $stmt->bind_param('siis', $tipo, $viaje_id, $rid, $msg);
            $stmt->execute();
        }

        jsonResponse(['success'=>true,'message'=>'Viaje finalizado']);
    }

    // Marcar parada individual como entregada (guarda también la hora)
    if ($action === 'entregar_parada') {
        $boleta_id = intval($input['boleta_id']);
        $stmt = $db->prepare('UPDATE boletas SET estado="entregada", entregada_at=NOW() WHERE id=?');
        $stmt->bind_param('i', $boleta_id);
        $stmt->execute();
        jsonResponse(['success'=>true]);
    }

    // Guardar el día en que el admin planea que salga una zona (viaje).
    // Es solo planificación interna del admin — no afecta lo que ve el
    // repartidor (él solo ve su viaje ya confirmado y asignado).
    if ($action === 'dia_salida') {
        $viaje_id = intval($input['viaje_id'] ?? 0);
        $dia = trim($input['dia_salida'] ?? '');
        if (!$viaje_id) jsonResponse(['success'=>false,'message'=>'Viaje inválido']);
        if ($dia === '') {
            $stmt = $db->prepare('UPDATE viajes SET dia_salida=NULL WHERE id=?');
            $stmt->bind_param('i', $viaje_id);
        } else {
            $stmt = $db->prepare('UPDATE viajes SET dia_salida=? WHERE id=?');
            $stmt->bind_param('si', $dia, $viaje_id);
        }
        $stmt->execute();
        jsonResponse(['success'=>true,'message'=>'Día de salida guardado']);
    }

    // Mover una boleta de un viaje ARMADO a otro viaje ARMADO (los dos
    // pendientes de confirmar). Sirve para juntar una boleta suelta con
    // el viaje de una zona cercana y ahorrar un recorrido. La boleta
    // conserva su zona nominal; solo cambia a qué viaje pertenece. Ambos
    // viajes (origen y destino) recalculan peso y orden de paradas.
    if ($action === 'mover_boleta') {
        $boleta_id = intval($input['boleta_id'] ?? 0);
        $destino_id = intval($input['viaje_destino'] ?? 0);
        if (!$boleta_id || !$destino_id) jsonResponse(['success'=>false,'message'=>'Datos incompletos']);

        $b = $db->query("SELECT * FROM boletas WHERE id=$boleta_id")->fetch_assoc();
        if (!$b) jsonResponse(['success'=>false,'message'=>'Boleta no encontrada']);

        $destino = $db->query("SELECT * FROM viajes WHERE id=$destino_id")->fetch_assoc();
        if (!$destino) jsonResponse(['success'=>false,'message'=>'Viaje destino no encontrado']);
        if ($destino['estado'] !== 'armado') jsonResponse(['success'=>false,'message'=>'Solo se puede mover a un viaje que todavía no salió']);

        $origen_id = $b['viaje_id'] ? intval($b['viaje_id']) : null;

        // Mover la boleta
        $db->query("UPDATE boletas SET viaje_id=$destino_id, estado='asignada' WHERE id=$boleta_id");

        // Recalcular el viaje destino
        recalcularViaje($db, $destino_id);
        // Recalcular (o borrar si quedó vacío) el viaje de origen
        if ($origen_id && $origen_id !== $destino_id) {
            $quedan = $db->query("SELECT COUNT(*) c FROM boletas WHERE viaje_id=$origen_id")->fetch_assoc();
            if (intval($quedan['c']) === 0) {
                $db->query("DELETE FROM viajes WHERE id=$origen_id AND estado='armado'");
            } else {
                recalcularViaje($db, $origen_id);
            }
        }
        jsonResponse(['success'=>true,'message'=>'Boleta movida al otro viaje']);
    }

    // Sumar una parada a un viaje YA CONFIRMADO o EN CURSO (ej. el admin
    // se olvidó de cargar una boleta a tiempo). Recalcula la ruta: las
    // paradas ya entregadas quedan primero, en el mismo orden en que ya
    // iban (no tiene sentido reordenar lo que ya pasó); las que faltan
    // entregar —incluida la nueva— se reoptimizan entre sí con Google
    // Routes, para que la ruta que le queda al repartidor sea la mejor
    // posible con la parada nueva sumada.
    if ($action === 'agregar_parada_viaje_activo') {
        $boleta_id = intval($input['boleta_id'] ?? 0);
        $viaje_id  = intval($input['viaje_id'] ?? 0);
        if (!$boleta_id || !$viaje_id) jsonResponse(['success'=>false,'message'=>'Datos incompletos']);

        $b = $db->query("SELECT * FROM boletas WHERE id=$boleta_id")->fetch_assoc();
        if (!$b) jsonResponse(['success'=>false,'message'=>'Boleta no encontrada']);
        if ($b['estado'] === 'entregada') jsonResponse(['success'=>false,'message'=>'Esa boleta ya fue entregada']);
        if ($b['viaje_id']) jsonResponse(['success'=>false,'message'=>'Esa boleta ya pertenece a otro viaje']);

        $viaje = $db->query("SELECT * FROM viajes WHERE id=$viaje_id")->fetch_assoc();
        if (!$viaje) jsonResponse(['success'=>false,'message'=>'Viaje no encontrado']);
        if (!in_array($viaje['estado'], ['confirmado','en_curso'])) {
            jsonResponse(['success'=>false,'message'=>'Solo se puede agregar a un viaje confirmado o en curso']);
        }

        $db->query("UPDATE boletas SET viaje_id=$viaje_id, estado='asignada' WHERE id=$boleta_id");

        // Respetar el destino que ya tenía elegido este viaje (un punto
        // de finalización puntual, o la cochera si no eligió ninguno).
        $destLat = null; $destLon = null;
        if (!empty($viaje['destino_id'])) {
            $dp = $db->query("SELECT lat, lon FROM puntos_finalizacion WHERE id=".intval($viaje['destino_id']))->fetch_assoc();
            if ($dp && $dp['lat'] !== null) { $destLat = floatval($dp['lat']); $destLon = floatval($dp['lon']); }
        }

        $todas = $db->query("SELECT * FROM boletas WHERE viaje_id=$viaje_id")->fetch_all(MYSQLI_ASSOC);
        $entregadas = array_values(array_filter($todas, fn($x) => $x['estado'] === 'entregada'));
        $pendientes = array_values(array_filter($todas, fn($x) => $x['estado'] !== 'entregada'));

        $ordenPendientes = optimizarOrdenRuta($pendientes, null, null, $destLat, $destLon);
        if (!$ordenPendientes) {
            // Respaldo si Google Routes falla: orden por cercanía.
            $ordenPendientes = array_column(rutaOptima($pendientes), 'id');
        }
        $ordenFinal = array_merge(array_column($entregadas, 'id'), array_map('intval', $ordenPendientes));

        $peso_total = array_sum(array_column($todas, 'peso_kg'));
        $orden_json = json_encode($ordenFinal);
        $st = $db->prepare('UPDATE viajes SET peso_total=?, orden_paradas=? WHERE id=?');
        $st->bind_param('dsi', $peso_total, $orden_json, $viaje_id);
        $st->execute();

        jsonResponse(['success'=>true,'message'=>'Parada agregada y ruta recalculada.']);
    }

    // Sacar una boleta de un viaje ARMADO (antes de confirmar) y dejarla
    // POSPUESTA: NO se reagrupa sola en el viaje de su zona (a
    // diferencia de una boleta 'pendiente' común), para que realmente
    // quede afuera hasta que el admin decida a qué viaje sumarla desde
    // el apartado de "Boletas pospuestas".
    if ($action === 'quitar_parada') {
        $boleta_id = intval($input['boleta_id'] ?? 0);
        if (!$boleta_id) jsonResponse(['success'=>false,'message'=>'Boleta inválida']);

        $b = $db->query("SELECT * FROM boletas WHERE id=$boleta_id")->fetch_assoc();
        if (!$b) jsonResponse(['success'=>false,'message'=>'Boleta no encontrada']);
        if ($b['estado'] === 'entregada') jsonResponse(['success'=>false,'message'=>'Esa parada ya fue entregada, no se puede quitar']);

        $viaje_id = $b['viaje_id'] ? intval($b['viaje_id']) : null;
        $db->query("UPDATE boletas SET viaje_id=NULL, estado='pospuesta' WHERE id=$boleta_id");

        if ($viaje_id) {
            $quedan = $db->query("SELECT COUNT(*) c FROM boletas WHERE viaje_id=$viaje_id")->fetch_assoc();
            if (intval($quedan['c']) === 0) {
                // Si el viaje quedó sin paradas, liberar camión y borrarlo
                $v = $db->query("SELECT camion_id FROM viajes WHERE id=$viaje_id")->fetch_assoc();
                if ($v && $v['camion_id']) $db->query("UPDATE camiones SET estado='disponible' WHERE id=".intval($v['camion_id']));
                $db->query("DELETE FROM viajes WHERE id=$viaje_id");
            } else {
                recalcularViaje($db, $viaje_id);
            }
        }
        jsonResponse(['success'=>true,'message'=>'Boleta pospuesta. Quedó guardada en "Boletas pospuestas" hasta que la agregues a un viaje.']);
    }

    // Devolver una boleta pospuesta al flujo normal: pasa a 'pendiente' y
    // se reagrupa sola en el viaje armado de su zona (o crea uno nuevo).
    if ($action === 'reactivar_pospuesta') {
        $boleta_id = intval($input['boleta_id'] ?? 0);
        if (!$boleta_id) jsonResponse(['success'=>false,'message'=>'Boleta inválida']);
        $b = $db->query("SELECT * FROM boletas WHERE id=$boleta_id")->fetch_assoc();
        if (!$b || $b['estado'] !== 'pospuesta') jsonResponse(['success'=>false,'message'=>'Esa boleta no está pospuesta']);

        $db->query("UPDATE boletas SET estado='pendiente' WHERE id=$boleta_id");
        sincronizarZonasPendientes($db);
        jsonResponse(['success'=>true,'message'=>'Boleta reactivada, se sumó al viaje de su zona.']);
    }

    // Sumar una boleta pospuesta directo a un viaje ARMADO puntual que
    // elija el admin (puede ser el de otra zona, si conviene por
    // cercanía).
    if ($action === 'agregar_pospuesta_a_viaje') {
        $boleta_id = intval($input['boleta_id'] ?? 0);
        $viaje_destino = intval($input['viaje_destino'] ?? 0);
        if (!$boleta_id || !$viaje_destino) jsonResponse(['success'=>false,'message'=>'Datos incompletos']);

        $b = $db->query("SELECT * FROM boletas WHERE id=$boleta_id")->fetch_assoc();
        if (!$b || $b['estado'] !== 'pospuesta') jsonResponse(['success'=>false,'message'=>'Esa boleta no está pospuesta']);

        $destino = $db->query("SELECT * FROM viajes WHERE id=$viaje_destino")->fetch_assoc();
        if (!$destino) jsonResponse(['success'=>false,'message'=>'Viaje destino no encontrado']);
        if ($destino['estado'] !== 'armado') jsonResponse(['success'=>false,'message'=>'Solo se puede agregar a un viaje que todavía no salió']);

        $db->query("UPDATE boletas SET viaje_id=$viaje_destino, estado='asignada' WHERE id=$boleta_id");
        recalcularViaje($db, $viaje_destino);
        jsonResponse(['success'=>true,'message'=>'Boleta agregada al viaje.']);
    }

    // Cancelar/reiniciar un viaje (lo usa el administrador). Libera el
    // camión, deja las boletas de vuelta como 'pendiente' (se van a
    // reagrupar solas en un viaje "armado" nuevo la próxima vez que se
    // sincronicen las zonas) y borra el viaje. Sirve tanto para un error
    // de carga como para destrabar un camión que quedó marcado "en viaje"
    // sin estarlo en la realidad.
    if ($action === 'cancelar') {
        $viaje_id = intval($input['viaje_id'] ?? 0);
        if (!$viaje_id) jsonResponse(['success'=>false,'message'=>'Viaje inválido']);

        $v = $db->query("SELECT * FROM viajes WHERE id=$viaje_id")->fetch_assoc();
        if (!$v) jsonResponse(['success'=>false,'message'=>'Viaje no encontrado']);

        if ($v['camion_id']) {
            $db->query("UPDATE camiones SET estado='disponible' WHERE id=".intval($v['camion_id']));
        }
        // Solo las boletas que todavía no se entregaron vuelven a pendiente;
        // las que ya se marcaron entregadas quedan como están (historial real).
        $db->query("UPDATE boletas SET viaje_id=NULL, estado='pendiente' WHERE viaje_id=$viaje_id AND estado<>'entregada'");
        $db->query("DELETE FROM viajes WHERE id=$viaje_id");

        jsonResponse(['success'=>true,'message'=>'Viaje cancelado. El camión quedó disponible y sus boletas pendientes se van a reasignar solas.']);
    }
}

$db->close();
