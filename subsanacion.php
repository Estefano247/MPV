<?php

declare(strict_types=1);

$config = require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Setup.php';
require_once __DIR__ . '/includes/View.php';
require_once __DIR__ . '/includes/AcuseService.php';
require_once __DIR__ . '/includes/ObservacionRepository.php';
require_once __DIR__ . '/includes/SubsanacionService.php';

session_name('AMSP_CLIENTE');
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$mpv = $config['mpv'];

// Dos pasos en la misma página, como el seguimiento:
//   1. el presentante se identifica con su Nº de cargo (o de expediente) y su DNI;
//   2. si el expediente tiene una observación vigente, se le muestra y subsana.
$numero = strtoupper(trim((string) ($_POST['numero'] ?? $_GET['numero'] ?? '')));
$dni = preg_replace('/\D/', '', (string) ($_POST['dni'] ?? $_GET['dni'] ?? '')) ?? '';

$error = null;
$info = null;
$buscado = false;

if ($numero !== '' || $dni !== '') {
    $buscado = true;

    if (!preg_match('/^\d{8}$/', $dni)) {
        $error = 'Debe ingresar un DNI válido de 8 dígitos.';
    } elseif (!preg_match('/^[A-Z]-\d{4}-\d{6}$/', $numero)) {
        $error = 'Ingrese el Nº de cargo o Nº de expediente recibido en su acuse (por ejemplo, C-2026-000123).';
    } else {
        try {
            Setup::ensureDatabase();
            $info = (new SubsanacionService())->consultar($numero, $dni);
            if ($info === null) {
                $error = 'No se encontró ningún trámite con esos datos. Verifique el número y su DNI.';
            }
        } catch (Throwable $e) {
            error_log('[SUBSANACION] ' . $e->getMessage());
            $error = 'No fue posible consultar el expediente. Inténtelo más tarde.';
        }
    }
}

// La subsanación más reciente es la que tiene el enlace firmado a su acuse: se
// la busca para poder reenviarla desde esta misma página si el presentante
// cerró el aviso que recibió al registrarla.
$ultima = $info['subsanaciones'][0] ?? null;
$hashUltima = '';
if ($ultima !== null) {
    try {
        Setup::ensureDatabase();
        $fila = (new ObservacionRepository())->subsanacionPorId($ultima['id']);
        $hashUltima = (string) ($fila['acuse_hash'] ?? '');
    } catch (Throwable $e) {
        error_log('[SUBSANACION] ' . $e->getMessage());
    }
}

$puedeSubsanar = $info !== null
    && $info['observacion'] !== null
    && !$info['cerrado']
    && !$info['en_revision'];

$plazoSugerido = SubsanacionService::plazoPorDefecto();
?>
<?php View::head('Subsanación de observaciones | ' . (string) $mpv['titulo'], $config, 'subsanacion'); ?>
?>
            <div class="rounded-xl bg-blue-950 text-white p-4 sm:p-6 mb-6">
                <h1 class="text-xl sm:text-2xl font-bold">Subsanación de observaciones</h1>
                <p class="text-sm text-blue-200 mt-1">Si el área responsable le registró una observación sobre su
                    expediente, puede subsanarla aquí antes de que venza el plazo. Recibirá un <strong>Nº de cargo de
                    subsanación</strong> y su propio <strong>Acuse de Subsanación</strong>.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl shadow-lg border p-4 sm:p-6">
                        <h2 class="text-sm font-semibold text-gray-800 mb-4">1. Identifíquese con su expediente</h2>
                        <form method="post" class="space-y-4">
                            <div>
                                <label for="numero" class="block text-sm font-medium text-gray-700 mb-1">Nº de cargo o Nº de expediente</label>
                                <input id="numero" name="numero" type="text" required maxlength="40"
                                       value="<?= e($numero) ?>"
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
                            <button type="submit" class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 transition-colors">Buscar observación</button>
                        </form>
                    </div>

                    <?php if ($error !== null): ?>
                        <div class="mt-6 flex items-center gap-2 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($info !== null): ?>
                        <div class="mt-6 bg-white rounded-2xl shadow-lg border overflow-hidden">
                            <div class="px-4 sm:px-6 py-3 border-b flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-sm font-semibold text-gray-800">
                                    Expediente <?= e($info['nro_expediente']) ?>
                                </h2>
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                                    <?= match ($info['status']) {
                                        'aprobado' => 'bg-emerald-100 text-emerald-700',
                                        'denegado' => 'bg-red-100 text-red-700',
                                        'en_revision' => 'bg-blue-100 text-blue-700',
                                        'observado' => 'bg-amber-200 text-amber-900',
                                        default => 'bg-amber-100 text-amber-700',
                                    } ?>">
                                    <?= e(AcuseService::ESTADOS[$info['status']] ?? $info['status']) ?>
                                </span>
                            </div>
                            <div class="p-4 sm:p-6 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                <div><span class="text-xs text-gray-500 uppercase">Nº de cargo</span><p class="font-semibold"><?= e($info['nro_cargo']) ?></p></div>
                                <div><span class="text-xs text-gray-500 uppercase">Remitente</span><p class="font-semibold"><?= e($info['nombre']) ?></p></div>
                                <div><span class="text-xs text-gray-500 uppercase">DNI</span><p class="font-semibold"><?= e($info['dni']) ?></p></div>
                                <div><span class="text-xs text-gray-500 uppercase">Correo</span><p><?= e($info['email']) ?></p></div>
                            </div>
                        </div>

                        <?php if ($puedeSubsanar): ?>
                            <div class="mt-6 bg-white rounded-2xl shadow-lg border overflow-hidden">
                                <div class="px-4 sm:px-6 py-3 border-b bg-amber-50">
                                    <h2 class="text-sm font-semibold text-amber-900">2. Observación que debe subsanar</h2>
                                </div>
                                <div class="p-4 sm:p-6 space-y-4">
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                        <p class="text-sm text-amber-900 whitespace-pre-line"><?= e($info['observacion']['detalle']) ?></p>
                                        <p class="mt-3 text-xs text-amber-800">
                                            Plazo: <?= (int) $info['observacion']['plazo_dias'] ?> días &middot;
                                            Vence el <?= e(AcuseService::formatearFecha($info['observacion']['fecha_limite'])) ?>
                                            <?php if ($info['observacion']['vencida']): ?>
                                                <strong class="text-red-700">(plazo vencido)</strong>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <form id="subsForm" class="space-y-4">
                                        <div>
                                            <label for="subDescripcion" class="block text-sm font-medium text-gray-700 mb-1">Qué se subsana *</label>
                                            <textarea id="subDescripcion" rows="3" required maxlength="2000"
                                                      placeholder="Indique qué documento adjunta y cómo responde a lo observado."
                                                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                                        </div>
                                        <div>
                                            <label for="subNombre" class="block text-sm font-medium text-gray-700 mb-1">Nombres y apellidos</label>
                                            <input id="subNombre" type="text" maxlength="200" value="<?= e($info['nombre']) ?>"
                                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <p class="mt-1 text-xs text-gray-500">Opcional. Si lo deja vacío se usa el del expediente original.</p>
                                        </div>
                                        <div>
                                            <label for="subTelefono" class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                                            <input id="subTelefono" type="text" maxlength="15" value="<?= e($info['telefono']) ?>"
                                                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)"
                                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>

                                        <div>
                                            <p class="block text-sm font-medium text-gray-700 mb-1">Documentos de la subsanación *</p>
                                            <button type="button" id="subSelectBtn"
                                                    class="w-full rounded-lg border border-dashed border-gray-400 px-4 py-3 text-sm text-gray-600 hover:bg-gray-50">
                                                Seleccionar documentos (PDF o imagen, máx. 10 MB cada uno)
                                            </button>
                                            <ul id="subFileList" class="mt-3 space-y-2"></ul>
                                        </div>

                                        <div id="subMessage" class="hidden"></div>
                                        <div id="subProgress" class="hidden flex items-center gap-2 text-sm text-gray-600">
                                            <span class="animate-pulse">Subiendo documentos…</span>
                                        </div>

                                        <button type="submit" id="subSubmitBtn" disabled
                                                class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                            Presentar subsanación
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php elseif ($info['cerrado']): ?>
                            <div class="mt-6 rounded-lg bg-blue-50 border border-blue-200 p-4 text-sm text-blue-900">
                                Este expediente ya fue resuelto, por lo que no admite subsanaciones.
                            </div>
                        <?php elseif ($info['en_revision']): ?>
                            <div class="mt-6 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-900">
                                Ya entregó la subsanación de esta observación y está en revisión por el área responsable.
                                Vuelve al <a class="underline font-semibold" href="seguimiento.php">seguimiento del expediente</a> para ver el estado.
                            </div>
                        <?php else: ?>
                            <div class="mt-6 rounded-lg bg-gray-50 border border-gray-200 p-4 text-sm text-gray-700">
                                Este expediente no tiene observaciones pendientes. Puede consultarlo en el
                                <a class="underline" href="seguimiento.php">seguimiento del trámite</a>.
                            </div>
                        <?php endif; ?>

                        <?php if ($ultima !== null): ?>
                            <div class="mt-6 bg-white rounded-2xl shadow-lg border p-4 sm:p-6">
                                <h2 class="text-sm font-semibold text-gray-800 mb-2">Sus subsanaciones</h2>
                                <ul class="space-y-2 text-sm">
                                    <?php foreach ($info['subsanaciones'] as $sb): ?>
                                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2">
                                            <span>
                                                <strong><?= e($sb['nro_cargo']) ?></strong> &middot;
                                                <span class="text-xs text-gray-500"><?= e(match ($sb['estado']) {
                                                    'aceptada' => 'Aceptada por el área',
                                                    'rechazada' => 'Rechazada por el área',
                                                    default => 'En revisión',
                                                }) ?></span>
                                            </span>
                                            <span class="flex items-center gap-3">
                                                <span class="text-xs text-gray-400"><?= e(AcuseService::formatearFecha($sb['created_at'])) ?></span>
                                                <?php if ($hashUltima !== '' && $sb['id'] === $ultima['id']): ?>
                                                    <a class="text-xs font-medium text-blue-700 underline"
                                                       href="acuse.php?sub=<?= urlencode($sb['id']) ?>&t=<?= urlencode($hashUltima) ?>"
                                                       target="_blank">Acuse</a>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <aside class="space-y-4">
                    <div class="bg-white rounded-2xl shadow-lg border p-4 sm:p-5">
                        <h2 class="text-sm font-semibold text-gray-800 mb-2">Cómo funciona</h2>
                        <ol class="list-decimal list-inside space-y-2 text-sm text-gray-600">
                            <li>El área revisa su expediente y, si le falta algún documento, registra una observación con un plazo.</li>
                            <li>Usted entra a esta página con su Nº de cargo y su DNI, y presenta lo que se le requerida.</li>
                            <li>Recibe un Nº de cargo de subsanación y su acuse descargable.</li>
                            <li>El área acepta o rechaza lo presentado; el resultado consta en el seguimiento del expediente.</li>
                        </ol>
                    </div>
                    <div class="bg-white rounded-2xl shadow-lg border p-4 sm:p-5">
                        <h2 class="text-sm font-semibold text-gray-800 mb-2">Plazos</h2>
                        <p class="text-sm text-gray-600">
                            El plazo se cuenta en días desde la fecha de la observación y no puede ser menor a
                            <?= SubsanacionService::PLAZO_MINIMO_DIAS ?> días. Pasado el plazo, el área puede resolver el
                            expediente o ampliarlo; y si la subsanación se rechaza, se le concederá un plazo nuevo.
                        </p>
                    </div>
                </aside>
            </div>

<?php if ($puedeSubsanar): ?>
<script>
(function () {
    'use strict';

    const SUB_UPLOAD_URL = <?= json_encode('upload-url.php') ?>;
    const SUB_GUARDAR_URL = <?= json_encode('guardar-subsanacion.php') ?>;
    const SUB_CSRF = <?= json_encode($csrf) ?>;
    const SUB_NUMERO = <?= json_encode($numero) ?>;
    const SUB_DNI = <?= json_encode($dni) ?>;
    const SUB_ALLOWED = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    const SUB_MAX = 10 * 1024 * 1024;

    let docs = {};
    let subiendo = false;

    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.pdf,.jpg,.jpeg,.png,.webp';
    input.multiple = true;
    input.style.display = 'none';
    document.body.appendChild(input);

    function mostrar(tipo, texto) {
        const el = document.getElementById('subMessage');
        el.className = 'flex items-center gap-2 rounded-lg p-3 text-sm '
            + (tipo === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800');
        el.innerHTML = texto;
        el.classList.remove('hidden');
    }

    function render() {
        const list = document.getElementById('subFileList');
        const claves = Object.keys(docs);
        list.innerHTML = claves.map(function (clave) {
            const f = docs[clave];
            return '<li class="flex items-center gap-3 rounded-lg border bg-white px-3 py-2.5">'
                + '<div class="flex-1 min-w-0"><p class="text-sm font-medium truncate">' + f.name + '</p></div>'
                + '<button type="button" data-quitar="' + clave + '" class="shrink-0 text-xs font-medium text-red-500 hover:text-red-700 p-1">Quitar</button></li>';
        }).join('');
        Array.prototype.forEach.call(list.querySelectorAll('[data-quitar]'), function (btn) {
            btn.addEventListener('click', function () {
                delete docs[btn.getAttribute('data-quitar')];
                render();
            });
        });
        actualizarBoton();
    }

    function actualizarBoton() {
        const desc = document.getElementById('subDescripcion').value.trim();
        document.getElementById('subSubmitBtn').disabled = subiendo || desc === '' || Object.keys(docs).length < 1;
    }

    document.getElementById('subSelectBtn').addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function (e) {
        for (const file of e.target.files) {
            if (!SUB_ALLOWED.includes(file.type)) { mostrar('error', 'Solo se permiten archivos PDF o imagen: ' + file.name); continue; }
            if (file.size > SUB_MAX) { mostrar('error', 'Archivo demasiado grande (máx 10MB): ' + file.name); continue; }
            docs[file.name + '-' + Date.now()] = file;
        }
        input.value = '';
        render();
    });
    document.getElementById('subDescripcion').addEventListener('input', actualizarBoton);

    document.getElementById('subsForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (subiendo) return;
        subiendo = true;
        const btn = document.getElementById('subSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Enviando…';
        document.getElementById('subProgress').classList.remove('hidden');
        document.getElementById('subMessage').classList.add('hidden');

        try {
            const subsanacionId = crypto.randomUUID();
            const files = [];
            for (const key in docs) {
                const file = docs[key];
                const urlRes = await fetch(SUB_UPLOAD_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        fileName: file.name,
                        fileType: file.type,
                        fileSize: file.size,
                        submissionType: 'subsanacion',
                        submissionId: subsanacionId
                    })
                });
                if (!urlRes.ok) throw new Error('Error al obtener URL de carga');
                const res = await urlRes.json();
                const up = await fetch(res.url, {
                    method: 'PUT',
                    body: file,
                    headers: Object.assign({ 'Content-Type': file.type }, res.headers || {})
                });
                if (!up.ok) throw new Error('Error subiendo archivo: ' + file.name);
                files.push({ key: res.key, originalName: file.name, fileType: file.type });
            }

            const guardarRes = await fetch(SUB_GUARDAR_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: SUB_CSRF,
                    subsanacionId,
                    numero: SUB_NUMERO,
                    dni: SUB_DNI,
                    descripcion: document.getElementById('subDescripcion').value.trim(),
                    nombre: document.getElementById('subNombre').value.trim(),
                    telefono: document.getElementById('subTelefono').value.trim(),
                    files: files
                })
            });
            const data = await guardarRes.json().catch(function () { return {}; });
            if (!guardarRes.ok) {
                const detail = (data.details && data.details.length) ? (': ' + data.details.join(' | ')) : '';
                throw new Error((data.error || 'Error al registrar la subsanación') + detail);
            }

            const acuse = data.acuse || {};
            mostrar('success',
                'Subsanación registrada con <strong>Nº de cargo ' + (acuse.nroCargo || '') + '</strong>. '
                + '<a class="underline font-semibold" href="acuse.php?sub=' + encodeURIComponent(data.subsanacionId)
                + '&t=' + encodeURIComponent(acuse.hash || '') + '" target="_blank">Descargar acuse de subsanación</a>. '
                + 'El área responsable verificará lo presentado.');

            document.getElementById('subsForm').reset();
            docs = {};
            render();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            mostrar('error', err.message || 'Error al registrar la subsanación');
        } finally {
            subiendo = false;
            btn.textContent = 'Presentar subsanación';
            document.getElementById('subProgress').classList.add('hidden');
            actualizarBoton();
        }
    });

    render();
})();
</script>
<?php endif; ?>
<?php View::footer($config); ?>
<?php View::fin(); ?>
