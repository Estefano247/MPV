<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

dashboard_guard_api();

$request = ApiRequest::capture();
$request->requireMethod('GET');

$id = $request->uuidParam('id');

$repo = new SubmissionRepository();

$submission = $repo->porId($id);
if ($submission === null) {
    ApiResponse::error('Solicitud no encontrada', 404);
}

dashboard_log_audit('view_files', ['name' => (string) $submission['name']], 'submissions', $id);

$files = [];
foreach ($repo->archivos($id) as $row) {
    $url = null;
    $previewUrl = null;
    try {
        $url = S3Service::generateDownloadUrl((string) $row['s3_key'], 60);
        // Sin response-content-disposition=attachment: el navegador sirve el
        // PDF en línea en vez de descargarlo.
        $previewUrl = S3Service::generateDownloadUrl((string) $row['s3_key'], 120, true);
    } catch (Throwable) {
        $url = null;
        $previewUrl = null;
    }

    $createdAt = null;
    if ($row['created_at'] !== null) {
        try {
            $createdAt = (new DateTimeImmutable((string) $row['created_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            $createdAt = null;
        }
    }

    $files[] = [
        'fileId' => (string) $row['file_id'],
        's3Key' => (string) $row['s3_key'],
        'originalName' => (string) $row['original_name'],
        'fileType' => (string) $row['file_type'],
        'url' => $url,
        'previewUrl' => $previewUrl,
        'createdAt' => $createdAt,
        // Marca los documentos que el presentante adjuntó al subsanar, para que
        // el panel los distinga de los del alta original.
        'subsanacionId' => $row['subsanacion_id'] === null ? null : (string) $row['subsanacion_id'],
    ];
}

ApiResponse::json($files);
