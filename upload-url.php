<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/S3Service.php';

session_name('AMSP_CLIENTE');
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

$submissionType = (string) ($input['submissionType'] ?? 'credito');
$validTypes = ['afiliacion', 'pre-evaluacion', 'credito', 'mpv',
    'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
    'prestamo-solidario', 'auxilio-fallecimiento'];
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