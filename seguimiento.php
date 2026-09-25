<?php

declare(strict_types=1);

$config = require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/View.php';
require_once __DIR__ . '/includes/SubmissionRepository.php';
require_once __DIR__ . '/includes/AcuseService.php';
require_once __DIR__ . '/includes/DataProtector.php';

session_name('AMSP_CLIENTE');
session_start();

$mpv = $config['mpv'];

$consulta = trim((string) ($_POST['consulta'] ?? $_GET['consulta'] ?? ''));
$dni = trim((string) ($_POST['dni'] ?? $_GET['dni'] ?? ''));
$dni = preg_replace('/\D/', '', $dni);

$resultado = null;
$error = null;
$movimientos = [];

if (($consulta !== '' || $dni !== '') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!preg_match('/^\d{8}$/', $dni)) {
        $error = 'Debe ingresar un DNI válido de 8 dígitos.';
    }
    if ($consulta === '' || (strlen($consulta) > 40 || !preg_match('/^[A-Za-z0-9-]+$/', $consulta))) {
        $error = 'Ingrese el Nº de cargo o Nº de expediente recibido en su acuse.';
    }

    if ($error === null) {
        try {
            Setup::ensureDatabase();
            $repo = new SubmissionRepository();
            $row = $repo->buscarPorAcuse($dni, $consulta);

            if ($row === null) {
                $error = 'No se encontró ningún trámite con esos datos. Verifique el número y su DNI.';
            } else {
                $resultado = [
                    'id' => (string) $row['submission_id'],
                    'nro_cargo' => (string) $row['nro_cargo'],
                    'nro_expediente' => (string) $row['nro_expediente'],
                    'tipo' => (string) $row['type'],
                    'nombre' => (string) $row['name'],
                    'dni' => (string) $row['dni'],
                    'descripcion' => DataProtector::decrypt((string) $row['description']),
                    'status' => (string) $row['status'],
                    'fecha_solicitud' => (string) $row['fecha_solicitud'],
                    'area_actual' => (string) $row['area_actual'],
                    'acuse_hash' => (string) $row['acuse_hash'],
                ];
                $movimientos = $repo->movimientos((string) $row['submission_id']);
            }
        } catch (Throwable $e) {
            error_log('[SEGUIMIENTO] ' . $e->getMessage());
            $error = 'No fue posible consultar el seguimiento. Inténtelo más tarde.';
        }
    }
}
?>
<?php View::head('Seguimiento de trámites | ' . (string) $mpv['titulo'], $config, 'seguimiento'); ?>
?>

            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">Seguimiento del trámite</h1>
            <p class="text-sm text-gray-500 mb-6">Consulte el estado de su expediente con el número de su acuse de recibo.</p>

            <div class="bg-white rounded-2xl shadow-lg border p-4 sm:p-6 max-w-xl">
                <form method="post" class="space-y-4">
                    <div>
                        <label for="consulta" class="block text-sm font-medium text-gray-700 mb-1">Nº de cargo o Nº de expediente</label>
                        <input id="consulta" name="consulta" type="text" required maxlength="40"
                               value="<?= e($consulta) ?>"
                               placeholder="Ej. C-2026-000123"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="dni" class="block text-sm font-medium text-gray-700 mb-1">DNI del titular</label>
                        <input id="dni" name="dni" type="text" required maxlength="8" inputmode="numeric"
                               value="<?= e($dni) ?>"
                               oninput="this.value=this.value.replace(/\D/g,'').slice(0,8)"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 transition-colors">Consultar</button>
                </form>
            </div>

            <?php if ($error !== null): ?>
                <div class="mt-6 flex items-center gap-2 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($resultado !== null): ?>
                <div class="mt-6 bg-white rounded-2xl shadow-lg border overflow-hidden">
                    <div class="px-4 sm:px-6 py-3 border-b flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-gray-800">Expediente <?= e($resultado['nro_expediente']) ?></h2>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                                <?= match ($resultado['status']) {
                                    'aprobado' => 'bg-emerald-100 text-emerald-700',
                                    'denegado' => 'bg-red-100 text-red-700',
                                    'en_revision' => 'bg-blue-100 text-blue-700',
                                    default => 'bg-amber-100 text-amber-700',
                                } ?>">
                                <?= e(AcuseService::ESTADOS[$resultado['status']] ?? $resultado['status']) ?>
                            </span>
                            <a href="acuse.php?id=<?= urlencode($resultado['id']) ?>&t=<?= urlencode($resultado['acuse_hash']) ?>"
                               class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100">Ver acuse</a>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div><span class="text-xs text-gray-500 uppercase">Nº de cargo</span><p class="font-semibold"><?= e($resultado['nro_cargo']) ?></p></div>
                        <div><span class="text-xs text-gray-500 uppercase">Tipo de trámite</span><p class="font-semibold"><?= e(AcuseService::labelTipo($resultado['tipo'])) ?></p></div>
                        <div><span class="text-xs text-gray-500 uppercase">Remitente</span><p class="font-semibold"><?= e($resultado['nombre']) ?></p></div>
                        <div><span class="text-xs text-gray-500 uppercase">DNI</span><p class="font-semibold"><?= e($resultado['dni']) ?></p></div>
                        <div><span class="text-xs text-gray-500 uppercase">Asunto</span><p><?= e($resultado['descripcion']) ?></p></div>
                        <div><span class="text-xs text-gray-500 uppercase">Área actual</span><p><?= e($resultado['area_actual'] !== '' ? $resultado['area_actual'] : 'Mesa de Partes Virtual') ?></p></div>
                        <div><span class="text-xs text-gray-500 uppercase">Presentación</span><p><?= e(AcuseService::formatearFecha($resultado['fecha_solicitud'])) ?></p></div>
                    </div>
                </div>

                <div class="mt-6 bg-white rounded-2xl shadow-lg border overflow-hidden">
                    <div class="px-4 sm:px-6 py-3 border-b">
                        <h2 class="text-sm font-semibold text-gray-800">Movimientos del expediente</h2>
                    </div>
                    <?php if ($movimientos === []): ?>
                        <div class="p-6 text-sm text-gray-500">Sin movimientos registrados.</div>
                    <?php else: ?>
                        <ol class="p-4 sm:p-6 space-y-4">
                            <?php foreach ($movimientos as $i => $m): ?>
                                <?php
                                $tipoLabel = match ((string) $m['tipo']) {
                                    'registro' => 'Registro',
                                    'derivacion' => 'Derivación',
                                    'estado' => 'Cambio de estado',
                                    'resolucion' => 'Resolución',
                                    default => ucfirst((string) $m['tipo']),
                                };
                                $circle = $i === count($movimientos) - 1 ? 'bg-blue-600' : 'bg-gray-400';
                                ?>
                                <li class="relative pl-8">
                                    <span class="absolute left-0 top-1 flex h-4 w-4 items-center justify-center rounded-full ring-4 ring-white <?= $circle ?>"></span>
                                    <p class="text-sm font-semibold text-gray-900"><?= e($tipoLabel) ?>
                                        <span class="ml-2 text-xs font-normal text-gray-400"><?= e(AcuseService::formatearFecha((string) $m['created_at'])) ?></span>
                                    </p>
                                    <p class="text-sm text-gray-600"><?= e((string) $m['descripcion']) ?></p>
                                    <p class="text-xs text-gray-500">
                                        <?php if ((string) $m['de_area'] !== ''): ?>De: <?= e((string) $m['de_area']) ?> &rarr; <?php endif; ?>
                                        <?php if ((string) $m['a_area'] !== ''): ?>A: <?= e((string) $m['a_area']) ?><?php endif; ?>
                                        <?php if ((string) $m['usuario'] !== ''): ?>&middot; por <?= e((string) $m['usuario']) ?><?php endif; ?>
                                    </p>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
<?php View::footer($config); ?>
<?php View::fin(); ?>
