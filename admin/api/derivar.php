<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = dashboard_require_roles(['admin', 'super-admin']);

$request = ApiRequest::capture();
$request->requireMethod('POST');
$request->body();

$id = $request->uuidParam('id');

$areaId = $request->paramInt('areaId');
if ($areaId < 1) {
    ApiResponse::error('Debe seleccionar un área de destino', 400);
}

$motivo = $request->paramText('motivo', 500);

$repo = new SubmissionRepository();

$sub = $repo->porId($id);
if ($sub === null) {
    ApiResponse::error('Solicitud no encontrada', 404);
}

// Validar que el área exista
if (!(new AreaRepository())->existe($areaId)) {
    ApiResponse::error('Área de destino inválida', 400);
}

$deArea = ($sub['area_actual_id'] !== null) ? (int) $sub['area_actual_id'] : null;
$usuario = (string) $user['username'];
$estadoActual = (string) $sub['status'];

try {
    $repo->transaccion(function (SubmissionRepositoryInterface $repo) use ($id, $areaId, $motivo, $deArea, $usuario, $estadoActual): void {
        $repo->actualizarArea($id, $areaId);

        $descripcion = 'Derivación del expediente.' . ($motivo !== '' ? ' Motivo: ' . $motivo : '');
        $repo->registrarMovimiento($id, 'derivacion', $descripcion, $deArea, $areaId, $estadoActual, $usuario);

        // Si estaba pendiente, pasa a revisión al derivar al área competente.
        if ($estadoActual === 'pendiente') {
            $repo->actualizarEstado($id, 'en_revision');
            $repo->registrarMovimiento($id, 'estado', 'El expediente pasa a revisión en el área de destino.', null, $areaId, 'en_revision', $usuario);
        }
    });
} catch (Throwable $e) {
    error_log('[DERIVAR] ' . $e->getMessage());
    ApiResponse::error('No se pudo derivar el expediente', 500);
}

dashboard_log_audit('derivar', ['to_area' => $areaId, 'motivo' => $motivo, 'status' => $estadoActual], 'submissions', $id);

ApiResponse::json(['message' => 'Expediente derivado correctamente', 'status' => 'en_revision']);
