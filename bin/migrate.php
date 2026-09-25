<?php

declare(strict_types=1);

/**
 * Aplica el esquema y crea el usuario administrador inicial.
 *
 * Existe para que el contenedor pueda dejar la base lista en el arranque, sin
 * depender de que alguien abra /admin/setup.php a mano. Repite lo que esa
 * página hace, pero desde línea de comandos y sin mostrar nada por pantalla.
 *
 * Es idempotente: se puede correr en cada `docker compose up` sin efectos
 * colaterales. Si el usuario ya existe y la contraseña no coincide, no la
 * cambia: avisa y sigue, para no dejar al panel sin acceso por un arranque.
 *
 *   php bin/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Setup.php';
require_once __DIR__ . '/../includes/password.php';

$avisos = [];

/**
 * Imprime un aviso tanto en el log del contenedor como en la salida estándar.
 */
function avisar(string $mensaje): void
{
    fwrite(STDERR, "[migrate] {$mensaje}\n");
}

// ---------------------------------------------------------------------------
// 1. Esquema
// ---------------------------------------------------------------------------
try {
    Setup::ensureDatabase();
    fwrite(STDOUT, "esquema aplicado\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'no se pudo aplicar el esquema: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Usuario administrador
// ---------------------------------------------------------------------------
$username = (string) ($config['seed']['adminUsername'] ?? '');
$password = (string) ($config['seed']['adminPassword'] ?? '');
$role = (string) ($config['seed']['adminRole'] ?? 'super-admin');

if ($username === '' || $password === '') {
    // No es un error: se puede levantar el portal sin panel y definirlo después.
    $avisos[] = 'SEED_ADMIN_USERNAME/PASSWORD sin definir: no se creó el usuario del panel.';
} else {
    try {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $existente = $stmt->fetchAll();

        if ($existente === []) {
            $db->prepare(
                'INSERT INTO users (username, password_hash, role, active) VALUES (:u, :h, :r, true)'
            )->execute([':u' => $username, ':h' => dashboard_hash_password($password), ':r' => $role]);
            fwrite(STDOUT, "usuario '{$username}' creado (rol: {$role})\n");
        } else {
            $hash = (string) $existente[0]['password_hash'];
            if ($hash !== '' && dashboard_verify_password($password, $hash)) {
                fwrite(STDOUT, "usuario '{$username}' ya existe\n");
            } else {
                // No se sobrescribe a la fuerza: un cambio de SEED_ADMIN_PASSWORD
                // no debería tumbar el acceso al panel en un entorno de pruebas
                // que ya está en uso.
                $avisos[] = "el usuario '{$username}' existe con otra contraseña: se dejó como está.";
            }
        }
    } catch (Throwable $e) {
        $avisos[] = 'no se pudo revisar el usuario del panel: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// 3. Estado de S3
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
    avisar($aviso);
}

fwrite(STDOUT, "migración terminada\n");
exit(0);
