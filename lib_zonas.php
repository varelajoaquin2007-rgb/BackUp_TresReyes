<?php
// =============================================================
// LIBRERÍA COMPARTIDA: armado de viajes por ZONA
// =============================================================
// La empresa reparte UNA zona por día. Cada boleta trae su zona
// (1 a 6) escaneada del PDF. En vez de agrupar boletas por camiones
// disponibles (como antes), se agrupan por zona: cada zona pendiente
// tiene un único viaje "armado" (hoja de ruta) que se va completando
// a medida que llegan nuevas boletas de esa zona, hasta que el
// administrador lo confirma (elige camión/repartidor y "sale" el
// camión). Un viaje confirmado, en curso o finalizado nunca se toca:
// si llegan boletas nuevas de esa zona, se arma un viaje nuevo.

function haversine($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return 0;
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// Pasa a minúsculas y saca acentos/Ñ, para comparar texto sin que fallen
// nombres como "Muñiz", "San Martín" o "Ituzaingó" (PHP's strtolower()
// no convierte bien la Ñ ni las vocales acentuadas, lo que rompía la
// comparación de localidad al elegir entre varios resultados del mapa).
function normalizarTexto($s) {
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $buscar     = ['á','à','ä','é','è','ë','í','ì','ï','ó','ò','ö','ú','ù','ü','ñ',
                   'Á','À','Ä','É','È','Ë','Í','Ì','Ï','Ó','Ò','Ö','Ú','Ù','Ü','Ñ'];
    $reemplazo  = ['a','a','a','e','e','e','i','i','i','o','o','o','u','u','u','n',
                   'a','a','a','e','e','e','i','i','i','o','o','o','u','u','u','n'];
    return str_replace($buscar, $reemplazo, $s);
}

// Ordena las paradas de una zona con el vecino más cercano (ruta óptima simple)
// Ordena las paradas por cercanía usando "vecino más cercano", SIEMPRE
// arrancando desde la fábrica (el punto real de salida del camión). Así
// el recorrido queda anclado en algún punto de partida, sumando siempre
// la parada más cercana a la anterior — mejor que un orden arbitrario.
// OJO: este orden es solo el PROVISORIO que se ve mientras se arma el
// viaje (antes de confirmar). El orden DEFINITIVO se calcula con Google
// Routes recién cuando el repartidor inicia el viaje, desde SU
// ubicación real — ahí es donde de verdad importa la optimización.
// Nota: usa distancia en línea recta (rápida y sin costo). Para el orden
// óptimo real por calles/tiempo haría falta la API de rutas de Google.
function rutaOptima($paradas) {
    if (count($paradas) <= 1) return $paradas;

    $conCoords = array_values(array_filter($paradas, fn($p) => $p['lat'] !== null && $p['lon'] !== null));
    $sinCoords = array_values(array_filter($paradas, fn($p) => $p['lat'] === null || $p['lon'] === null));
    if (count($conCoords) <= 1) return array_merge($conCoords, $sinCoords);

    $visitados = []; $orden = [];
    // Punto de arranque: la primera parada (no se usa la fábrica acá —
    // este orden es solo provisorio, se recalcula de verdad al iniciar).
    $refLat = floatval($conCoords[0]['lat']);
    $refLon = floatval($conCoords[0]['lon']);

    $restantes = $conCoords;
    while (!empty($restantes)) {
        $minDist = PHP_FLOAT_MAX; $nearIdx = 0;
        foreach ($restantes as $idx => $p) {
            $d = haversine($refLat, $refLon, floatval($p['lat']), floatval($p['lon']));
            if ($d < $minDist) { $minDist = $d; $nearIdx = $idx; }
        }
        $sig = $restantes[$nearIdx];
        $orden[] = $sig;
        // La próxima parada se mide desde la que acabamos de agregar.
        $refLat = floatval($sig['lat']); $refLon = floatval($sig['lon']);
        array_splice($restantes, $nearIdx, 1);
    }
    // Las que no tienen coordenadas van al final (no se pueden ordenar).
    return array_merge($orden, $sinCoords);
}

/**
 * Toma TODAS las boletas 'pendiente' (de cualquier fecha de carga) y
 * arma/actualiza un único viaje 'armado' (hoja de ruta pendiente de
 * confirmar) por zona:
 *
 *  - Si la zona ya tiene un viaje 'armado' (sin importar hace cuántos
 *    días se creó) → le agrega las boletas nuevas (recalcula peso
 *    total y orden de paradas). Así, si hoy se arma la zona 4 y no
 *    sale, y mañana entran más boletas de la zona 4, se suman al
 *    mismo viaje en vez de crear uno nuevo.
 *  - Si no tiene (primera boleta de esa zona, o su viaje anterior ya
 *    fue confirmado/salió) → crea un viaje 'armado' nuevo para esa zona.
 *  - Nunca modifica viajes en estado confirmado, en_curso o finalizado.
 *
 * Es seguro llamarla repetidas veces (idempotente): se ejecuta sola
 * cada vez que se cargan boletas nuevas, y también la puede disparar
 * el admin manualmente desde "Viajes pendientes".
 */
function sincronizarZonasPendientes($db) {
    $res = $db->query("SELECT * FROM boletas WHERE estado='pendiente' ORDER BY id ASC");
    $pendientes = $res->fetch_all(MYSQLI_ASSOC);
    if (empty($pendientes)) return [];

    $porZona = [];
    foreach ($pendientes as $b) {
        $z = ($b['zona'] !== null && trim($b['zona']) !== '') ? trim($b['zona']) : 'Sin zona';
        $porZona[$z][] = $b;
    }

    $hoy = date('Y-m-d');
    $viajesTocados = [];
    foreach ($porZona as $zona => $boletasZona) {
        // OJO: acá ya NO se filtra por fecha — un viaje "armado" de la
        // zona 4 sigue siendo el mismo aunque hayan pasado varios días
        // sin confirmarse.
        $stmt = $db->prepare("SELECT * FROM viajes WHERE zona=? AND estado='armado' LIMIT 1");
        $stmt->bind_param('s', $zona);
        $stmt->execute();
        $viaje = $stmt->get_result()->fetch_assoc();

        if ($viaje) {
            $viaje_id = $viaje['id'];
            $existentes = $db->query("SELECT * FROM boletas WHERE viaje_id=$viaje_id")->fetch_all(MYSQLI_ASSOC);
        } else {
            $estado_v = 'armado';
            // La "fecha" acá es solo informativa (cuándo se empezó a armar);
            // la fecha real del viaje se pisa con la de hoy recién cuando
            // el administrador lo confirma.
            $stmt2 = $db->prepare("INSERT INTO viajes (fecha, estado, zona, peso_total) VALUES (?,?,?,0)");
            $stmt2->bind_param('sss', $hoy, $estado_v, $zona);
            $stmt2->execute();
            $viaje_id = $db->insert_id;
            $existentes = [];
        }

        // Asignar las boletas nuevas al viaje de la zona
        foreach ($boletasZona as $b) {
            $bid = intval($b['id']);
            $db->query("UPDATE boletas SET viaje_id=$viaje_id, estado='asignada' WHERE id=$bid");
        }

        $todas = array_merge($existentes, $boletasZona);
        $peso_total = array_sum(array_column($todas, 'peso_kg'));
        $orden = rutaOptima($todas);
        $orden_json = json_encode(array_values(array_column($orden, 'id')));

        $stmt3 = $db->prepare("UPDATE viajes SET peso_total=?, orden_paradas=? WHERE id=?");
        $stmt3->bind_param('dsi', $peso_total, $orden_json, $viaje_id);
        $stmt3->execute();

        $viajesTocados[] = $viaje_id;
    }

    return $viajesTocados;
}

// Recalcula el peso total y el orden óptimo de paradas de un viaje,
// según las boletas que tenga asignadas en este momento. Se usa cada
// vez que se agrega o quita una boleta de un viaje (mover, quitar
// parada, etc.).
function recalcularViaje($db, $viaje_id) {
    $viaje_id = intval($viaje_id);
    $bs = $db->query("SELECT * FROM boletas WHERE viaje_id=$viaje_id")->fetch_all(MYSQLI_ASSOC);
    $peso_total = array_sum(array_column($bs, 'peso_kg'));
    $orden = rutaOptima($bs);
    $orden_json = json_encode(array_values(array_column($orden, 'id')));
    $stmt = $db->prepare("UPDATE viajes SET peso_total=?, orden_paradas=? WHERE id=?");
    $stmt->bind_param('dsi', $peso_total, $orden_json, $viaje_id);
    $stmt->execute();
}

// Trae un viaje con sus boletas ordenadas según orden_paradas
function obtenerViajeConBoletas($db, $viaje_id) {
    $v = $db->query("SELECT * FROM viajes WHERE id=$viaje_id")->fetch_assoc();
    if (!$v) return null;
    $bs = $db->query("SELECT * FROM boletas WHERE viaje_id=$viaje_id ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    if ($v['orden_paradas']) {
        $orden = json_decode($v['orden_paradas'], true);
        $byId = [];
        foreach ($bs as $b) $byId[$b['id']] = $b;
        $ordenadas = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $orden)));
        // Por si alguna boleta quedó afuera de orden_paradas (ej. se
        // agregó después de calcular el orden): se suma al final, para
        // no perderla nunca de la lista aunque no esté optimizada.
        $idsOrdenados = array_column($ordenadas, 'id');
        foreach ($bs as $b) {
            if (!in_array($b['id'], $idsOrdenados)) $ordenadas[] = $b;
        }
        $bs = $ordenadas;
    }
    $v['boletas'] = $bs;

    // Coordenadas de dónde termina este viaje: el punto de finalización
    // que eligió el admin, si eligió uno. Si no eligió ninguno, quedan en
    // null — no hay ningún punto por defecto, el viaje termina en la
    // última entrega (ver optimizarOrdenRuta) y el frontend arma el link
    // de Maps de la misma forma.
    $v['destino_lat'] = null;
    $v['destino_lon'] = null;
    $v['destino_nombre'] = null;
    if (!empty($v['destino_id'])) {
        $dp = $db->query("SELECT nombre, lat, lon FROM puntos_finalizacion WHERE id=".intval($v['destino_id']))->fetch_assoc();
        if ($dp && $dp['lat'] !== null) {
            $v['destino_lat'] = floatval($dp['lat']);
            $v['destino_lon'] = floatval($dp['lon']);
            $v['destino_nombre'] = $dp['nombre'];
        }
    }
    return $v;
}

// Libera camiones que quedaron marcados "en_viaje" sin tener en
// realidad ningún viaje confirmado/en curso detrás (típicamente porque
// un viaje viejo nunca se finalizó ni se canceló). Se llama en varios
// puntos de la app para que el estado se autocorrija solo.
function liberarCamionesTrabados($db) {
    $db->query("
        UPDATE camiones
        SET estado='disponible'
        WHERE estado='en_viaje'
          AND id NOT IN (
              SELECT COALESCE(camion_id,0) FROM viajes WHERE estado IN ('confirmado','en_curso')
          )
    ");
}

/**
 * Geocodifica con Google Geocoding API y devuelve los resultados
 * normalizados al formato interno { lat, lon, address:{city,town,
 * village,suburb,county} }, para que la lógica de selección por
 * localidad / cuadrante de zona / fábrica funcione igual que antes.
 *
 * $bounds (opcional): ['sur','oeste','norte','este'] — sesgo hacia el
 * cuadrante de la zona (Google lo trata como preferencia, no filtro duro).
 */
function buscarGoogleGeocoding($query, $bounds = null) {
    $url = 'https://maps.googleapis.com/maps/api/geocode/json'
         . '?key=' . urlencode(GOOGLE_GEOCODING_KEY)
         . '&address=' . urlencode($query)
         . '&region=ar&components=country:AR&language=es';
    if ($bounds) {
        $url .= '&bounds=' . $bounds['sur'] . ',' . $bounds['oeste'] . '|' . $bounds['norte'] . ',' . $bounds['este'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['error' => 'No se pudo conectar con Google: ' . $curlErr];

    $data = json_decode($resp, true);
    $status = $data['status'] ?? 'ERROR';

    if ($status === 'OVER_QUERY_LIMIT') return ['error' => 'Límite de pedidos de Google alcanzado, reintentá en un momento'];
    if ($status === 'REQUEST_DENIED')  return ['error' => 'Google rechazó el pedido (revisar key/restricciones): ' . ($data['error_message'] ?? '')];
    if ($status !== 'OK' || empty($data['results'])) return ['error' => 'No se encontró esa dirección', 'candidatos' => []];

    $candidatos = [];
    foreach ($data['results'] as $r) {
        $comps = $r['address_components'] ?? [];
        $get = function($tipo) use ($comps) {
            foreach ($comps as $c) if (in_array($tipo, $c['types'] ?? [])) return $c['long_name'];
            return '';
        };
        // location_type indica la precisión del resultado:
        //   ROOFTOP            = punto exacto del edificio (lo mejor)
        //   RANGE_INTERPOLATED = interpolado sobre la cuadra (muy bueno)
        //   GEOMETRIC_CENTER   = centro de la calle/zona (aproximado)
        //   APPROXIMATE        = aproximado (localidad/barrio)
        $lt = $r['geometry']['location_type'] ?? 'APPROXIMATE';
        $candidatos[] = [
            'lat' => $r['geometry']['location']['lat'] ?? null,
            'lon' => $r['geometry']['location']['lng'] ?? null,
            'precision' => $lt,
            'exacto' => in_array($lt, ['ROOFTOP','RANGE_INTERPOLATED']),
            'address' => [
                'city'    => $get('locality') ?: $get('postal_town'),
                'town'    => $get('locality'),
                'village' => '',
                'suburb'  => $get('sublocality') ?: $get('sublocality_level_1') ?: $get('neighborhood'),
                'county'  => $get('administrative_area_level_2'),
            ],
        ];
    }
    return ['candidatos' => $candidatos];
}

/**
 * Optimiza el ORDEN de las paradas de un viaje con la Google Routes API
 * (optimizeWaypointOrder). El recorrido:
 *   - ARRANCA en la fábrica (por defecto; se puede pasar otro origen).
 *   - PASA por todas las entregas, en el mejor orden por tiempo real de
 *     manejo (Google decide el orden).
 *   - TERMINA en el punto de finalización elegido por el admin para ese
 *     viaje ($destinoLat/$destinoLon), o si no eligió ninguno, en la
 *     ÚLTIMA entrega (la más lejana del origen) — no hay ningún punto
 *     fijo por defecto, el admin lo carga a mano cuando quiere uno.
 *
 * $boletas: array de boletas (cada una con id, lat, lon).
 * $origenLat/$origenLon: punto de partida. Si son null, usa la fábrica.
 * $destinoLat/$destinoLon: punto de llegada elegido. Si son null, el
 * destino es la última entrega (todas las demás quedan de intermedias).
 * Devuelve un array de ids en el orden óptimo, o null si no se pudo.
 */
function optimizarOrdenRuta($boletas, $origenLat = null, $origenLon = null, $destinoLat = null, $destinoLon = null) {
    $conCoords = array_values(array_filter($boletas, fn($b) => $b['lat'] !== null && $b['lon'] !== null));
    $sinCoords = array_values(array_filter($boletas, fn($b) => $b['lat'] === null || $b['lon'] === null));
    // Con 0 o 1 parada no hay nada que optimizar.
    if (count($conCoords) < 2) return null;
    // La Routes API admite hasta 25 waypoints intermedios. Si hubiera más,
    // no optimizamos por API (se queda el orden por cercanía).
    if (count($conCoords) > 25) return null;

    $oLat = $origenLat !== null ? floatval($origenLat) : FABRICA_LAT;
    $oLon = $origenLon !== null ? floatval($origenLon) : FABRICA_LON;
    $origen = ['location' => ['latLng' => ['latitude' => $oLat, 'longitude' => $oLon]]];

    $destinoElegido = ($destinoLat !== null && $destinoLon !== null);
    $ultima = null; // solo se usa cuando NO hay destino elegido

    if ($destinoElegido) {
        // Punto de finalización elegido: todas las entregas son paradas
        // intermedias a ordenar, ninguna es el destino.
        $destino = ['location' => ['latLng' => ['latitude' => floatval($destinoLat), 'longitude' => floatval($destinoLon)]]];
        $intermedias = $conCoords;
    } else {
        // Sin destino elegido: el viaje termina en la última entrega (la
        // más lejana del origen, según un primer orden por cercanía) —
        // el comportamiento de siempre cuando no se carga un punto de
        // finalización a mano.
        $ordenPrevio = rutaOptima($conCoords);
        $ultima = end($ordenPrevio);
        $intermedias = array_slice($ordenPrevio, 0, count($ordenPrevio) - 1);
        $destino = ['location' => ['latLng' => ['latitude' => floatval($ultima['lat']), 'longitude' => floatval($ultima['lon'])]]];
    }

    $waypoints = [];
    foreach ($intermedias as $b) {
        $waypoints[] = ['location' => ['latLng' => ['latitude' => floatval($b['lat']), 'longitude' => floatval($b['lon'])]]];
    }

    $body = [
        'origin'      => $origen,
        'destination' => $destino,
        'intermediates' => $waypoints,
        'travelMode'  => 'DRIVE',
        'optimizeWaypointOrder' => true,
    ];

    $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . GOOGLE_ROUTES_KEY,
        'X-Goog-FieldMask: routes.optimizedIntermediateWaypointIndex',
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) return null;
    $data = json_decode($resp, true);
    $orden = $data['routes'][0]['optimizedIntermediateWaypointIndex'] ?? null;
    if (!is_array($orden)) return null;

    // Reconstruir la lista de ids en el orden óptimo.
    $ids = [];
    foreach ($orden as $idx) {
        if (isset($intermedias[$idx])) $ids[] = intval($intermedias[$idx]['id']);
    }
    // Si no había destino elegido, la "última entrega" va al final del
    // orden (es una boleta más, no un punto de finalización aparte).
    if (!$destinoElegido && $ultima) $ids[] = intval($ultima['id']);
    // Las paradas sin coordenadas (que no entraron al cálculo) van al final.
    foreach ($sinCoords as $b) $ids[] = intval($b['id']);
    return $ids;
}
