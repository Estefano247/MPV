<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';

dashboard_guard_page();

const AUDIT_ACTION_LABELS = [
    'login' => ['Login', 'bg-emerald-100 text-emerald-700'],
    'login_failed' => ['Login fallido', 'bg-red-100 text-red-700'],
    'logout' => ['Cerrar sesión', 'bg-gray-100 text-gray-700'],
    'status_change' => ['Cambio de estado', 'bg-blue-100 text-blue-700'],
    'delete_submission' => ['Eliminación', 'bg-orange-100 text-orange-700'],
    'view_files' => ['Ver archivos', 'bg-purple-100 text-purple-700'],
    'derivar' => ['Derivación', 'bg-indigo-100 text-indigo-700'],
];

$db = Database::getConnection();

$page = max(1, (int) ($_GET['pagina'] ?? 1));
$limit = min(100, max(1, (int) ($_GET['limite'] ?? 20)));
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 50) {
    $q = mb_substr($q, 0, 50);
}
$accion = trim((string) ($_GET['accion'] ?? ''));

$where = ['1 = 1'];
$params = [];

if ($q !== '') {
    $params[':q'] = '%' . $q . '%';
    $where[] = '(username ILIKE :q OR entity_id ILIKE :q OR ip_address ILIKE :q)';
}
if ($accion !== '' && in_array($accion, array_keys(AUDIT_ACTION_LABELS), true)) {
    $params[':accion'] = $accion;
    $where[] = 'action = :accion';
}

$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) AS total FROM audit_log WHERE {$whereSql}");
$stmt->execute($params);
$total = (int) ($stmt->fetchAll()[0]['total'] ?? 0);

$totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;
if ($page > $totalPages) {
    header('Location: auditoria.php' . dashboard_query_url(['pagina' => $totalPages]));
    exit;
}

$offset = ($page - 1) * $limit;
$listParams = $params;
$listParams[':limite'] = $limit;
$listParams[':offset'] = $offset;

$stmt = $db->prepare(
    "SELECT id, user_id, username, action, entity_type, entity_id, details, ip_address, user_agent, created_at
       FROM audit_log
      WHERE {$whereSql}
      ORDER BY id DESC
      LIMIT :limite OFFSET :offset"
);
$stmt->execute($listParams);
$rows = $stmt->fetchAll();

$pageItems = [];
for ($p = 1; $p <= $totalPages; $p++) {
    if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) {
        $pageItems[] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría | Panel AMSP</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; style-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Auditoría del panel</h1>
                <p class="text-sm text-gray-500">Registro de acciones de los administradores sobre las solicitudes</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="index.php" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">Volver al panel</a>
                <a href="logout.php" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">Cerrar sesión</a>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center">
                <form method="get" class="flex flex-1 flex-wrap gap-2">
                    <input type="hidden" name="accion" value="<?= e($accion) ?>">
                    <input
                        type="search"
                        name="q"
                        placeholder="Buscar por usuario, IP o ID..."
                        value="<?= e($q) ?>"
                        maxlength="50"
                        class="w-full min-w-[220px] flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                    <button type="submit" class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Buscar</button>
                </form>
                <div class="flex flex-wrap gap-1">
                    <a href="auditoria.php" class="whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium <?= $accion === '' ? 'bg-blue-950 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Todas</a>
                    <?php foreach (AUDIT_ACTION_LABELS as $key => [$label]): ?>
                        <a
                            href="auditoria.php?accion=<?= $key ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                            class="whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium <?= $accion === $key ? 'bg-blue-950 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"
                        ><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($rows === []): ?>
                <div class="py-16 text-center">
                    <p class="text-sm text-gray-500">No hay registros de auditoría<?= $q !== '' || $accion !== '' ? ' para los filtros aplicados' : '' ?>.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <th class="p-3 font-medium">Fecha</th>
                                <th class="p-3 font-medium">Usuario</th>
                                <th class="p-3 font-medium">Acción</th>
                                <th class="p-3 font-medium">Entidad</th>
                                <th class="p-3 font-medium">Detalles</th>
                                <th class="p-3 font-medium">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $actionKey = (string) $row['action'];
                                [$actionLabel, $badge] = AUDIT_ACTION_LABELS[$actionKey] ?? [$actionKey, 'bg-gray-100 text-gray-700'];
                                $details = [];
                                if ($row['details'] !== null && $row['details'] !== '') {
                                    $decoded = json_decode((string) $row['details'], true);
                                    if (is_array($decoded)) {
                                        $details = $decoded;
                                    }
                                }
                                $entityLabel = match ($row['entity_type']) {
                                    'submissions' => 'Solicitud',
                                    'users' => 'Usuario',
                                    default => (string) ($row['entity_type'] !== null && $row['entity_type'] !== '' ? $row['entity_type'] : '—'),
                                };
                                ?>
                                <tr class="transition-colors hover:bg-gray-50">
                                    <td class="whitespace-nowrap p-3 text-gray-500"><?= e(formatFecha((string) $row['created_at'])) ?> <?= e(date('H:i', strtotime((string) $row['created_at']))) ?></td>
                                    <td class="p-3 font-medium text-gray-900"><?= e((string) ($row['username'] ?? '—')) ?></td>
                                    <td class="p-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold <?= $badge ?>"><?= e($actionLabel) ?></span>
                                    </td>
                                    <td class="p-3 text-gray-600">
                                        <?= e($entityLabel) ?>
                                        <?php if (isset($row['entity_id']) && $row['entity_id'] !== ''): ?>
                                            <span class="font-mono text-xs text-gray-400"><?= e((string) $row['entity_id']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="max-w-md p-3 text-gray-600">
                                        <?php if ($details === []): ?>
                                            <span class="text-gray-400">—</span>
                                        <?php else: ?>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($details as $detailKey => $detailValue): ?>
                                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">
                                                        <strong><?= e((string) $detailKey) ?>:</strong>
                                                        <?= e(is_scalar($detailValue) ? (string) $detailValue : json_encode($detailValue, JSON_UNESCAPED_UNICODE)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="whitespace-nowrap p-3 font-mono text-xs text-gray-500"><?= e((string) ($row['ip_address'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col items-center justify-between gap-3 border-t p-4 sm:flex-row">
                    <p class="text-sm text-gray-500"><?= $total ?> registro<?= $total === 1 ? '' : 's' ?></p>
                    <div class="flex items-center gap-1">
                        <a
                            href="auditoria.php<?= dashboard_query_url(['pagina' => max(1, $page - 1), 'q' => $q]) ?>"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>"
                        >Anterior</a>
                        <?php foreach ($pageItems as $idx => $p): ?>
                            <?php if ($idx > 0 && $pageItems[$idx - 1] !== $p - 1): ?>
                                <span class="px-1 text-xs text-gray-400">...</span>
                            <?php endif; ?>
                            <a
                                href="auditoria.php<?= dashboard_query_url(['pagina' => $p, 'q' => $q]) ?>"
                                class="min-w-[36px] rounded-lg px-3 py-1.5 text-center text-sm <?= $p === $page ? 'bg-blue-950 text-white' : 'text-gray-700 hover:bg-gray-100' ?>"
                            ><?= $p ?></a>
                        <?php endforeach; ?>
                        <a
                            href="auditoria.php<?= dashboard_query_url(['pagina' => min($totalPages, $page + 1), 'q' => $q]) ?>"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>"
                        >Siguiente</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>