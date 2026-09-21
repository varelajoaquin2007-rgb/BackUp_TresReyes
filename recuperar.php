<?php
require_once 'config.php';
require_once 'mailer.php';
headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Método no permitido'], 405);

$db     = getDB();
$input  = getInput();
$action = $input['action'] ?? '';

// Auto-migración: asegura que existan las columnas necesarias (no rompe nada si ya existen)
try { @$db->query("ALTER TABLE usuarios ADD COLUMN reset_token VARCHAR(64) NULL"); } catch (\Throwable $e) {}
try { @$db->query("ALTER TABLE usuarios ADD COLUMN reset_expira DATETIME NULL"); } catch (\Throwable $e) {}

// ---- Solicitar link de recuperación (SOLO para la cuenta admin) ----
if ($action === 'solicitar') {
    // No se recibe ni se elige ningún email: esta función resetea
    // siempre la cuenta con rol='admin', y el mail se manda siempre
    // a ADMIN_RECOVERY_EMAIL. Nadie puede elegir a quién se resetea
    // ni a dónde llega el mail.
    $stmt = $db->prepare("SELECT id, nombre, email FROM usuarios WHERE rol='admin' LIMIT 1");
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $msgGenerico = 'Solicitud enviada.';

    if ($user) {
        $token  = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', time() + 3600); // válido 1 hora

        $upd = $db->prepare('UPDATE usuarios SET reset_token=?, reset_expira=? WHERE id=?');
        $upd->bind_param('ssi', $token, $expira, $user['id']);
        $upd->execute();
        $upd->close();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'];
        $base   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $link   = $base . '/index.html?reset=' . $token;

        $html = "
        <div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222'>
            <h2 style='color:#1B7340'>Tres Reyes · Recuperar contraseña de administrador</h2>
            <p>Se solicitó restablecer la contraseña de la cuenta admin (" . htmlspecialchars($user['email']) . ").</p>
            <p>Hacé clic en el siguiente botón para definir la nueva contraseña:</p>
            <p style='text-align:center;margin:28px 0'>
                <a href='" . htmlspecialchars($link) . "' style='background:#1B7340;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>Definir nueva contraseña</a>
            </p>
            <p style='color:#666;font-size:13px'>Este link expira en 1 hora. Si vos no pediste este cambio, ignorá este mensaje.</p>
        </div>";

        [$ok, $err] = enviarMailSMTP(ADMIN_RECOVERY_EMAIL, 'Recuperar contraseña admin - Tres Reyes', $html);
        if (!$ok) error_log('Error enviando mail de recuperación: ' . $err);
    }

    jsonResponse(['success' => true, 'message' => $msgGenerico]);
}

// ---- Confirmar nueva contraseña con el token del mail ----
if ($action === 'resetear') {
    $token    = trim($input['token'] ?? '');
    $password = $input['password'] ?? '';

    if (!$token) jsonResponse(['success'=>false,'message'=>'Link inválido']);
    if (strlen($password) < 6) jsonResponse(['success'=>false,'message'=>'La contraseña debe tener al menos 6 caracteres']);

    $stmt = $db->prepare('SELECT id FROM usuarios WHERE reset_token=? AND reset_expira > NOW() LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) jsonResponse(['success'=>false,'message'=>'El link expiró o no es válido. Pedí uno nuevo.']);

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $upd = $db->prepare('UPDATE usuarios SET password=?, reset_token=NULL, reset_expira=NULL WHERE id=?');
    $upd->bind_param('si', $hashed, $user['id']);
    $upd->execute();
    $upd->close();

    jsonResponse(['success' => true, 'message' => 'Contraseña actualizada. Ya podés iniciar sesión.']);
}

jsonResponse(['success' => false, 'message' => 'Acción no reconocida'], 400);
$db->close();
