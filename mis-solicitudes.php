<?php

declare(strict_types=1);

$config = require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/SubmissionRepository.php';
require_once __DIR__ . '/includes/DataProtector.php';
require_once __DIR__ . '/includes/S3Service.php';

session_name('AMSP_CLIENTE');
session_start();

header('Content-Type: application/json; charset=utf-8');

$dni = trim((string) ($_GET['dni'] ?? ''));
$sessionDni = (string) ($_SESSION[$config['session']['key']] ?? '');

// Solo permite ver las solicitudes del propio asociado logueado.
if ($sessionDni === '' || $dni === '' || !hash_equals($sessionDni, $dni)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

try {
    Setup::ensureDatabase();

    $repo = new SubmissionRepository();

    $solicitudes = [];
    foreach ($repo->porDni($sessionDni) as $row) {
        $files = [];
        foreach ($repo->archivos((string) $row['submission_id']) as $fr) {
            $url = null;
            try {
                $url = S3Service::generateDownloadUrl((string) $fr['s3_key'], 120);
            } catch (Throwable) {
                $url = null;
            }
            $files[] = [
                'originalName' => (string) $fr['original_name'],
                'fileType' => (string) $fr['file_type'],
                'url' => $url,
            ];
        }

        $solicitudes[] = [
            'submission_id' => (string) $row['submission_id'],
            'type' => (string) $row['type'],
            'description' => (string) DataProtector::decrypt((string) $row['description']),
            'status' => (string) $row['status'],
            'fecha_solicitud' => (string) $row['fecha_solicitud'],
            'nro_cargo' => (string) $row['nro_cargo'],
            'nro_expediente' => (string) $row['nro_expediente'],
            'acuse_hash' => (string) $row['acuse_hash'],
            'files' => $files,
        ];
    }

    http_response_code(200);
    echo json_encode(['solicitudes' => $solicitudes]);
} catch (Throwable $e) {
    error_log('[MIS-SOLICITUDES] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar las solicitudes', 'debug' => $e->getMessage()]);
}