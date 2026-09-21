<?php
/**
 * Cliente SMTP minimalista, sin dependencias externas.
 * Pensado para enviar mails vía Gmail (SMTP + STARTTLS) desde hosts
 * como InfinityFree, donde la función mail() nativa de PHP está deshabilitada.
 *
 * Requiere que en config.php estén definidas las constantes:
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM_NAME
 */

function enviarMailSMTP($destinatario, $asunto, $cuerpoHtml) {
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        return [false, 'Falta configurar las constantes SMTP en config.php'];
    }

    $host     = SMTP_HOST;
    $port     = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Notificaciones';

    $errno = 0; $errstr = '';
    $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 15);
    if (!$socket) return [false, "No se pudo conectar al servidor SMTP ($host:$port): $errstr"];
    stream_set_timeout($socket, 15);

    $leer = function() use ($socket) {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            // La última línea de una respuesta multi-línea tiene un espacio después del código (ej: "250 ")
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $escribir = function($cmd) use ($socket) { fwrite($socket, $cmd . "\r\n"); };

    $leer(); // banner del servidor

    $escribir('EHLO tresreyes.local');
    $leer();

    $escribir('STARTTLS');
    $r = $leer();
    if (strpos($r, '220') !== 0) { fclose($socket); return [false, "STARTTLS rechazado: $r"]; }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return [false, 'No se pudo iniciar la conexión cifrada TLS'];
    }

    $escribir('EHLO tresreyes.local');
    $leer();

    $escribir('AUTH LOGIN');
    $leer();
    $escribir(base64_encode($user));
    $leer();
    $escribir(base64_encode($pass));
    $r = $leer();
    if (strpos($r, '235') !== 0) { fclose($socket); return [false, "Autenticación SMTP fallida (revisá usuario/contraseña de aplicación): $r"]; }

    $escribir("MAIL FROM:<$user>");
    $leer();
    $escribir("RCPT TO:<$destinatario>");
    $r = $leer();
    if (strpos($r, '250') !== 0) { fclose($socket); return [false, "Destinatario rechazado: $r"]; }

    $escribir('DATA');
    $r = $leer();
    if (strpos($r, '354') !== 0) { fclose($socket); return [false, "Servidor no esperaba datos: $r"]; }

    // Dot-stuffing: si alguna línea del cuerpo empieza con ".", se duplica
    $cuerpoSeguro = preg_replace('/^\./m', '..', $cuerpoHtml);

    $headers  = "From: $fromName <$user>\r\n";
    $headers .= "To: <$destinatario>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $escribir($headers . "\r\n" . $cuerpoSeguro . "\r\n.");
    $r = $leer();

    $escribir('QUIT');
    $leer();
    fclose($socket);

    if (strpos($r, '250') !== 0) return [false, "El servidor no aceptó el mensaje: $r"];
    return [true, 'OK'];
}
