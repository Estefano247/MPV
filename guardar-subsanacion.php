<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Api.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/SubsanacionService.php';

session_name('AMSP_CLIENTE');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
$esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.cookie_secure', $esHttps ? '1' : '0');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $esHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$input = json_decode((string) file_get_contents('php://input'), true) ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ApiResponse::error('Método no permitido', 405);
}

$token = (string) ($input['_csrf'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    ApiResponse::error('Token CSRF inválido. Recarga la página e inténtalo de nuevo.', 403);
}

$entrada = [
    'numero' => trim((string) ($input['numero'] ?? '')),
    'dni' => preg_replace('/\D/', '', (string) ($input['dni'] ?? '')) ?? '',
    'descripcion' => trim((string) ($input['descripcion'] ?? '')),
    'nombre' => trim((string) ($input['nombre'] ?? '')),
    'email' => strtolower(trim((string) ($input['email'] ?? ''))),
    'telefono' => trim((string) ($input['telefono'] ?? '')),
];

$files = isset($input['files']) && is_array($input['files']) ? $input['files'] : [];

try {
    Setup::ensureDatabase();

    $acuse = (new SubsanacionService())->registrarSubsanacion(
        $entrada,
        $files,
        isset($input['subsanacionId']) ? (string) $input['subsanacionId'] : null
    );

    // `reenvio` avisa de que el id ya estaba registrado (doble clic, reintento
    // tras un timeout). La respuesta es la del primer intento, así que el
    // cliente puede mostrar el mismo acuse sin cambios. Se conserva el 201 para
    // no romper el manejo de errores del script de la página, que solo mira res.ok.
    ApiResponse::json([
        'message' => $acuse['reenvio']
            ? 'Esta subsanación ya estaba registrada. Se devuelve el acuse original.'
            : 'Subsanación registrada. El área responsable verificará lo presentado.',
        'subsanacionId' => $acuse['id'],
        'reenvio' => $acuse['reenvio'],
        'acuse' => [
            'nroCargo' => $acuse['nro_cargo'],
            'hash' => $acuse['acuse_hash'],
        ],
    ], 201);
} catch (ValidationException $e) {
    ApiResponse::json(['error' => $e->getMessage(), 'details' => $e->errores()], 400);
} catch (Throwable $e) {
    error_log('[GUARDAR-SUBSANACION] ' . $e->getMessage());
    ApiResponse::json(['error' => 'Error al registrar la subsanación'], 500);
}