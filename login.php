<?php

declare(strict_types=1);

$config = require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/View.php';
require __DIR__ . '/includes/AmspApiClient.php';

session_name('AMSP_CLIENTE');
session_start();

// ---------------------------
// Procesar consulta (POST)
// ---------------------------
$dni = null;
$socio = null;
$error = null;

$dia = null;
$mes = null;
$anio = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // "Cerrar sesión" (envía id vacío): limpiar la sesión del asociado.
    if (trim((string) ($_POST['id'] ?? '')) === '' && isset($_SESSION[$config['session']['key']])) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $dni = normalizarDni($_POST['id'] ?? '');
    if ($dni === null) {
        $error = 'Debe ingresar un DNI válido de 8 dígitos.';
    }
    $dia = (int) ($_POST['dia'] ?? '');
    $mes = (int) ($_POST['mes'] ?? '');
    $anio = (int) ($_POST['anio'] ?? '');
    if (!checkdate($mes, $dia, $anio)) {
        $error = 'Debe ingresar una fecha de nacimiento válida.';
    }
} elseif (isset($_SESSION[$config['session']['key']])) {
    $dni = normalizarDni($_SESSION[$config['session']['key']]);
    $dia = (int) ($_SESSION['fec_dia'] ?? '');
    $mes = (int) ($_SESSION['fec_mes'] ?? '');
    $anio = (int) ($_SESSION['fec_anio'] ?? '');
}

if ($dni !== null && $error === null) {
    $api = new AmspApiClient($config['api']['base']);
    $socio = $api->getSocio($dni, $dia, $mes, $anio);
    if ($socio === null) {
        $error = 'No se encontraron datos para el DNI ingresado.';
    } else {
        $_SESSION[$config['session']['key']] = $dni;
        $_SESSION['fec_dia'] = $dia;
        $_SESSION['fec_mes'] = $mes;
        $_SESSION['fec_anio'] = $anio;
        // Nombre real del asociado (para que aparezca en el panel administrativo)
        $_SESSION['amsp_cliente_nombre'] = trim(idx($socio, 2));
    }
}

$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;
?>
<?php View::head('Portal del Asociado | AMSP', $config, 'portal'); ?>
?>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1 text-center">Estado de Cuenta de Préstamo</h1>
            <p class="text-sm text-gray-500 mb-6 text-center">Asociación Mutualista Sanitaria del Perú</p>

            <?php if ($error !== null): ?>
                <div class="flex items-center gap-2 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800 mb-6">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($socio === null): ?>
                <!-- ===================== CONSULTA ===================== -->
                <div class="bg-white rounded-2xl shadow-lg border p-4 sm:p-6 max-w-xl mx-auto">
                    <h2 class="text-base font-semibold mb-3">Iniciar sesión con tu DNI</h2>
                    <form method="post" class="space-y-4">
                        <div>
                            <label for="dni" class="block text-sm font-medium text-gray-700 mb-1">D.N.I</label>
                            <input id="dni" name="id" type="text" inputmode="numeric" maxlength="8" required
                                   placeholder="Documento de Identidad - DNI"
                                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,8)"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="fecnac" class="block text-sm font-medium text-gray-700 mb-1">Fecha de Nacimiento</label>
                            <div class="grid grid-cols-3 gap-2">
                                <select id="dia" name="dia" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="" disabled selected>Día</option>
                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?= $d ?>" <?= $dia === $d ? 'selected' : '' ?>><?= str_pad((string) $d, 2, '0', STR_PAD_LEFT) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select id="mes" name="mes" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="" disabled selected>Mes</option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $mes === $m ? 'selected' : '' ?>><?= str_pad((string) $m, 2, '0', STR_PAD_LEFT) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select id="anio" name="anio" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="" disabled selected>Año</option>
                                    <?php for ($a = date('Y'); $a >= 1930; $a--): ?>
                                        <option value="<?= $a ?>" <?= $anio === $a ? 'selected' : '' ?>><?= $a ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 rounded-lg bg-blue-50 border border-blue-100 p-3 text-xs text-blue-800">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>Solo se registran los asociados activos. Quienes tengan condición de retiro deben llamar al (01) 424-3262 / (01) 331-0083 o acudir a nuestras oficinas.</span>
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 transition-colors">Entrar / Consultar</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- ===================== DATOS DEL ASOCIADO ===================== -->
                <div class="bg-white rounded-2xl shadow-lg border overflow-hidden mb-6">
                    <div class="bg-blue-50 border-b border-blue-100 px-4 sm:px-6 py-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-800">Datos del asociado</h2>
                        <div class="flex items-center gap-2">
                            <form method="post" class="m-0">
                                <button type="submit" name="id" value="" class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-red-600 p-1 transition-colors">Cerrar sesión</button>
                            </form>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">Código</dt>
                                <dd class="mt-0.5 font-semibold"><?= e(idx($socio, 0)) ?></dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium text-gray-500 uppercase">Apellidos y nombres</dt>
                                <dd class="mt-0.5 font-semibold"><?= e(idx($socio, 2)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">N° DNI</dt>
                                <dd class="mt-0.5 font-semibold"><?= e(idx($socio, 3)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">Compañía</dt>
                                <dd class="mt-0.5"><?= e(idx($socio, 18)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">Condición</dt>
                                <dd class="mt-0.5"><span class="inline-flex rounded-full bg-green-100 text-green-800 px-2 py-0.5 text-xs font-semibold"><?= e(condicionLabel(idx($socio, 1))) ?></span></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">U. Proceso</dt>
                                <dd class="mt-0.5"><?= e(idx($socio, 21)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">Base</dt>
                                <dd class="mt-0.5"><?= e(idx($socio, 19)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">Establecimiento</dt>
                                <dd class="mt-0.5"><?= e(idx($socio, 20)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">F. Ingreso</dt>
                                <dd class="mt-0.5"><?= e(formatFecha(idx($socio, 10))) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">F. Nacimiento</dt>
                                <dd class="mt-0.5"><?= e(sprintf('%02d/%02d/%04d', $dia, $mes, $anio)) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 uppercase">Ejecutora</dt>
                                <dd class="mt-0.5"><?= e(idx($socio, 22)) ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- ===================== SOLICITUDES ===================== -->
                <div class="bg-white rounded-2xl shadow-lg border overflow-hidden">
                    <div class="px-4 sm:px-6 py-3 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-800">Enviar solicitudes</h2>
                        <div class="flex gap-1 text-xs font-medium">
                            <button type="button" data-tab="credito" onclick="cambiarTab('credito')" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white transition-colors">Solicitud de Crédito</button>
                            <button type="button" data-tab="preeval" onclick="cambiarTab('preeval')" class="px-3 py-1.5 rounded-lg text-gray-600 hover:bg-gray-100 transition-colors">Pre-evaluación</button>
                        </div>
                    </div>

                    <p class="px-4 sm:px-6 pb-3 text-xs text-gray-400">Estos trámites también pueden presentarse desde la <a href="mpv/" class="text-blue-600 underline">Mesa de Partes Virtual</a> (sin necesidad de iniciar sesión).</p>

                    <div id="tab-credito" class="p-4 sm:p-6">
                        <?php include __DIR__ . '/includes/solicitud-credito.php'; ?>
                    </div>

                    <div id="tab-preeval" class="p-4 sm:p-6 hidden">
                        <?php include __DIR__ . '/includes/pre-evaluacion.php'; ?>
                    </div>
                </div>

                <!-- ===================== MIS SOLICITUDES ===================== -->
                <div class="bg-white rounded-2xl shadow-lg border overflow-hidden mt-8">
                    <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-800">Mis solicitudes</h2>
                    </div>
                    <div class="p-4 sm:p-6">
                        <p id="misSolicitudesCargando" class="text-sm text-gray-500">Cargando sus solicitudes...</p>
                        <div id="misSolicitudes" class="space-y-3"></div>
                        <p id="misSolicitudesVacio" class="hidden text-sm text-gray-500">Aún no tiene solicitudes enviadas.</p>
                    </div>
                </div>

                <!-- Modal de previsualización de archivos enviados -->
                <div id="prevModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4" onclick="window.__cerrarPrev(event)">
                    <div class="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" onclick="event.stopPropagation()">
                        <div class="flex shrink-0 items-center justify-between border-b p-4">
                            <h2 id="prevModalTitulo" class="truncate pr-4 text-sm font-semibold text-gray-900">Archivos enviados</h2>
                            <button type="button" onclick="window.__cerrarPrev(event)" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 hover:bg-gray-100">Cerrar</button>
                        </div>
                        <div id="prevModalBody" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
<?php View::footer($config); ?>

    <script>
    const CSRF_TOKEN = <?= json_encode($csrf) ?>;
    const APP = <?= json_encode($config['app']) ?>;

    function cambiarTab(tab) {
        document.getElementById('tab-credito').classList.toggle('hidden', tab !== 'credito');
        document.getElementById('tab-preeval').classList.toggle('hidden', tab !== 'preeval');
        document.querySelectorAll('[data-tab]').forEach(btn => {
            const active = btn.dataset.tab === tab;
            btn.classList.toggle('bg-blue-600', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('text-gray-600', !active);
            btn.classList.toggle('hover:bg-gray-100', !active);
        });
    }
    (function () {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        if (tab === 'credito' || tab === 'preeval') cambiarTab(tab);
    })();

    function subirArchivos(files, tipo) {
        const entries = [];
        const promises = [];
        const submissionId = crypto.randomUUID();
        for (let key in files) {
            const file = files[key];
            promises.push(fetch(APP.uploadUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fileName: file.name, fileType: file.type, fileSize: file.size, submissionType: tipo, submissionId })
            }).then(r => {
                if (!r.ok) throw new Error('Error al obtener URL de carga');
                return r.json();
            }).then(({ url, key: s3Key, headers }) => fetch(url, {
                method: 'PUT',
                body: file,
                headers: Object.assign({ 'Content-Type': file.type }, headers || {})
            }).then(r => {
                if (!r.ok) throw new Error('Error subiendo archivo: ' + file.name);
                entries.push({ key: s3Key, originalName: file.name, fileType: file.type });
            })));
        }
        return Promise.all(promises).then(() => ({ entries, submissionId }));
    }

    function enviarSolicitud(payload, csrf) {
        return fetch(APP.guardarUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Idempotency-Key': (crypto.randomUUID?.() || Date.now() + '-' + Math.random().toString(36).slice(2, 10))
            },
            body: JSON.stringify({ ...payload, _csrf: csrf })
        });
    }
    </script>
    <script src="assets/js/cliente.js"></script>
<?php View::fin(); ?>
