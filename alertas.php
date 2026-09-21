<?php
require_once 'config.php';
headers();

$db = getDB();

// GET - traer alertas del admin (viajes finalizados recientes)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('
        SELECT v.id, v.zona, v.finished_at, v.peso_total,
               c.patente, u.nombre as repartidor
        FROM viajes v
        LEFT JOIN camiones c ON c.id = v.camion_id
        LEFT JOIN repartidores r ON r.id = v.repartidor_id
        LEFT JOIN usuarios u ON u.id = r.usuario_id
        WHERE v.estado = "finalizado"
        AND v.finished_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY v.finished_at DESC
        LIMIT 20
    ');
    $alertas = $res->fetch_all(MYSQLI_ASSOC);
    jsonResponse(['success'=>true,'alertas'=>$alertas]);
}

$db->close();
