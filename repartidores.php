<?php
require_once 'config.php';
require_once 'lib_zonas.php';
headers();

$db = getDB();
liberarCamionesTrabados($db);

$res = $db->query('
    SELECT r.id, r.camion_id, u.nombre, u.usuario, u.email,
           c.patente, c.modelo, c.estado as camion_estado,
           (SELECT COUNT(*) FROM viajes v WHERE v.repartidor_id = r.id AND v.estado IN ("confirmado","en_curso")) as viajes_activos
    FROM repartidores r
    JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN camiones c ON c.id = r.camion_id
');
$repartidores = $res->fetch_all(MYSQLI_ASSOC);

$cam_res = $db->query('SELECT * FROM camiones');
$camiones = $cam_res->fetch_all(MYSQLI_ASSOC);

jsonResponse(['success'=>true,'repartidores'=>$repartidores,'camiones'=>$camiones]);
$db->close();
