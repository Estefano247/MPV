<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Api.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/SolicitudService.php';

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

// El formulario de la MPV manda los campos en inglés (name/type/description);
// se aceptan también los nombres en español por compatibilidad con clientes viejos.
$datos = [
    'nombre'      => trim((string) ($input['nombre'] ?? $input['name'] ?? '')),
    'email'       => strtolower(trim((string) ($input['email'] ?? ''))),
    'dni'         => trim((string) ($input['dni'] ?? '')),
    'telefono'    => trim((string) ($input['telefono'] ?? '')),
    'descripcion' => trim((string) ($input['descripcion'] ?? $input['description'] ?? '')),
    'tipo'        => (string) ($input['tipo'] ?? $input['type'] ?? ''),
];

$files = isset($input['files']) && is_array($input['files']) ? $input['files'] : [];
$areaId = (int) ($input['areaId'] ?? 0);

try {
    Setup::ensureDatabase();

    $acuse = (new SolicitudService())->registrar(
        $datos,
        $files,
        isset($input['submissionId']) ? (string) $input['submissionId'] : null,
        $areaId > 0 ? $areaId : null,
        trim((string) ($_SESSION['amsp_cliente_nombre'] ?? ''))
    );

    // `reenvio` avisa de que el id ya estaba registrado (doble clic, reintento
    // tras un timeout). La respuesta es idéntica a la del primer intento, así que
    // el cliente puede mostrar el mismo acuse sin cambios. Se mantiene 201 para no
    // romper el manejo de errores del front, que solo mira res.ok.
    ApiResponse::json([
        'message' => $acuse['reenvio']
            ? 'Esta solicitud ya estaba registrada. Se devuelve el acuse original.'
            : 'Solicitud registrada en la Mesa de Partes Virtual.',
        'submissionId' => $acuse['id'],
        'reenvio' => $acuse['reenvio'],
        'acuse' => [
            'nroCargo' => $acuse['nro_cargo'],
            'nroExpediente' => $acuse['nro_expediente'],
            'hash' => $acuse['acuse_hash'],
        ],
    ], 201);
} catch (ValidationException $e) {
    ApiResponse::json(['error' => $e->getMessage(), 'details' => $e->errores()], 400);
} catch (Throwable $e) {
    error_log('[GUARDAR] ' . $e->getMessage());
    // Sin 'debug': getMessage() puede filtrar el DSN, rutas u otros datos
    // internos. El detalle ya va a error_log.
    ApiResponse::json(['error' => 'Error al guardar la solicitud'], 500);
}