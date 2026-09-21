<?php
require_once 'config.php';
require_once 'lib_zonas.php';
headers();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

// GET - listar boletas pendientes
if ($method === 'GET') {
    // Boletas "pospuestas": las que se sacaron de un viaje armado (antes
    // de confirmar) para dejarlas afuera hasta decidir a qué viaje
    // sumarlas. Se listan aparte, sin importar la fecha en que se
    // cargaron originalmente — quedan esperando indefinidamente.
    if (isset($_GET['pospuestas']) && $_GET['pospuestas'] == '1') {
        $rows = $db->query("SELECT * FROM boletas WHERE estado='pospuesta' ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
        jsonResponse(['success'=>true,'boletas'=>$rows]);
    }

    // Boletas sueltas (pendientes o pospuestas) sin ningún viaje
    // asignado, de cualquier fecha — para poder sumarlas a un viaje ya
    // confirmado o en curso si el admin se olvidó de cargarlas a tiempo.
    if (isset($_GET['sin_asignar']) && $_GET['sin_asignar'] == '1') {
        $rows = $db->query("SELECT * FROM boletas WHERE viaje_id IS NULL AND estado IN ('pendiente','pospuesta') ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
        jsonResponse(['success'=>true,'boletas'=>$rows]);
    }

    $fecha = $_GET['fecha'] ?? date('Y-m-d');
    $stmt = $db->prepare('
        SELECT b.*, v.estado as viaje_estado
        FROM boletas b
        LEFT JOIN viajes v ON v.id = b.viaje_id
        WHERE b.fecha_carga = ?
          AND (v.estado IS NULL OR v.estado NOT IN ("confirmado","en_curso","finalizado"))
        ORDER BY b.id DESC
    ');
    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    jsonResponse(['success'=>true,'boletas'=>$rows]);
}

// POST - cargar nueva boleta manualmente o desde PDF parseado
if ($method === 'POST') {
    $input = getInput();
    $action = $input['action'] ?? 'crear';

    if ($action === 'crear') {
        $fecha    = $input['fecha']    ?? date('Y-m-d');
        $cliente  = trim($input['cliente']  ?? '');
        $direccion= trim($input['direccion']?? '');
        $peso     = floatval($input['peso_kg'] ?? 0);
        $barrio   = trim($input['barrio']  ?? '');
        $cp       = trim($input['cp']      ?? '');
        $zona     = trim($input['zona']    ?? '');
        $notas    = trim($input['notas']   ?? '');
        $lat      = $input['lat'] ? floatval($input['lat']) : null;
        $lon      = $input['lon'] ? floatval($input['lon']) : null;

        if (!$cliente || !$direccion || $peso <= 0) {
            jsonResponse(['success'=>false,'message'=>'Cliente, dirección y peso son requeridos']);
        }

        $stmt = $db->prepare('INSERT INTO boletas (fecha_carga, cliente, direccion, barrio, cp, zona, lat, lon, peso_kg, notas) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssssssddds', $fecha, $cliente, $direccion, $barrio, $cp, $zona, $lat, $lon, $peso, $notas);
        $stmt->execute();
        $id = $db->insert_id;

        // Arma o completa automáticamente la hoja de ruta de la zona
        sincronizarZonasPendientes($db);

        jsonResponse(['success'=>true,'id'=>$id,'message'=>'Boleta cargada']);
    }

    if ($action === 'bulk') {
        // Cargar múltiples boletas desde PDF parseado por el cliente
        $boletas  = $input['boletas'] ?? [];
        $fecha    = $input['fecha']   ?? date('Y-m-d');
        $inserted = 0;

        foreach ($boletas as $b) {
            $cliente  = trim($b['cliente']   ?? '');
            $direccion= trim($b['direccion'] ?? '');
            $peso     = floatval($b['peso_kg'] ?? 0);
            $barrio   = trim($b['barrio']    ?? '');
            $cp       = trim($b['cp']        ?? '');
            $zona     = trim($b['zona']      ?? '');
            $notas    = trim($b['notas']     ?? '');
            $lat      = isset($b['lat']) ? floatval($b['lat']) : null;
            $lon      = isset($b['lon']) ? floatval($b['lon']) : null;

            if (!$cliente || !$direccion) continue;

            // Usar coordenadas del PDF si están disponibles
            $lat_b = isset($b['lat']) && $b['lat'] ? floatval($b['lat']) : $lat;
            $lon_b = isset($b['lon']) && $b['lon'] ? floatval($b['lon']) : $lon;
            $stmt = $db->prepare('INSERT INTO boletas (fecha_carga, cliente, direccion, barrio, cp, zona, lat, lon, peso_kg, notas) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('ssssssddds', $fecha, $cliente, $direccion, $barrio, $cp, $zona, $lat_b, $lon_b, $peso, $notas);
            $stmt->execute();
            $inserted++;
        }

        // Arma o completa automáticamente las hojas de ruta de las zonas afectadas
        sincronizarZonasPendientes($db);

        jsonResponse(['success'=>true,'insertadas'=>$inserted]);
    }

    // Borrado TOTAL: elimina TODAS las boletas (sin importar fecha ni
    // estado, incluidas las ya entregadas) y TODOS los viajes, liberando
    // los camiones que estuvieran en viaje. Pensado para volver el
    // sistema a cero (pruebas, arranque de una nueva operación, etc.) —
    // es irreversible, por eso el frontend pide una confirmación fuerte
    // antes de llamar a esta acción.
    if ($action === 'borrar_todo') {
        $db->query("DELETE FROM boletas");
        $db->query("DELETE FROM viajes");
        $db->query("UPDATE camiones SET estado='disponible' WHERE estado='en_viaje'");
        jsonResponse(['success'=>true,'message'=>'Se borraron todas las boletas y viajes de todas las fechas. Los camiones quedaron disponibles.']);
    }

    if ($action === 'update_coords') {
        $id  = intval($input['id']);
        $lat = floatval($input['lat']);
        $lon = floatval($input['lon']);
        $barrio = trim($input['barrio'] ?? '');
        $stmt = $db->prepare('UPDATE boletas SET lat=?, lon=?, barrio=? WHERE id=?');
        $stmt->bind_param('ddsi', $lat, $lon, $barrio, $id);
        $stmt->execute();
        jsonResponse(['success'=>true]);
    }

    // Editar los datos de un cliente/boleta: dirección, localidad y zona.
    // Si cambió la dirección o la localidad, se borran las coordenadas
    // para forzar que el frontend vuelva a geocodificar. Si cambió la
    // zona y la boleta está en un viaje ARMADO, se saca de ese viaje y se
    // reagrupa en el de su nueva zona (los viajes ya confirmados/en curso
    // no se tocan). El admin igual puede después moverla a otro viaje a
    // mano con 'mover_boleta'.
    if ($action === 'editar_cliente') {
        $id = intval($input['id'] ?? 0);
        if (!$id) jsonResponse(['success'=>false,'message'=>'ID requerido']);
        $b = $db->query("SELECT * FROM boletas WHERE id=$id")->fetch_assoc();
        if (!$b) jsonResponse(['success'=>false,'message'=>'Boleta no encontrada']);

        $direccion = trim($input['direccion'] ?? $b['direccion']);
        $barrio    = trim($input['barrio'] ?? $b['barrio']);
        $zona      = trim($input['zona'] ?? $b['zona']);

        $cambioUbicacion = ($direccion !== $b['direccion']) || ($barrio !== ($b['barrio'] ?? ''));
        $cambioZona = ($zona !== ($b['zona'] ?? ''));

        if ($cambioUbicacion) {
            // Borra coordenadas: quedará "Sin GPS" hasta re-geocodificar.
            $stmt = $db->prepare('UPDATE boletas SET direccion=?, barrio=?, zona=?, lat=NULL, lon=NULL, geo_aproximada=0 WHERE id=?');
            $stmt->bind_param('sssi', $direccion, $barrio, $zona, $id);
        } else {
            $stmt = $db->prepare('UPDATE boletas SET direccion=?, barrio=?, zona=? WHERE id=?');
            $stmt->bind_param('sssi', $direccion, $barrio, $zona, $id);
        }
        $stmt->execute();

        // Si cambió la zona y la boleta estaba en un viaje ARMADO, se
        // reagrupa según la zona nueva (no toca viajes ya confirmados).
        if ($cambioZona) {
            $viaje_id = $b['viaje_id'] ? intval($b['viaje_id']) : null;
            if ($viaje_id) {
                $v = $db->query("SELECT estado FROM viajes WHERE id=$viaje_id")->fetch_assoc();
                if ($v && $v['estado'] === 'armado') {
                    $db->query("UPDATE boletas SET viaje_id=NULL, estado='pendiente' WHERE id=$id");
                    $quedan = $db->query("SELECT COUNT(*) c FROM boletas WHERE viaje_id=$viaje_id")->fetch_assoc();
                    if (intval($quedan['c']) === 0) $db->query("DELETE FROM viajes WHERE id=$viaje_id AND estado='armado'");
                    else recalcularViaje($db, $viaje_id);
                    sincronizarZonasPendientes($db);
                }
            } else {
                sincronizarZonasPendientes($db);
            }
        }

        jsonResponse(['success'=>true,'regeocodificar'=>$cambioUbicacion,'message'=>'Cliente actualizado']);
    }

    // Geocodificar una boleta puntual con LocationIQ (dirección → lat/lon).
    // Se hace del lado del servidor: así la API key nunca queda expuesta
    // en el navegador, y se evita el límite de 1 pedido/seg del mapa
    // gratuito que usábamos antes.
    //
    // Reintento en cascada: si la dirección exacta (calle+altura) no está
    // cargada en el mapa —muy común en calles largas del Gran Buenos
    // Aires como "Pte. Perón"—, se prueba con la calle SIN la altura
    // (punto aproximado sobre esa calle) y, si tampoco, con la localidad
    // sola (punto aproximado en ese barrio/pueblo). Siempre se intenta
    // dejar algo ubicado en vez de nada; lo aproximado queda marcado en
    // geo_aproximada para que se pueda revisar a mano si hace falta.
    if ($action === 'geocodificar') {
        $id = intval($input['id'] ?? 0);
        if (!$id) jsonResponse(['success'=>false,'message'=>'ID requerido']);

        $b = $db->query("SELECT * FROM boletas WHERE id=$id")->fetch_assoc();
        if (!$b) jsonResponse(['success'=>false,'message'=>'Boleta no encontrada']);

        $localidad = trim($b['barrio'] ?? '');
        $direccion = $b['direccion'];
        $cp = trim($b['cp'] ?? '');
        $zona = trim($b['zona'] ?? '');

        // Con Google + código postal, una sola búsqueda limpia alcanza:
        // Google devuelve el punto exacto y nos dice su precisión. No hace
        // falta sesgar por zona ni reintentar con la calle sin altura —
        // eso, con Google, empeoraba el resultado (lo volvía aproximado).
        $sufijoCP = $cp ? ", $cp" : '';
        $q1 = $direccion;
        if ($localidad && stripos($q1, $localidad) === false) $q1 .= ", $localidad";
        $q1 .= $sufijoCP;
        if (stripos($q1, 'argentina') === false) $q1 .= ', Buenos Aires, Argentina';

        $r = buscarGoogleGeocoding($q1, null);

        // Si con el CP el resultado vino aproximado, puede ser que el CP
        // de la factura esté mal (ej. un CP de Capital "C1406" para una
        // dirección de provincia). Reintentar SIN el CP, con dirección +
        // localidad, que es más confiable. Nos quedamos con el mejor de
        // los dos (preferimos el exacto).
        if ($cp && (empty($r['candidatos']) || empty($r['candidatos'][0]['exacto']))) {
            $qSinCP = $direccion;
            if ($localidad && stripos($qSinCP, $localidad) === false) $qSinCP .= ", $localidad";
            if (stripos($qSinCP, 'argentina') === false) $qSinCP .= ', Buenos Aires, Argentina';
            $r2 = buscarGoogleGeocoding($qSinCP, null);
            // Usamos el reintento si encontró algo exacto (o si el primero
            // no había encontrado nada).
            if (!empty($r2['candidatos']) && (!empty($r2['candidatos'][0]['exacto']) || empty($r['candidatos']))) {
                $r = $r2;
            }
        }

        // Si con dirección completa no encontró NADA, un único reintento
        // con la localidad sola (para al menos ubicar el barrio). Ese sí
        // queda marcado como aproximado.
        $soloLocalidad = false;
        if (empty($r['candidatos'])) {
            if (!empty($r['error']) && stripos($r['error'], 'Límite') !== false) {
                jsonResponse(['success'=>false,'message'=>$r['error']]);
            }
            if ($localidad) {
                $r = buscarGoogleGeocoding("$localidad, Buenos Aires, Argentina", null);
                $soloLocalidad = true;
            }
        }

        if (empty($r['candidatos'])) {
            jsonResponse(['success'=>false,'message'=>$r['error'] ?? 'No se encontró esa dirección']);
        }
        $data = $r['candidatos'];

        // Con varios resultados, preferir el que coincide con la localidad
        // de la boleta (sin distinguir mayúsculas ni acentos).
        $loc = $data[0];
        if ($localidad && count($data) > 1) {
            $localidadNorm = normalizarTexto($localidad);
            foreach ($data as $d) {
                $addr = $d['address'] ?? [];
                $posibles = normalizarTexto(implode(' ', array_filter([
                    $addr['city'] ?? '', $addr['town'] ?? '', $addr['village'] ?? '',
                    $addr['suburb'] ?? '', $addr['county'] ?? '',
                ])));
                if ($localidadNorm !== '' && strpos($posibles, $localidadNorm) !== false) { $loc = $d; break; }
            }
        }

        $lat = floatval($loc['lat']);
        $lon = floatval($loc['lon']);

        // Es aproximada si tuvimos que caer a "solo localidad", o si Google
        // mismo reporta que el punto no es exacto (no es ROOFTOP ni
        // interpolado sobre la cuadra).
        $aproximada = $soloLocalidad || empty($loc['exacto']);

        // Barrera de sanidad: toda la operación es en Buenos Aires / Gran
        // Buenos Aires. Un resultado a más de 80 km de la fábrica es casi
        // seguro un error (calle homónima de otra provincia) — mejor
        // dejarla "Sin GPS" con aviso que guardar un punto disparatado.
        $distFabrica = haversine($lat, $lon, FABRICA_LAT, FABRICA_LON);
        if ($distFabrica > 80) {
            jsonResponse(['success'=>false,'message'=>'El resultado quedaba a '.round($distFabrica).' km de la fábrica — no parece una dirección real de Buenos Aires. Revisá cómo quedó escaneada la dirección.']);
        }

        $aproxInt = $aproximada ? 1 : 0;

        // OJO: la localidad NO se toca acá. Se guarda tal cual la escaneó
        // el parser del PDF ($localidad) — antes se pisaba con el barrio
        // que devolvía el mapa para el punto encontrado, y si ese punto
        // quedaba mal ubicado, la localidad mostrada también quedaba mal
        // (tapando el dato real y arrastrando el error en un reintento).
        $stmt = $db->prepare('UPDATE boletas SET lat=?, lon=?, geo_aproximada=? WHERE id=?');
        $stmt->bind_param('ddii', $lat, $lon, $aproxInt, $id);
        $stmt->execute();

        jsonResponse(['success'=>true, 'lat'=>$lat, 'lon'=>$lon, 'barrio'=>$localidad, 'aproximada'=>$aproximada]);
    }
}

// DELETE - eliminar boleta (solo si todavía no salió de viaje: se puede
// borrar mientras está "pendiente" o mientras su viaje sigue "armado"
// —es decir, antes de que el administrador confirme la salida— por si
// se cargó por error).
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID requerido']);

    $b = $db->query("SELECT * FROM boletas WHERE id=$id")->fetch_assoc();
    if (!$b) jsonResponse(['success'=>false,'message'=>'Boleta no encontrada']);

    if ($b['estado'] === 'entregada') {
        jsonResponse(['success'=>false,'message'=>'Esta boleta ya fue entregada, no se puede borrar']);
    }

    $viaje_id = $b['viaje_id'] ? intval($b['viaje_id']) : null;
    $viaje = $viaje_id ? $db->query("SELECT * FROM viajes WHERE id=$viaje_id")->fetch_assoc() : null;

    $stmt = $db->prepare('DELETE FROM boletas WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    // Si pertenecía a un viaje, recalcularlo (o borrarlo/liberar el
    // camión si se quedó sin ninguna boleta) — sin importar si el viaje
    // estaba armado, confirmado o en curso: la boleta se puede eliminar
    // en cualquier estado (menos ya entregada).
    if ($viaje_id && $viaje) {
        $restantes = $db->query("SELECT * FROM boletas WHERE viaje_id=$viaje_id")->fetch_all(MYSQLI_ASSOC);
        if (empty($restantes)) {
            if ($viaje['camion_id']) {
                $db->query("UPDATE camiones SET estado='disponible' WHERE id=".intval($viaje['camion_id']));
            }
            $db->query("DELETE FROM viajes WHERE id=$viaje_id");
        } else {
            $peso_total = array_sum(array_column($restantes, 'peso_kg'));
            $orden = rutaOptima($restantes);
            $orden_json = json_encode(array_values(array_column($orden, 'id')));
            $stmt3 = $db->prepare('UPDATE viajes SET peso_total=?, orden_paradas=? WHERE id=?');
            $stmt3->bind_param('dsi', $peso_total, $orden_json, $viaje_id);
            $stmt3->execute();
        }
    }

    jsonResponse(['success'=>true]);
}

$db->close();
