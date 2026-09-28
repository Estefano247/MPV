<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Califica una subsanación presentada por el presentante: el área la acepta (el
 * expediente vuelve a revisión) o la rechaza (el requerimiento sigue en pie con
 * un plazo nuevo).
 */
$user = dashboard_require_roles(['admin', 'super-admin', 'empleado']);

$request = ApiRequest::capture();
$request->requireMethod('POST');
$request->body();

$id = $request->uuidParam('id');
$decision = $request->param('decision');

try {
    $resultado = (new SubsanacionService())->revisarSubsanacion($id, $decision, (string) $user['username']);
} catch (ValidationException $e) {
    ApiResponse::json(['error' => $e->getMessage(), 'details' => $e->errores()], 400);
}

dashboard_log_audit('subsanacion_review', [
    'subsanacionId' => $id,
    'decision' => $decision,
    'status' => $resultado['status'],
], 'subsanaciones', $id);

ApiResponse::json([
    'message' => $decision === 'aceptada'
        ? 'Subsanación aceptada. El expediente vuelve a revisión.'
        : 'Subsanación rechazada. El requerimiento se mantiene con un nuevo plazo.',
    'subsanacion' => [
        'id' => $resultado['subsanacion_id'],
        'estado' => $resultado['estado'],
    ],
    'status' => $resultado['status'],
    'status_label' => dashboard_status_label($resultado['status']),
]);
