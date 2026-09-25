<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

dashboard_guard_api();

$request = ApiRequest::capture();
$request->requireMethod('GET');

$id = $request->uuidParam('id');

$repo = new SubmissionRepository();

$row = $repo->porId($id);
if ($row === null) {
    ApiResponse::error('Solicitud no encontrada', 404);
}

$movimientos = $repo->movimientos($id);

$items = [];
foreach ($movimientos as $mv) {
    $items[] = [
        'id' => (int) $mv['id'],
        'tipo' => (string) $mv['tipo'],
        'estado' => (string) $mv['estado'],
        'descripcion' => (string) $mv['descripcion'],
        'usuario' => (string) $mv['usuario'],
        'de_area' => (string) $mv['de_area'],
        'a_area' => (string) $mv['a_area'],
        'created_at' => AcuseService::formatearFecha((string) $mv['created_at']),
    ];
}

ApiResponse::json([
    'expediente' => [
        'id' => (string) $row['submission_id'],
        'nro_cargo' => (string) $row['nro_cargo'],
        'nro_expediente' => (string) $row['nro_expediente'],
        'status' => (string) $row['status'],
        'tipo' => (string) $row['type'],
        'name' => (string) $row['name'],
        'dni' => (string) $row['dni'],
        'fecha' => AcuseService::formatearFecha((string) $row['fecha_solicitud']),
        'area_actual' => (string) $row['area_actual'],
        'status_label' => dashboard_status_label((string) $row['status']),
        'tipo_label' => dashboard_type_label((string) $row['type']),
    ],
    'movimientos' => $items,
]);
