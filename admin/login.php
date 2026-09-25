<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/password.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

if (dashboard_current_user() !== null) {
    header('Location: index.php');
    exit;
}

const MAX_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

$username = '';
$error = null;
$locked = false;
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '') {
        $fieldErrors['username'] = 'El usuario es requerido';
    }
    if ($password === '') {
        $fieldErrors['password'] = 'La contraseña es requerida';
    }

    if ($fieldErrors === []) {
        @set_time_limit(120); // el verify de scrypt puro puede tomar ~3 s
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id, username, password_hash, role, active, locked_until, failed_login_attempts
               FROM users
              WHERE username = :username'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetchAll()[0] ?? null;

        if ($user === null) {
            usleep(120000); // 120 ms: reducir el timing de enumeración
            dashboard_log_audit('login_failed', ['reason' => 'user_not_found']);
            $error = 'Credenciales inválidas';
        } else {
            $lockedUntil = $user['locked_until'] !== null && $user['locked_until'] !== ''
                ? strtotime((string) $user['locked_until'])
                : null;

            if ($lockedUntil !== null && $lockedUntil > time()) {
                $locked = true;
                dashboard_log_audit('login_failed', ['reason' => 'locked', 'username' => (string) $user['username']]);
                $error = 'Cuenta bloqueada temporalmente por muchos intentos fallidos';
            } elseif ((int) $user['active'] !== 1 && (bool) $user['active'] !== true) {
                dashboard_log_audit('login_failed', ['reason' => 'inactive', 'username' => (string) $user['username']]);
                $error = 'Credenciales inválidas';
            } elseif (!dashboard_verify_password($password, (string) $user['password_hash'])) {
                $failed = (int) $user['failed_login_attempts'] + 1;
                if ($failed >= MAX_ATTEMPTS) {
                    $db->prepare(
                        'UPDATE users
                            SET failed_login_attempts = :failed,
                                locked_until = NOW() + (:minutes || \' minutes\')::interval
                          WHERE id = :id'
                    )->execute([':failed' => $failed, ':minutes' => LOCKOUT_MINUTES, ':id' => $user['id']]);
                } else {
                    $db->prepare('UPDATE users SET failed_login_attempts = :failed WHERE id = :id')
                        ->execute([':failed' => $failed, ':id' => $user['id']]);
                }
                usleep(120000);
                dashboard_log_audit('login_failed', ['reason' => 'bad_password', 'username' => (string) $user['username']]);
                $error = 'Credenciales inválidas';
            } else {
                $db->prepare(
                    'UPDATE users
                        SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW()
                      WHERE id = :id'
                )->execute([':id' => $user['id']]);

                $token = dashboard_create_token([
                    'id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                    'role' => (string) $user['role'],
                ]);
                dashboard_set_session_cookie($token);
                dashboard_log_audit('login', ['role' => (string) $user['role']], 'users', (string) $user['id']);
                header('Location: index.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo | AMSP</title>
    <meta name="robots" content="noindex, nofollow">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 px-4 flex items-center justify-center">
    <div class="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 shadow-lg">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-950">
                <span class="font-bold text-white">AMSP</span>
            </div>
            <h1 class="text-xl font-bold text-gray-900">Panel Administrativo</h1>
            <p class="mt-1 text-sm text-gray-500">Solicitudes de crédito, afiliación y pre-evaluación</p>
        </div>

        <form method="post" class="space-y-4" novalidate>
            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-gray-700">Usuario</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    autocomplete="username"
                    maxlength="50"
                    value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                    class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600 <?= isset($fieldErrors['username']) ? 'border-red-300' : 'border-gray-300' ?>"
                    placeholder="Nombre de usuario"
                >
                <?php if (isset($fieldErrors['username'])): ?>
                    <p class="mt-1 text-xs text-red-600"><?= htmlspecialchars($fieldErrors['username'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Contraseña</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600 <?= isset($fieldErrors['password']) ? 'border-red-300' : 'border-gray-300' ?>"
                    placeholder="••••••••"
                >
                <?php if (isset($fieldErrors['password'])): ?>
                    <p class="mt-1 text-xs text-red-600"><?= htmlspecialchars($fieldErrors['password'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>

            <?php if ($error !== null): ?>
                <div class="flex items-center gap-2 rounded-lg p-3 text-sm <?= $locked ? 'bg-orange-50 text-orange-800' : 'bg-red-50 text-red-800' ?>" role="alert">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-700">
                Ingresar
            </button>
        </form>
    </div>
</body>
</html>