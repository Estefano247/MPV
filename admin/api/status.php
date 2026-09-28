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

$user = dashboard_current_user();
$username = (string) ($user['username'] ?? 'admin');

$repo->actualizarEstado($id, $status);

// Salir de 'observado' por el selector de estado significa que el área se desistió
// del requerimiento. La observación se cierra para que no quede pendiente de un
// expediente que ya no la espera, y para que el presentante no vea en el
// seguimiento un plazo que ya no vigila nadie.
if ($current === 'observado') {
    (new SubsanacionService())->desestimar($id, $username);
}

$repo->registrarMovimiento(
    $id,
    $status === 'aprobado' || $status === 'denegado' ? 'resolucion' : 'estado',
    'Estado actualizado de "' . dashboard_status_label($current) . '" a "' . dashboard_status_label($status) . '".',
    null,
    null,
    $status,
    $username
);

dashboard_log_audit('status_change', ['from' => $current, 'to' => $status], 'submissions', $id);

ApiResponse::json(['message' => 'Estado actualizado exitosamente']);
