<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

dashboard_guard_api();

$request = ApiRequest::capture();
$request->requireMethod('POST');
$request->body();

$id = $request->uuidParam('id');
$status = $request->param('status');

if (!in_array($status, DASHBOARD_ALLOWED_STATUSES, true)) {
    ApiResponse::error('Estado inválido', 400);
}

$repo = new SubmissionRepository();

$row = $repo->porId($id);
if ($row === null) {
    ApiResponse::error('Solicitud no encontrada', 404);
}

$current = (string) $row['status'];
$conflict = dashboard_assert_transition($current, $status);
if ($conflict !== null) {
    ApiResponse::error($conflict, 400);
}

$repo->actualizarEstado($id, $status);

$user = dashboard_current_user();
$repo->registrarMovimiento(
    $id,
    $status === 'aprobado' || $status === 'denegado' ? 'resolucion' : 'estado',
    'Estado actualizado de "' . dashboard_status_label($current) . '" a "' . dashboard_status_label($status) . '".',
    null,
    null,
    $status,
    (string) ($user['username'] ?? 'admin')
);

dashboard_log_audit('status_change', ['from' => $current, 'to' => $status], 'submissions', $id);

ApiResponse::json(['message' => 'Estado actualizado exitosamente']);
