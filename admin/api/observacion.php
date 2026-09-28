<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Abre una observación sobre un expediente: el área le informa al presentante
 * qué le falta y le concede un plazo para completarlo.
 *
 * Lo invoca el formulario del modal de seguimiento, no el select de estado: un
 * expediente solo puede pasar a 'observado' con un requerimiento detrás, que es
 * lo que lo hace subsanable.
 */
$user = dashboard_require_roles(['admin', 'super-admin', 'empleado']);

$request = ApiRequest::capture();
$request->requireMethod('POST');
$body = $request->body();

$id = $request->uuidParam('id');
$detalle = $request->paramText('detalle', 1000);
$plazo = $body['plazoDias'] ?? null;

try {
    $resultado = (new SubsanacionService())->registrarObservacion(
        $id,
        $detalle,
        $plazo === null || $plazo === '' ? null : (int) $plazo,
        (string) $user['username']
    );
} catch (ValidationException $e) {
    ApiResponse::json(['error' => $e->getMessage(), 'details' => $e->errores()], 400);
}

dashboard_log_audit('observacion_create', [
    'submissionId' => $id,
    'plazoDias' => $resultado['plazo_dias'],
    'fechaLimite' => $resultado['fecha_limite'],
], 'submissions', $id);

ApiResponse::json([
    'message' => 'Observación registrada. El expediente queda a la espera de la subsanación.',
    'observacion' => [
        'id' => $resultado['id'],
        'detalle' => $resultado['detalle'],
        'plazo_dias' => $resultado['plazo_dias'],
        'fecha_limite' => AcuseService::formatearFecha($resultado['fecha_limite']),
    ],
], 201);
