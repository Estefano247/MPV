<?php

declare(strict_types=1);

/**
 * Deja la base lista en el arranque del contenedor local.
 *
 * Repite lo que la app hace sola en cada request (Setup::ensureDatabase) pero
 * desde línea de comandos y sin mostrar nada por pantalla: crea el esquema si
 * no existe y, al crearlo, siembra el usuario admin desde SEED_ADMIN_*.
 *
 * Es idempotente: se puede correr en cada `docker compose up` sin efectos
 * colaterales. Si el esquema ya existe no ejecuta nada (misma comprobación
 * barata de Setup::ensureDatabase).
 *
 *   php bin/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Setup.php';

try {
    Setup::ensureDatabase();
    fwrite(STDOUT, "esquema aplicado\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'no se pudo aplicar el esquema: ' . $e->getMessage() . "\n");
    exit(1);
}

$config = require __DIR__ . '/../includes/config.php';

$avisos = [];

// ---------------------------------------------------------------------------
// Estado de S3
// ---------------------------------------------------------------------------
// No bloquea el arranque: sin bucket se puede revisar el portal y el panel,
// lo que falla es subir adjuntos.
$s3 = $config['s3'] ?? [];
$aws = $config['aws'] ?? [];
$sinS3 = ($s3['bucketName'] ?? '') === ''
    || ($aws['accessKeyId'] ?? '') === ''
    || ($aws['secretAccessKey'] ?? '') === '';
if ($sinS3) {
    $avisos[] = 'S3 sin configurar: el portal funciona, pero los adjuntos no se pueden subir.';
}

foreach ($avisos as $aviso) {
    fwrite(STDERR, "[migrate] {$aviso}\n");
}

fwrite(STDOUT, "migración terminada\n");
exit(0);