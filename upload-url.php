<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/S3Service.php';

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

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['fileName']) || empty($input['fileType'])) {
    http_response_code(400);
    echo json_encode(['error' => 'fileName y fileType requeridos']);
    exit;
}

if (!in_array($input['fileType'], S3Service::ALLOWED_CONTENT_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo de archivo no permitido']);
    exit;
}

$fileSize = $input['fileSize'] ?? null;
if (!is_numeric($fileSize) || (int) $fileSize < 1 || (int) $fileSize > S3Service::MAX_FILE_SIZE) {
    http_response_code(400);
    echo json_encode(['error' => 'Tamaño de archivo inválido (máx 10MB)']);
    exit;
}

if (strlen((string) $input['fileName']) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Nombre de archivo demasiado largo']);
    exit;
}

// 'subsanacion' no es un tipo de trámite sino el destino de los adjuntos de una
// subsanación: se acepta aquí para que el formulario de subsanación reutilice
// este endpoint en vez de duplicar la firma SigV4. Los datos que se registran
// después los valida SubsanacionService, no este endpoint.
$submissionType = (string) ($input['submissionType'] ?? 'credito');
$validTypes = ['afiliacion', 'pre-evaluacion', 'credito', 'mpv',
    'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
    'prestamo-solidario', 'auxilio-fallecimiento', 'subsanacion'];
if (!in_array($submissionType, $validTypes, true)) {
    $submissionType = 'credito';
}

$submissionId = null;
if (!empty($input['submissionId'])) {
    $submissionId = (string) $input['submissionId'];
    if (strlen($submissionId) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $submissionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'submissionId inválido']);
        exit;
    }
}

try {
    $result = S3Service::generateUploadUrl($input['fileName'], $input['fileType'], $submissionType, (int) $fileSize, $submissionId);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('[UPLOAD-URL] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al generar URL de carga']);
}