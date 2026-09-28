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
$subsanaciones = new SubsanacionService();

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

$observaciones = [];
foreach ($subsanaciones->observacionesDe($id) as $obs) {
    $limite = (string) $obs['fecha_limite'];
    $observaciones[] = [
        'id' => (string) $obs['id'],
        'detalle' => (string) $obs['detalle'],
        'estado' => (string) $obs['estado'],
        'estado_label' => (string) $obs['estado'] === 'pendiente' ? 'Pendiente de subsanación' : 'Atendida',
        'plazo_dias' => (int) $obs['plazo_dias'],
        'fecha_limite' => AcuseService::formatearFecha($limite),
        'vencida' => $subsanaciones->vencida($limite),
        'usuario' => (string) $obs['usuario'],
        'created_at' => AcuseService::formatearFecha((string) $obs['created_at']),
        'atendida_at' => $obs['atendida_at'] === null ? null : AcuseService::formatearFecha((string) $obs['atendida_at']),
    ];
}

$subs = [];
foreach ($subsanaciones->subsanacionesDe($id) as $sb) {
    $subs[] = [
        'id' => (string) $sb['id'],
        'observacion_id' => $sb['observacion_id'] === null ? null : (string) $sb['observacion_id'],
        'observacion_detalle' => (string) ($sb['observacion_detalle'] ?? ''),
        'nro_cargo' => (string) $sb['nro_cargo'],
        'estado' => (string) $sb['estado'],
        'descripcion' => (string) $sb['descripcion'],
        'nombre' => (string) $sb['nombre'],
        'dni' => (string) $sb['dni'],
        'archivos' => $repo->contarArchivosDeSubsanacion((string) $sb['id']),
        'created_at' => AcuseService::formatearFecha((string) $sb['created_at']),
        'revisada_at' => $sb['revisada_at'] === null ? null : AcuseService::formatearFecha((string) $sb['revisada_at']),
        'revisada_por' => (string) ($sb['revisada_por'] ?? ''),
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
        // El modal usa esto para decidir si ofrece el formulario de observación:
        // solo si el expediente está abierto y no tiene ya un requerimiento vivo.
        'puede_observar' => !in_array((string) $row['status'], ['aprobado', 'denegado', 'observado'], true),
    ],
    'observaciones' => $observaciones,
    'subsanaciones' => $subs,
    'movimientos' => $items,
]);
