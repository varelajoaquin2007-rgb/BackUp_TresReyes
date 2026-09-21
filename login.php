<?php
require_once 'config.php';
headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Método no permitido'], 405);

$input    = getInput();
$email    = trim(strtolower($input['email'] ?? ''));
$password = $input['password'] ?? '';

if (!$email || !$password) jsonResponse(['success'=>false,'message'=>'Email y contraseña requeridos']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success'=>false,'message'=>'Email inválido']);

$db = getDB();
$stmt = $db->prepare('
    SELECT u.*, r.id as repartidor_id, r.camion_id
    FROM usuarios u
    LEFT JOIN repartidores r ON r.usuario_id = u.id
    WHERE LOWER(u.email) = ? LIMIT 1
');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) jsonResponse(['success'=>false,'message'=>'Email no encontrado']);

// Verificar contraseña — acepta hash bcrypt y texto plano (período de migración)
$ok = false;
if (password_verify($password, $user['password'])) {
    $ok = true;
} elseif ($user['password'] === $password) {
    // Contraseña en texto plano — hashearla ahora y guardar
    $ok = true;
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $upd = $db->prepare('UPDATE usuarios SET password=? WHERE id=?');
    $upd->bind_param('si', $hashed, $user['id']);
    $upd->execute();
    $upd->close();
}

if (!$ok) jsonResponse(['success'=>false,'message'=>'Contraseña incorrecta']);

jsonResponse([
    'success' => true,
    'usuario' => [
        'id'            => (int)$user['id'],
        'nombre'        => $user['nombre'],
        'usuario'       => $user['usuario'],
        'email'         => $user['email'],
        'rol'           => $user['rol'],
        'repartidor_id' => $user['repartidor_id'] ? (int)$user['repartidor_id'] : null,
        'camion_id'     => $user['camion_id']     ? (int)$user['camion_id']     : null,
    ]
]);
$db->close();
