<?php

declare(strict_types=1);

$config = require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/SubmissionRepository.php';
require_once __DIR__ . '/includes/AcuseService.php';
require_once __DIR__ . '/includes/auth.php';

session_name('AMSP_CLIENTE');
session_start();

$mpv = $config['mpv'];

$id = trim((string) ($_GET['id'] ?? ''));
$probed = trim((string) ($_GET['t'] ?? ''));

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
    http_response_code(400);
    exit('Identificador de acuse inválido.');
}

try {
    Setup::ensureDatabase();
    $acuse = AcuseService::datosAcuse(new SubmissionRepository(), strtolower($id));
} catch (Throwable $e) {
    error_log('[ACUSE] ' . $e->getMessage());
    http_response_code(500);
    exit('No fue posible generar el acuse de recibo.');
}

if ($acuse === null) {
    http_response_code(404);
    exit('Acuse no encontrado.');
}

// Autorización: huella válida (enlace firmado) o usuario del panel.
$isAdmin = dashboard_current_user() !== null;
$hashOk = AcuseService::verificarHash($probed, $acuse['acuse_hash']);
if (!$isAdmin && !$hashOk) {
    http_response_code(403);
    exit('No autorizado para ver este acuse.');
}

// Descarga del acuse en PDF
if (($_GET['pdf'] ?? '') === '1') {
    try {
        $pdf = AcuseService::generarAcusePdf($acuse);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="acuse-' . preg_replace('/\W/', '', $acuse['nro_cargo']) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('No fue posible generar el PDF del acuse.');
    }
}

$printUrl = 'acuse.php?id=' . urlencode($id) . '&t=' . urlencode($probed) . '&pdf=1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acuse de Recibo | <?= e((string) $mpv['titulo']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'">
    <style>
        body { font-family: Georgia, 'Times New Roman', serif; background: #f3f4f6; margin: 0; padding: 24px; color: #111827; }
        .page { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 40px 48px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .actions { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
        .btn { font-family: Arial, sans-serif; border: 0; border-radius: 8px; padding: 10px 16px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1e3a8a; color: #fff; }
        .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .brand { text-align: center; border-bottom: 3px double #1e3a8a; padding-bottom: 18px; margin-bottom: 24px; }
        .brand h1 { font-size: 22px; margin: 4px 0; color: #1e3a8a; }
        .brand p { margin: 2px 0; color: #4b5563; font-size: 13px; }
        .title { text-align: center; font-size: 20px; letter-spacing: 2px; margin: 8px 0 4px; color: #111827; }
        .subtitle { text-align: center; font-size: 12px; color: #6b7280; margin-bottom: 22px; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        td { border: 1px solid #9ca3af; padding: 8px 10px; vertical-align: top; }
        td.k { width: 220px; font-weight: bold; background: #f9fafb; }
        .legal { margin-top: 22px; font-size: 11.5px; color: #4b5563; line-height: 1.55; text-align: justify; }
        .sign { margin-top: 26px; font-size: 12.5px; }
        .hash { font-family: 'Courier New', monospace; color: #1e3a8a; font-size: 12.5px; }
        .foot { margin-top: 28px; border-top: 1px solid #d1d5db; padding-top: 12px; text-align: center; font-size: 11px; color: #6b7280; }
        @media print {
            body { background: #fff; padding: 0; }
            .page { border: 0; box-shadow: none; padding: 12px; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="brand">
            <h1><?= e((string) $mpv['titulo']) ?></h1>
            <p><?= e((string) $mpv['apex']) ?></p>
            <p>Directiva de creación: <?= e((string) $mpv['numero']) ?></p>
        </div>

        <div class="title">ACUSE DE RECIBO</div>
        <div class="subtitle">Recibo emitido automáticamente por la plataforma web</div>

        <table>
            <tr><td class="k">Nº de cargo</td><td class="hash"><strong><?= e($acuse['nro_cargo']) ?></strong></td></tr>
            <tr><td class="k">Nº de expediente</td><td class="hash"><?= e($acuse['nro_expediente']) ?></td></tr>
            <tr><td class="k">Fecha y hora de recepción</td><td><?= e(AcuseService::formatearFecha($acuse['acuse_at'])) ?></td></tr>
            <tr><td class="k">Remitente</td><td><?= e($acuse['nombre']) ?></td></tr>
            <tr><td class="k">D.N.I.</td><td><?= e($acuse['dni']) ?></td></tr>
            <tr><td class="k">Correo electrónico</td><td><?= e($acuse['email']) ?></td></tr>
            <tr><td class="k">Teléfono</td><td><?= e($acuse['telefono']) ?></td></tr>
            <tr><td class="k">Tipo de trámite</td><td><?= e(AcuseService::labelTipo($acuse['tipo'])) ?></td></tr>
            <tr><td class="k">Asunto</td><td><?= e($acuse['descripcion']) ?></td></tr>
            <tr><td class="k">Archivos adjuntos</td><td><?= (int) $acuse['archivos'] ?> documento(s)</td></tr>
            <tr><td class="k">Área responsable</td><td><?= e($acuse['area_actual'] !== '' ? $acuse['area_actual'] : 'Mesa de Partes Virtual') ?></td></tr>
            <tr><td class="k">Código de verificación</td><td class="hash"><?= e(substr($acuse['acuse_hash'], 0, 20)) ?>...</td></tr>
        </table>

        <p class="legal">
            El presente acuse acredita la recepción de la solicitud y sus documentos adjuntos en la Mesa de Partes
            Virtual de la <?= e((string) $mpv['apex']) ?>. Su contenido se genera de manera automática al momento de la
            presentación; por ello, conserve una copia y utilice el código de verificación para validar su autenticidad
            en la plataforma web. La información proporcionada está protegida conforme a la política de privacidad y
            la normativa vigente sobre protección de datos personales.
        </p>

        <div class="sign">
            <p><strong>Órgano responsable:</strong> <?= e((string) $mpv['responsable']) ?></p>
            <p style="margin-top:26px;"><strong>Firma responsable:</strong> <?= e((string) $mpv['responsableNombre']) ?></p>
            <div style="margin-top:8px; border-top:1px solid #d1d5db; width:240px; padding-top:6px; font-size:11px; color:#6b7280;">Firma y sello</div>
        </div>

        <div class="foot">
            <?= e((string) $mpv['horario']) ?> &middot; <?= e((string) $mpv['telefono']) ?> &middot; <?= e((string) $mpv['correo']) ?>
        </div>
    </div>

    <div class="actions">
        <button type="button" onclick="window.open('<?= e($printUrl) ?>', '_blank')" class="btn btn-primary">Descargar acuse (PDF)</button>
        <button type="button" onclick="window.print()" class="btn btn-outline">Imprimir</button>
    </div>
</body>
</html>