<?php

declare(strict_types=1);

$showPhpInfo = getenv('PHPINFO') === '1';

if ($showPhpInfo) {
    phpinfo();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$checks = [];

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'db',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: ''
    );

    $pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,

        // Conexión a MySQL cifrada con TLS 1.3.
        //
        // MySQL 8.4 ya trae certificados autogenerados, así que no
        // hay nada que configurar en el servidor.
        //
        // ATRIB_SSL_CIPHER por sí solo NO basta: activa el cifrado
        // pero deja la verificación activa y la conexión falla con
        // 'Cannot connect to MySQL using SSL'. Por eso va
        // acompañado de VERIFY_SERVER_CERT => false, que es
        // inevitable aquí: el certificado es autofirmado y no
        // tenemos su CA. Cifra el tráfico, pero no autentica al
        // servidor, así que no protege frente a un MITM activo.
        //
        // Ojo: 'sslmode' en el DSN es de mysqli, PDO lo ignora en
        // silencio. Y las constantes PDO::MYSQL_ATTR_* están
        // deprecadas desde PHP 8.5 en favor de Pdo\Mysql::*.
        \Pdo\Mysql::ATTR_SSL_CIPHER             => 'DHE-RSA-AES256-GCM-SHA384',
        \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);

    $pdo->query('SELECT 1');
    $checks['Base de datos'] = ['ok', 'conexión establecida'];

    $ssl = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '';
    $checks['Cifrado BD'] = $ssl !== ''
        ? ['ok', $ssl]
        : ['ko', 'conexión sin cifrar'];
} catch (PDOException $e) {
    $checks['Base de datos'] = ['ko', 'sin conexión'];
    $checks['Cifrado BD'] = ['ko', 'sin comprobar'];
}

$checks['Versión de PHP'] = ['ok', PHP_VERSION];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Estado del servicio</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            max-width: 44rem; margin: 3rem auto; padding: 0 1.25rem;
            line-height: 1.55;
        }
        h1 { font-size: 1.4rem; margin-bottom: 0.25rem; }
        p.sub { margin-top: 0; opacity: 0.7; font-size: 0.9rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1.5rem; }
        th, td { text-align: left; padding: 0.6rem 0.5rem; border-bottom: 1px solid rgba(128,128,128,0.3); }
        th { font-weight: 600; width: 14rem; }
        .ok  { color: #1a7f37; font-weight: 600; }
        .ko  { color: #b42318; font-weight: 600; }
        footer { margin-top: 2.5rem; font-size: 0.8rem; opacity: 0.6; }
        code { font-size: 0.85em; }
    </style>
</head>
<body>
    <h1>Estado del servicio</h1>
    <p class="sub">PHP <?= $h(PHP_VERSION) ?> sobre Apache &mdash; proyecto RA1</p>

    <table>
        <?php foreach ($checks as $name => [$state, $detail]): ?>
            <tr>
                <th scope="row"><?= $h($name) ?></th>
                <td>
                    <span class="<?= $state === 'ok' ? 'ok' : 'ko' ?>">
                        <?= $state === 'ok' ? 'correcto' : 'fallo' ?>
                    </span>
                    &mdash; <?= $h($detail) ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <footer>
        Esta página no expone datos de configuraci&oacute;n ni secretos.
        Para diagnosticar en local, activa <code>PHPINFO=1</code> en el
        fichero <code>.env</code> y reinicia el servicio.
    </footer>
</body>
</html>
