<?php

declare(strict_types=1);

/**
 * Configuración del panel administrativo (reparación puntual):
 *   1. Crea el esquema completo (solicitudes, MPV, users, audit_log) desde
 *      `schema.sql` si no existe.
 *   2. Sincroniza los dos usuarios del panel (superadmin y admin) con las
 *      variables SEED_ADMIN_* / SEED_STAFF_* del `.env`.
 *
 * Requiere sesión de panel (super-admin o admin): sin ella no se ejecuta nada.
 * Este archivo era una página abierta que creaba usuarios y reescribía
 * contraseñas desde el .env, accesible para cualquiera que la adivinara.
 * La app hace lo mismo sola en cada request (Setup::ensureDatabase()), así que
 * la única razón para venir aquí es reparar una base rota.
 *
 * Igual: borra este archivo tras usarlo en producción.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Setup.php';
require_once __DIR__ . '/../includes/password.php';
require_once __DIR__ . '/../includes/auth.php'; // define dashboard_config()
require_once __DIR__ . '/../includes/dashboard.php'; // define dashboard_guard_page()
require_once __DIR__ . '/../includes/helpers.php'; // define e()

Setup::ensureDatabase();

// Salta a login.php y termina si no hay sesión de panel.
dashboard_guard_page();

$config = dashboard_config();

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Setup::ensureDatabase(); // submissions, files, users, MPV y audit_log + seed

        if (Setup::ultimoError() !== null) {
            throw new RuntimeException(Setup::ultimoError());
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT username, role, active, password_hash FROM users WHERE username = :u OR username = :u2'
        );
        $stmt->execute([
            ':u' => $config['seed']['adminUsername'],
            ':u2' => $config['seed']['staffUsername'],
        ]);
        $filas = $stmt->fetchAll();

        $parts = [];
        foreach ($filas as $fila) {
            $ok = dashboard_verify_password(
                $fila['username'] === $config['seed']['adminUsername']
                    ? $config['seed']['adminPassword']
                    : $config['seed']['staffPassword'],
                (string) $fila['password_hash']
            );
            $parts[] = e((string) $fila['username'])
                . ' (rol: ' . e((string) $fila['role']) . ') — contraseña del .env: '
                . ($ok ? 'coincide' : '<span class="text-red-600">NO coincide</span>');
        }
        $result = 'Esquema aplicado y usuarios sincronizados con el .env. '
            . implode(' · ', $parts);
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
        <p class="text-sm text-gray-500 mb-6">Crea la tabla de usuarios y sincroniza superadmin y admin con el .env.</p>

        <?php if ($error !== null): ?>
            <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800"><?= e($error) ?></div>
        <?php elseif ($result !== null): ?>
            <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-800"><?= $result ?></div>
            <p class="mb-4 text-sm text-gray-500">
                Ingresa en <a href="login.php" class="text-blue-600 underline">admin/login.php</a> con cualquiera de los dos usuarios.
            </p>
        <?php endif; ?>

        <dl class="mb-6 space-y-4 text-sm">
            <?php foreach ([['super-admin', 'admin'], ['admin', 'staff']] as [$rolEsperado, $clave]):
                $uKey = $clave . 'Username';
                $rKey = $clave . 'Role';
                $pKey = $clave . 'Password';
                $u = (string) ($config['seed'][$uKey] ?? '');
                if ($u === '') { continue; } ?>
                <div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Usuario</dt><dd class="font-mono"><?= e($u) ?></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Rol</dt><dd class="font-mono"><?= e((string) ($config['seed'][$rKey] ?? $rolEsperado)) ?></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Contraseña en el .env</dt><dd><?= ($config['seed'][$pKey] ?? '') !== '' ? 'Sí' : '<span class="text-red-600">No (agrégala en el .env)</span>' ?></dd></div>
                </div>
            <?php endforeach; ?>
        </dl>

        <form method="post">
            <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Crear / sincronizar usuarios</button>
        </form>
    </div>
</body>
</html>