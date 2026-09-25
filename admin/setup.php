<?php

declare(strict_types=1);

/**
 * Configuración del panel administrativo (una sola vez):
 *   1. Crea el esquema completo (solicitudes, MPV, users, audit_log) desde
 *      `schema.sql` si no existe.
 *   2. Crea o actualiza el usuario administrador usando las variables
 *      SEED_ADMIN_USERNAME / SEED_ADMIN_PASSWORD / SEED_ADMIN_ROLE del `.env`.
 *
 * IMPORTANTE: borra o protege este archivo tras usarlo en producción.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Setup.php';
require_once __DIR__ . '/../includes/password.php';
require_once __DIR__ . '/../includes/auth.php'; // define dashboard_config()
require_once __DIR__ . '/../includes/helpers.php'; // define e()

$config = dashboard_config();

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Setup::ensureDatabase(); // submissions, files, users, MPV y audit_log

        $username = $config['seed']['adminUsername'];
        $password = $config['seed']['adminPassword'];
        $role = $config['seed']['adminRole'];

        if ($username === '' || $password === '') {
            throw new RuntimeException('Falta SEED_ADMIN_USERNAME o SEED_ADMIN_PASSWORD en el .env');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $exists = $stmt->fetchAll();

        if ($exists !== []) {
            $hash = (string) $exists[0]['password_hash'];
            if ($hash !== '' && dashboard_verify_password($password, $hash)) {
                $result = "El usuario \"" . e($username) . "\" ya existe y la contraseña coincide. Nada que hacer.";
            } else {
                $db->prepare(
                    'UPDATE users SET password_hash = :h, active = true, role = :r, updated_at = NOW() WHERE username = :u'
                )->execute([':h' => dashboard_hash_password($password), ':r' => $role, ':u' => $username]);
                $result = "Usuario \"" . e($username) . "\" actualizado con nueva contraseña.";
            }
        } else {
            $db->prepare(
                'INSERT INTO users (username, password_hash, role, active) VALUES (:u, :h, :r, true)'
            )->execute([':u' => $username, ':h' => dashboard_hash_password($password), ':r' => $role]);
            $result = "Usuario administrador \"" . e($username) . "\" creado (rol: " . e($role) . ").";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup del Panel | AMSP</title>
    <meta name="robots" content="noindex, nofollow">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 px-4 py-10">
    <div class="mx-auto max-w-lg rounded-2xl border border-gray-200 bg-white p-8 shadow-lg">
        <h1 class="text-lg font-bold text-gray-900 mb-1">Configuración del panel</h1>
        <p class="text-sm text-gray-500 mb-6">Crea la tabla de usuarios y el administrador inicial.</p>

        <?php if ($error !== null): ?>
            <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800"><?= e($error) ?></div>
        <?php elseif ($result !== null): ?>
            <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-800"><?= e($result) ?></div>
            <p class="mb-4 text-sm text-gray-500">
                Ingresa en <a href="login.php" class="text-blue-600 underline">admin/login.php</a> con el usuario configurado.
            </p>
        <?php endif; ?>

        <dl class="mb-6 space-y-2 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Usuario</dt><dd class="font-mono"><?= e($config['seed']['adminUsername']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Rol</dt><dd class="font-mono"><?= e($config['seed']['adminRole']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Contraseña definida</dt><dd><?= $config['seed']['adminPassword'] !== '' ? 'Sí' : '<span class="text-red-600">No (agrégala en el .env)</span>' ?></dd></div>
        </dl>

        <form method="post">
            <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Crear / actualizar administrador</button>
        </form>
    </div>
</body>
</html>