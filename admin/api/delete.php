<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

dashboard_guard_api();

$request = ApiRequest::capture();
$request->requireMethod('POST');
$request->body();

$id = $request->uuidParam('id');

$repo = new SubmissionRepository();

$submission = $repo->porId($id);
$s3Keys = $repo->s3Keys($id);

if ($s3Keys === [] && $submission === null) {
    ApiResponse::error('Solicitud no encontrada', 404);
}

// Borrar objetos de S3 (no romper el borrado si falla un objeto)
foreach ($s3Keys as $s3Key) {
    try {
        S3Service::deleteObject($s3Key);
    } catch (Throwable $e) {
        error_log('[DELETE] Error al borrar S3 ' . $s3Key . ': ' . $e->getMessage());
    }
}

$repo->eliminar($id);

dashboard_log_audit(
    'delete_submission',
    ['name' => $submission !== null ? (string) $submission['name'] : null, 'files' => count($s3Keys)],
    'submissions',
    $id
);

ApiResponse::json(['message' => 'Solicitud eliminada exitosamente']);
