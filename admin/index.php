<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/SubmissionRepository.php';

$user = dashboard_guard_page();

$config = dashboard_config();

// Filtros (parámetros GET, igual que /api/admin/submissions del dashboard Node)
$tipo = (string) ($_GET['tipo'] ?? 'credito');
if (!in_array($tipo, DASHBOARD_ALLOWED_TYPES, true)) {
    $tipo = 'credito';
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 50) {
    $q = mb_substr($q, 0, 50);
}

$statuses = [];
$estadoRaw = trim((string) ($_GET['estado'] ?? ''));
foreach (explode(',', $estadoRaw) as $piece) {
    $piece = trim($piece);
    if (in_array($piece, DASHBOARD_ALLOWED_STATUSES, true)) {
        $statuses[] = $piece;
    }
}

$page = max(1, (int) ($_GET['pagina'] ?? 1));
$limit = min(100, max(1, (int) ($_GET['limite'] ?? 10)));

$repo = new SubmissionRepository();
$filtros = ['tipo' => $tipo, 'buscar' => $q, 'estados' => $statuses];

// Conteos por estado (para las tarjetas y la paginación)
$counts = $repo->contarPorEstado($filtros);

$total = (int) array_sum($counts);
$pendientes = (int) ($counts['pendiente'] ?? 0);
$enRevision = (int) ($counts['en_revision'] ?? 0);
$resueltas = (int) (($counts['aprobado'] ?? 0) + ($counts['denegado'] ?? 0));

$totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

// Si la página quedó fuera de rango (p. ej. se eliminó la última fila), corregir.
if ($page > $totalPages) {
    header('Location: index.php' . dashboard_query_url(['pagina' => $totalPages]));
    exit;
}

$offset = ($page - 1) * $limit;
$rows = $repo->listar($filtros, $limit, $offset);

// Páginas a mostrar con elipses (mismo criterio que el dashboard React)
$pageItems = [];
for ($p = 1; $p <= $totalPages; $p++) {
    if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) {
        $pageItems[] = $p;
    }
}

$mostrandoDesde = $total === 0 ? 0 : $offset + 1;
$mostrandoHasta = min($offset + $limit, $total);

$stats = [
    ['label' => 'Total', 'value' => $total, 'color' => 'bg-blue-950'],
    ['label' => 'Pendientes', 'value' => $pendientes, 'color' => 'bg-amber-500'],
    ['label' => 'En revisión', 'value' => $enRevision, 'color' => 'bg-blue-500'],
    ['label' => 'Resueltas', 'value' => $resueltas, 'color' => 'bg-emerald-500'],
];

$tabConfig = [
    'credito' => 'Crédito',
    'afiliacion' => 'Afiliación',
    'pre-evaluacion' => 'Pre-evaluación',
    'mpv' => 'Mesa de Partes',
    'auxilio-retiro' => 'Aux. Retiro',
    'auxilio-invalidez' => 'Aux. Invalidez',
    'seguro-sepelio' => 'Sepelio Familiar',
    'prestamo-solidario' => 'Préstamo Solidario',
    'auxilio-fallecimiento' => 'Aux. Fallecimiento',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo | AMSP</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; style-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; img-src 'self' data: https:; frame-src https://*.s3.sa-east-1.amazonaws.com; connect-src 'self'">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Solicitudes recibidas</h1>
                <p class="text-sm text-gray-500">Administra y revisa las solicitudes enviadas desde el portal</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="auditoria.php" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    Auditoría
                </a>
                <a href="logout.php" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                Cerrar sesión
            </a>
        </div>

        <!-- Stats -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <?php foreach ($stats as $card): ?>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg text-white <?= $card['color'] ?>">
                            <span class="text-lg font-bold"><?= (int) $card['value'] ?></span>
                        </div>
                        <p class="text-sm font-medium text-gray-600"><?= $card['label'] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center">
                <div class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1">
                    <?php foreach ($tabConfig as $key => $label): ?>
                        <a
                            href="index.php<?= dashboard_query_url(['tipo' => $key, 'q' => null, 'pagina' => '1', 'estado' => null]) ?>"
                            class="whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium transition-colors sm:text-sm <?= $tipo === $key ? 'bg-white text-blue-950 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                        ><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="get" class="flex flex-1 gap-2 sm:min-w-[260px]" action="index.php">
                    <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($statuses !== []): ?>
                        <input type="hidden" name="estado" value="<?= htmlspecialchars($estadoRaw, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <input
                        type="search"
                        name="q"
                        placeholder="Buscar por nombre o DNI..."
                        value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
                        maxlength="50"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                    <button type="submit" class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Buscar
                    </button>
                </form>
            </div>

            <?php if ($rows === []): ?>
                <div class="py-16 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6M9 8h6M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="mt-3 text-sm text-gray-500">
                        <?= $q !== '' ? 'No se encontraron resultados para tu búsqueda.' : 'No hay solicitudes en esta categoría.' ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <th class="p-3 font-medium">N&deg; cargo / expediente</th>
                                <th class="p-3 font-medium">Nombre</th>
                                <th class="p-3 font-medium">DNI</th>
                                <th class="p-3 font-medium">Fecha</th>
                                <th class="p-3 font-medium">Estado</th>
                                <th class="p-3 font-medium">Área</th>
                                <th class="p-3 font-medium">Archivos</th>
                                <th class="p-3 font-medium">Acción</th>
                                <th class="p-3 font-medium">Eliminar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $id = (string) $row['submission_id'];
                                $status = (string) $row['status'];
                                $name = (string) $row['name'];
                                $closed = in_array($status, ['aprobado', 'denegado'], true);
                                $nroCargo = (string) $row['nro_cargo'];
                                $nroExp = (string) $row['nro_expediente'];
                                $area = (string) ($row['area_actual'] ?? '');
                                ?>
                                <tr class="transition-colors hover:bg-gray-50">
                                    <td class="p-3">
                                        <span class="block font-mono text-xs text-blue-800"><?= e($nroCargo !== '' ? $nroCargo : '—') ?></span>
                                        <span class="block font-mono text-[11px] text-gray-400"><?= e($nroExp !== '' ? $nroExp : '—') ?></span>
                                    </td>
                                    <td class="p-3 font-medium text-gray-900"><?= e($name) ?></td>
                                    <td class="p-3 text-gray-600"><?= e((string) $row['dni']) ?></td>
                                    <td class="whitespace-nowrap p-3 text-gray-500"><?= e(formatFecha((string) $row['fecha_solicitud'])) ?></td>
                                    <td class="p-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold <?= dashboard_status_badge_class($status) ?>">
                                            <?= dashboard_status_label($status) ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-gray-600"><?= e($area !== '' ? $area : '—') ?></td>
                                    <td class="p-3">
                                        <button
                                            type="button"
                                            onclick="verArchivos('<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>', <?= json_encode($name) ?>)"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-800"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            Ver
                                        </button>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <button
                                                type="button"
                                                onclick="verSeguimiento('<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>', <?= json_encode($name) ?>)"
                                                class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100"
                                            >
                                                Seguimiento
                                            </button>
                                            <?php if ($closed): ?>
                                                <span class="text-xs text-gray-400">Cerrada</span>
                                            <?php else: ?>
                                                <select
                                                    data-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                                                    onchange="cambiarEstado(this)"
                                                    class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-600"
                                                >
                                                    <?php foreach (dashboard_status_options($status) as $opt): ?>
                                                        <option value="<?= $opt['value'] ?>" <?= $opt['value'] === $status ? 'selected' : '' ?>><?= $opt['label'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <button
                                            type="button"
                                            onclick="eliminarSolicitud('<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>', <?= json_encode($name) ?>)"
                                            class="rounded p-1 text-red-500 hover:bg-red-50"
                                            aria-label="Eliminar solicitud"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col items-center justify-between gap-3 border-t p-4 sm:flex-row">
                    <p class="text-sm text-gray-500">
                        Mostrando <?= $mostrandoDesde ?>-<?= $mostrandoHasta ?> de <?= $total ?> resultados
                    </p>
                    <div class="flex items-center gap-1">
                        <a
                            href="index.php<?= dashboard_query_url(['pagina' => max(1, $page - 1), 'q' => $q]) ?>"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>"
                        >Anterior</a>

                        <?php foreach ($pageItems as $idx => $p): ?>
                            <?php if ($idx > 0 && $pageItems[$idx - 1] !== $p - 1): ?>
                                <span class="px-1 text-xs text-gray-400">...</span>
                            <?php endif; ?>
                            <a
                                href="index.php<?= dashboard_query_url(['pagina' => $p, 'q' => $q]) ?>"
                                class="min-w-[36px] rounded-lg px-3 py-1.5 text-center text-sm <?= $p === $page ? 'bg-blue-950 text-white' : 'text-gray-700 hover:bg-gray-100' ?>"
                            ><?= $p ?></a>
                        <?php endforeach; ?>

                        <a
                            href="index.php<?= dashboard_query_url(['pagina' => min($totalPages, $page + 1), 'q' => $q]) ?>"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>"
                        >Siguiente</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal de archivos -->
    <div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4" onclick="cerrarModal()">
        <div
            class="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl"
            onclick="event.stopPropagation()"
        >
            <div class="flex items-center justify-between border-b p-4">
                <h2 id="modalTitulo" class="text-lg font-semibold text-gray-900">Archivos</h2>
                <button type="button" onclick="cerrarModal()" class="text-gray-400 hover:text-gray-700" aria-label="Cerrar">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div id="modalBody" class="space-y-3 p-4">
                <div class="flex justify-center py-8">
                    <div class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de seguimiento / derivación -->
    <div id="modalSeg" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4" onclick="cerrarModalSeg()">
        <div
            class="max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-xl"
            onclick="event.stopPropagation()"
        >
            <div class="flex items-center justify-between border-b p-4">
                <h2 id="modalSegTitulo" class="text-lg font-semibold text-gray-900">Seguimiento</h2>
                <button type="button" onclick="cerrarModalSeg()" class="text-gray-400 hover:text-gray-700" aria-label="Cerrar">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div id="modalSegBody" class="space-y-4 p-4">
                <div class="flex justify-center py-8">
                    <div class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function fatalError(res) {
        if (res.status === 401) {
            window.location.href = 'login.php';
            throw new Error('No autorizado');
        }
    }

    async function verArchivos(id, name) {
        const modal = document.getElementById('modal');
        const body = document.getElementById('modalBody');
        const titulo = document.getElementById('modalTitulo');
        titulo.textContent = 'Archivos de ' + name;
        body.innerHTML = '<div class="flex justify-center py-8"><div class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"></div></div>';
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        try {
            const res = await fetch('api/files.php?id=' + encodeURIComponent(id));
            if (res.status === 401) { window.location.href = 'login.php'; return; }
            if (!res.ok) throw new Error('Error al cargar los archivos');
            const files = await res.json();

            if (files.length === 0) {
                body.innerHTML = '<p class="py-4 text-center text-sm text-gray-500">No hay archivos disponibles.</p>';
                return;
            }
            window.__adminFiles = files;
            window.__adminCtx = { id: id, name: name };

            body.innerHTML = '';
            files.forEach((f, i) => {
                const esImagen = /\.(jpg|jpeg|png|gif|webp)$/i.test(f.originalName) || (f.fileType || '').startsWith('image/');
                const preview = f.url && esImagen
                    ? '<a href="' + f.url + '" target="_blank" rel="noopener noreferrer"><img src="' + f.url + '" alt="' + f.originalName.replace(/"/g, '&quot;') + '" class="h-16 w-16 shrink-0 rounded object-cover"></a>'
                    : '<div class="flex h-16 w-16 shrink-0 items-center justify-center rounded bg-gray-100">' +
                      '<svg class="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div>';

                const prevBtn = f.url
                    ? '<button type="button" data-i="' + i + '" onclick="window.__adminPrev(this.dataset.i)" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Previsualizar</button>'
                    : '';
                const dir = f.url
                    ? '<a href="' + f.url + '" target="_blank" rel="noopener noreferrer" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Descargar</a>'
                    : '';

                body.innerHTML += '<div class="flex items-center gap-3 rounded-lg border p-3">'
                    + preview
                    + '<div class="min-w-0 flex-1"><p class="truncate text-sm font-medium text-gray-900">' + f.originalName + '</p>'
                    + '<span class="text-xs text-gray-500">' + f.fileType + '</span></div>'
                    + prevBtn
                    + dir
                    + '</div>';
            });
        } catch {
            body.innerHTML = '<p class="py-4 text-center text-sm text-red-600">Error al cargar los archivos.</p>';
        }
    }

    // Vista previa de archivo (imagen o PDF) dentro del modal de archivos.
    window.__adminPrev = function (i) {
        const f = (window.__adminFiles || [])[i];
        if (!f || !f.url) return;
        const body = document.getElementById('modalBody');
        document.getElementById('modalTitulo').textContent = f.originalName || 'Vista previa';
        const esImagen = (f.fileType || '').startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(f.originalName);
        body.innerHTML = '<div class="mb-3 flex items-center justify-between">'
            + '<button type="button" onclick="verArchivos(window.__adminCtx.id, window.__adminCtx.name)" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">&larr; Volver a la lista</button>'
            + '<button type="button" onclick="cerrarModal()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Cerrar</button></div>'
            + (esImagen
                ? '<div class="flex justify-center"><img src="' + f.url + '" alt="' + f.originalName.replace(/"/g, '&quot;') + '" class="max-h-[65vh] max-w-full object-contain"></div>'
                : '<iframe src="' + f.url + '" class="h-[65vh] w-full rounded-lg border"></iframe>');
    }

    function cerrarModal() {
        const modal = document.getElementById('modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    const ESTADO_BADGE = {
        'pendiente': 'bg-amber-100 text-amber-700',
        'en_revision': 'bg-blue-100 text-blue-700',
        'aprobado': 'bg-emerald-100 text-emerald-700',
        'denegado': 'bg-red-100 text-red-700'
    };
    const ESTADO_LABEL = {
        'pendiente': 'Pendiente',
        'en_revision': 'En revisión',
        'aprobado': 'Aprobado',
        'denegado': 'Denegado'
    };
    let segAreas = [];

    async function cargarAreas() {
        if (segAreas.length) return segAreas;
        try {
            const res = await fetch('api/areas.php');
            if (res.status === 401) { window.location.href = 'login.php'; return []; }
            const data = await res.json();
            segAreas = data.areas || [];
        } catch { segAreas = []; }
        return segAreas;
    }

    async function verSeguimiento(id, name) {
        const modal = document.getElementById('modalSeg');
        const body = document.getElementById('modalSegBody');
        document.getElementById('modalSegTitulo').textContent = 'Seguimiento de ' + name;
        body.innerHTML = '<div class="flex justify-center py-8"><div class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"></div></div>';
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        try {
            const res = await fetch('api/seguimiento.php?id=' + encodeURIComponent(id));
            if (res.status === 401) { window.location.href = 'login.php'; return; }
            if (!res.ok) throw new Error('Error al cargar el seguimiento');
            const data = await res.json();
            const exp = data.expediente || {};
            const mov = data.movimientos || [];
            const areas = await cargarAreas();

            let timeline = '';
            if (mov.length === 0) {
                timeline = '<p class="text-sm text-gray-500">Sin movimientos registrados.</p>';
            } else {
                const tipos = { registro: 'Registro', derivacion: 'Derivación', estado: 'Cambio de estado', resolucion: 'Resolución' };
                timeline = '<ol class="space-y-3">' + mov.map((m, i) => {
                    const last = i === mov.length - 1;
                    return '<li class="relative pl-7">'
                        + '<span class="absolute left-0 top-1 h-3.5 w-3.5 rounded-full ring-4 ring-white ' + (last ? 'bg-blue-600' : 'bg-gray-300') + '"></span>'
                        + '<p class="text-sm font-semibold text-gray-900">' + (tipos[m.tipo] || m.tipo) + ' <span class="ml-1 text-xs font-normal text-gray-400">' + m.created_at + '</span></p>'
                        + '<p class="text-sm text-gray-600">' + m.descripcion + '</p>'
                        + '<p class="text-xs text-gray-500">' + (m.de_area ? 'De: ' + m.de_area + ' &rarr; ' : '') + (m.a_area ? 'A: ' + m.a_area + ' ' : '') + (m.usuario ? '&middot; por ' + m.usuario : '') + '</p>'
                        + '</li>';
                }).join('') + '</ol>';
            }

            const enabled = exp.status !== 'aprobado' && exp.status !== 'denegado';
            const areaOptions = '<option value="">Seleccione área de destino</option>' + areas.map(a => '<option value="' + a.id + '">' + a.nombre + '</option>').join('');
            const derivarForm = enabled
                ? '<div class="rounded-lg border border-gray-200 p-4">'
                    + '<h3 class="text-sm font-semibold text-gray-900 mb-2">Derivar expediente</h3>'
                    + '<div class="grid grid-cols-1 gap-3">'
                    + '<select id="segArea" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600">' + areaOptions + '</select>'
                    + '<input id="segMotivo" type="text" maxlength="500" placeholder="Motivo de la derivación (opcional)" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600">'
                    + '<button type="button" onclick="derivar(\'' + id + '\')" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Derivar</button>'
                    + '</div></div>'
                : '<p class="text-xs text-gray-400">Expediente cerrado: no admite derivaciones.</p>';

            const acuseUrl = '../acuse.php?id=' + encodeURIComponent(id);

            body.innerHTML =
                '<div class="rounded-lg border border-gray-200 p-4">'
                + '<div class="flex flex-wrap items-center justify-between gap-2">'
                + '<div><p class="font-mono text-sm font-bold text-blue-900">' + exp.nro_cargo + ' &middot; ' + exp.nro_expediente + '</p>'
                + '<p class="text-xs text-gray-500">' + exp.tipo_label + ' &middot; ' + exp.name + ' &middot; DNI ' + exp.dni + '</p></div>'
                + '<a href="' + acuseUrl + '" target="_blank" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Ver acuse</a>'
                + '</div>'
                + '<div class="mt-3 flex flex-wrap gap-4 text-sm">'
                + '<div><span class="text-xs text-gray-500 uppercase">Estado</span><p><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ' + (ESTADO_BADGE[exp.status] || 'bg-gray-100 text-gray-700') + '">' + (ESTADO_LABEL[exp.status] || exp.status) + '</span></p></div>'
                + '<div><span class="text-xs text-gray-500 uppercase">Área actual</span><p class="font-medium">' + (exp.area_actual || 'Mesa de Partes') + '</p></div>'
                + '<div><span class="text-xs text-gray-500 uppercase">Presentación</span><p class="font-medium">' + exp.fecha + '</p></div>'
                + '</div></div>'
                + '<div class="rounded-lg border border-gray-200 p-4">'
                + '<h3 class="text-sm font-semibold text-gray-900 mb-2">Movimientos del expediente</h3>'
                + timeline
                + '</div>'
                + derivarForm;

            // registrar visualización en auditoría
            // (se registra igual que los archivos: al cargar el detalle)
        } catch {
            body.innerHTML = '<p class="py-4 text-center text-sm text-red-600">Error al cargar el seguimiento.</p>';
        }
    }

    function cerrarModalSeg() {
        const modal = document.getElementById('modalSeg');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    async function derivar(id) {
        const areaEl = document.getElementById('segArea');
        if (!areaEl.value) { window.alert('Seleccione un área de destino.'); return; }
        const motivoEl = document.getElementById('segMotivo');
        const btn = event.currentTarget;
        btn.disabled = true;
        btn.textContent = 'Derivando...';
        try {
            const res = await fetch('api/derivar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, areaId: parseInt(areaEl.value, 10), motivo: motivoEl.value })
            });
            if (res.status === 401) { window.location.href = 'login.php'; return; }
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                window.alert(data.error || 'Error al derivar el expediente');
                btn.disabled = false;
                btn.textContent = 'Derivar';
                return;
            }
            window.alert('Expediente derivado correctamente.');
            cerrarModalSeg();
            window.location.reload();
        } catch {
            window.alert('Error al derivar el expediente');
            btn.disabled = false;
            btn.textContent = 'Derivar';
        }
    }

    async function cambiarEstado(select) {
        select.disabled = true;
        try {
            const res = await fetch('api/status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: select.dataset.id, status: select.value })
            });
            if (res.status === 401) { window.location.href = 'login.php'; return; }
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                window.alert(data.error || 'Error al actualizar el estado');
                select.disabled = false;
                return;
            }
            window.location.reload();
        } catch {
            window.alert('Error al actualizar el estado');
            select.disabled = false;
        }
    }

    async function eliminarSolicitud(id, name) {
        if (!window.confirm('¿Estás seguro de eliminar la solicitud de ' + name + '?')) return;
        try {
            const res = await fetch('api/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            if (res.status === 401) { window.location.href = 'login.php'; return; }
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                window.alert(data.error || 'Error al eliminar la solicitud');
                return;
            }
            window.location.reload();
        } catch {
            window.alert('Error al eliminar la solicitud');
        }
    }
    </script>
</body>
</html>