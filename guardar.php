<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Api.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/SolicitudService.php';

session_name('AMSP_CLIENTE');
session_start();

$input = json_decode((string) file_get_contents('php://input'), true) ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ApiResponse::error('Método no permitido', 405);
}

$token = (string) ($input['_csrf'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    ApiResponse::error('Token CSRF inválido. Recarga la página e inténtalo de nuevo.', 403);
}

// El cliente es legacy y manda dos juegos de nombres: afiliacion.php usa
// nombre/tipo/descripcion, cliente.js (crédito/pre-evaluación) name/type/description.
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
    // romper el manejo de errores de cliente.js, que solo mira res.ok.
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
    ApiResponse::json(['error' => 'Error al guardar la solicitud', 'debug' => $e->getMessage()], 500);
}
