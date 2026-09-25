<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

dashboard_require_roles(['admin', 'super-admin']);

$request = ApiRequest::capture();
$request->requireMethod('GET');

try {
    $areas = array_map(static function (array $a): array {
        return ['id' => (int) $a['id'], 'nombre' => (string) $a['nombre'], 'siglas' => (string) $a['siglas']];
    }, (new AreaRepository())->listar(true));
    ApiResponse::json(['areas' => $areas]);
} catch (Throwable $e) {
    error_log('[AREAS] ' . $e->getMessage());
    ApiResponse::error('Error al listar las áreas', 500);
}
