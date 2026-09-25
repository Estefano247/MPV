<?php

declare(strict_types=1);

$config = require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/View.php';

$mpv = $config['mpv'];

session_name('AMSP_CLIENTE');
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$uploadUrl = '../upload-url.php';
$guardarUrl = '../guardar.php';

$tiposPermitidos = [
    'mpv', 'afiliacion', 'prestamo-solidario', 'pre-evaluacion',
    'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
    'auxilio-fallecimiento',
];

// "credito" y "prestamo-solidario" son el mismo tramite: era el mismo listado de
// documentos escrito de dos formas (un requisito en el sitio, un texto suelto en
// el otro). Se ofrece una sola opcion y se guarda el canonico, pero los enlaces
// viejos con ?tipo=credito y las filas historicas siguen funcionando.
$tipoInicial = (string) ($_GET['tipo'] ?? 'mpv');
$tipoInicial = $tipoInicial === 'credito' ? 'prestamo-solidario' : $tipoInicial;
if (!in_array($tipoInicial, $tiposPermitidos, true)) {
    $tipoInicial = 'mpv';
}
?>
<?php View::head((string) $mpv['titulo'] . ' | ' . (string) $mpv['apex'], $config, 'mpv'); ?>
?>
            <div class="rounded-xl bg-blue-950 text-white p-4 sm:p-6 mb-6">
                <h1 class="text-xl sm:text-2xl font-bold">Mesa de Partes Virtual</h1>
                <p class="text-sm text-blue-200 mt-1">Presente sus trámites y documentos de manera electrónica. Al registrar su
                    presentación recibirá un <strong>Nº de cargo</strong>, un <strong>Nº de expediente</strong> y un
                    <strong>Acuse de Recibo descargable</strong>.</p>
                <p class="text-xs text-blue-300 mt-2">Directiva: <?= e((string) $mpv['numero']) ?> &middot; Responsable: <?= e((string) $mpv['responsable']) ?></p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl shadow-lg border p-4 sm:p-6">
                        <p id="mpvSubtitle" class="text-sm text-gray-500 mb-4">Los campos marcados con * son obligatorios. Documentos admisibles: PDF o imagen (máx. 10 MB cada uno).</p>

                        <form id="mpvForm" class="space-y-4">
                            <div>
                                <label for="mpvTipo" class="block text-sm font-medium text-gray-700 mb-1">Tipo de trámite *</label>
                                <select id="mpvTipo" onchange="mpvAplicarTipo()"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="mpv" <?= $tipoInicial === 'mpv' ? 'selected' : '' ?>>Documento general (Mesa de Partes)</option>
                                    <option value="afiliacion" <?= $tipoInicial === 'afiliacion' ? 'selected' : '' ?>>Solicitud de Afiliación</option>
                                    <option value="pre-evaluacion" <?= $tipoInicial === 'pre-evaluacion' ? 'selected' : '' ?>>Pre-evaluación crediticia</option>
                                    <optgroup label="Prestaciones / Beneficios">
                                        <option value="auxilio-retiro" <?= $tipoInicial === 'auxilio-retiro' ? 'selected' : '' ?>>Auxilio por Retiro</option>
                                        <option value="auxilio-invalidez" <?= $tipoInicial === 'auxilio-invalidez' ? 'selected' : '' ?>>Auxilio por Invalidez</option>
                                        <option value="seguro-sepelio" <?= $tipoInicial === 'seguro-sepelio' ? 'selected' : '' ?>>Seguro de Sepelio Familiar</option>
                                        <option value="prestamo-solidario" <?= $tipoInicial === 'prestamo-solidario' ? 'selected' : '' ?>>Préstamo Solidario</option>
                                        <option value="auxilio-fallecimiento" <?= $tipoInicial === 'auxilio-fallecimiento' ? 'selected' : '' ?>>Auxilio por Fallecimiento</option>
                                    </optgroup>
                                </select>
                            </div>

                            <div id="mpvRequisitosWrap">
                                <div class="rounded-xl border border-blue-100 bg-blue-50 p-4">
                                    <h3 class="text-sm font-semibold text-blue-900 mb-2">Requisitos para este trámite</h3>
                                    <ol id="mpvRequisitos" class="list-decimal list-inside space-y-1.5 text-sm text-blue-900"></ol>
                                    <p class="mt-3 text-xs text-blue-700">Adjunte copias legibles de cada requisito (PDF o imagen).</p>
                                </div>
                            </div>

                            <div id="mpvAfiTipoWrap" class="hidden">
                                <label for="mpvAfiTipo" class="block text-sm font-medium text-gray-700 mb-1">Tipo de asociado *</label>
                                <select id="mpvAfiTipo" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="Nombrado">Nombrado</option>
                                    <option value="CAS Indeterminado">CAS Indeterminado</option>
                                </select>
                            </div>

                            <div id="mpvTipoDocWrap" class="hidden">
                                <label for="mpvTipoDoc" class="block text-sm font-medium text-gray-700 mb-1">Tipo de documento *</label>
                                <select id="mpvTipoDoc" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="Solicitud">Solicitud</option>
                                    <option value="Carta">Carta</option>
                                    <option value="Constancia">Constancia</option>
                                    <option value="Reclamo">Reclamo / Sugerencia</option>
                                    <option value="Certificado">Certificado</option>
                                    <option value="Otro">Otro documento</option>
                                </select>
                            </div>

                            <div id="mpvAreaWrap" class="hidden">
                                <label for="mpvArea" class="block text-sm font-medium text-gray-700 mb-1">Área de destino</label>
                                <select id="mpvArea" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Mesa de Partes (sin derivar)</option>
                                    <?php
                                    try {
                                        require_once __DIR__ . '/../includes/AreaRepository.php';
                                        require_once __DIR__ . '/../includes/Setup.php';
                                        Setup::ensureDatabase();
                                        foreach ((new AreaRepository())->listar() as $area) {
                                            if (str_contains(strtolower((string) $area['nombre']), 'mesa de partes')) continue;
                                            echo '<option value="' . (int) $area['id'] . '">' . e((string) $area['nombre']) . '</option>';
                                        }
                                    } catch (Throwable) {
                                        // La lista de áreas se cargará sin opciones si la BD aún no está lista.
                                    }
                                    ?>
                                </select>
                            </div>

                            <div>
                                <label for="mpvNombre" class="block text-sm font-medium text-gray-700 mb-1">Nombres y apellidos *</label>
                                <input id="mpvNombre" type="text" required oninput="mpvActualizar()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="mpvDni" class="block text-sm font-medium text-gray-700 mb-1">DNI *</label>
                                    <input id="mpvDni" type="text" required maxlength="8" inputmode="numeric" placeholder="12345678"
                                           oninput="this.value=this.value.replace(/\D/g,'').slice(0,8);mpvActualizar()"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label id="mpvTelefonoLabel" for="mpvTelefono" class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                                    <input id="mpvTelefono" type="tel" maxlength="9" inputmode="numeric" placeholder="987654321"
                                           oninput="this.value=this.value.replace(/\D/g,'').slice(0,9);mpvActualizar()"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <div>
                                <label for="mpvEmail" class="block text-sm font-medium text-gray-700 mb-1">Correo electrónico *</label>
                                <input id="mpvEmail" type="email" required oninput="mpvActualizar()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div id="mpvAsuntoWrap" class="hidden">
                                <label for="mpvAsunto" class="block text-sm font-medium text-gray-700 mb-1">Asunto / referencia *</label>
                                <textarea id="mpvAsunto" rows="3" maxlength="2000" required oninput="mpvActualizar()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Documentos adjuntos *</h3>
                                <p id="mpvDocsHint" class="text-xs text-gray-500 mb-2"></p>
                                <button type="button" id="mpvSelectBtn" class="flex items-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 hover:border-blue-500 hover:text-blue-600 transition-colors bg-transparent cursor-pointer">
                                    Seleccionar archivos (PDF o imagen)
                                </button>
                                <ul id="mpvFileList" class="space-y-2 mt-3"></ul>
                            </div>

                            <div id="mpvProgress" class="hidden flex items-center gap-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-800">Registrando su presentación y subiendo documentos...</div>
                            <div id="mpvMessage" class="hidden flex items-center gap-2 rounded-lg p-3 text-sm"></div>

                            <button type="submit" id="mpvSubmitBtn" disabled class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                Registrar presentación
                            </button>
                            <p class="text-xs text-gray-400 text-center">
                                Al enviar, acepta la política de privacidad y el tratamiento de sus datos personales.
                            </p>
                        </form>
                    </div>
                </div>

                <aside class="space-y-4">
                    <div class="bg-white rounded-2xl shadow-lg border p-5">
                        <h3 class="font-semibold text-gray-800 text-sm mb-2">Servicios</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="index.php" class="text-blue-600 hover:underline">Documento general (Mesa de Partes)</a></li>
                            <li><a href="index.php?tipo=afiliacion" class="text-blue-600 hover:underline">Solicitud de Afiliación</a></li>
                            <li><a href="index.php?tipo=credito" class="text-blue-600 hover:underline">Solicitud de Crédito</a></li>
                            <li><a href="index.php?tipo=pre-evaluacion" class="text-blue-600 hover:underline">Pre-evaluación crediticia</a></li>
                            <li><a href="index.php?tipo=auxilio-retiro" class="text-blue-600 hover:underline">Auxilio por Retiro</a></li>
                            <li><a href="index.php?tipo=auxilio-invalidez" class="text-blue-600 hover:underline">Auxilio por Invalidez</a></li>
                            <li><a href="index.php?tipo=seguro-sepelio" class="text-blue-600 hover:underline">Seguro de Sepelio Familiar</a></li>
                            <li><a href="index.php?tipo=prestamo-solidario" class="text-blue-600 hover:underline">Préstamo Solidario</a></li>
                            <li><a href="index.php?tipo=auxilio-fallecimiento" class="text-blue-600 hover:underline">Auxilio por Fallecimiento</a></li>
                            <li><a href="../seguimiento.php" class="text-blue-600 hover:underline">Seguimiento de trámites</a></li>
                            <li><a href="../login.php" class="text-blue-600 hover:underline">Portal del Asociado (estado de cuenta)</a></li>
                            <li><a href="../index.php" class="text-blue-600 hover:underline">Simulador de préstamo</a></li>
                        </ul>
                    </div>
                    <div class="bg-white rounded-2xl shadow-lg border p-5">
                        <h3 class="font-semibold text-gray-800 text-sm mb-2">Información institucional</h3>
                        <ul class="space-y-2 text-sm text-gray-600">
                            <li>Directiva Nº <?= e((string) $mpv['numero']) ?></li>
                            <li>Responsable: <?= e((string) $mpv['responsable']) ?></li>
                            <li>La directiva, la política de privacidad, el manual de procedimientos y el plan de contingencia se versionan en <code>docs/</code> del repositorio.</li>
                        </ul>
                    </div>
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-xs text-amber-800 space-y-1">
                        <p><strong>Horario de atención:</strong> <?= e((string) $mpv['horario']) ?></p>
                        <p><strong>Correo:</strong> <?= e((string) $mpv['correo']) ?></p>
                        <p><strong>Teléfono:</strong> <?= e((string) $mpv['telefono']) ?></p>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <!-- Modal de previsualización -->
    <div id="mpvPrev" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 p-4" onclick="window.__mpvCerrarPrev(event)">
        <div class="flex h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" onclick="event.stopPropagation()">
            <div class="flex shrink-0 items-center justify-between border-b px-4 py-3">
                <h3 id="mpvPrevTitulo" class="max-w-full truncate pr-4 text-sm font-semibold text-gray-900">Vista previa</h3>
                <button type="button" onclick="window.__mpvCerrarPrev(event)" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 hover:bg-gray-100">Cerrar</button>
            </div>
            <div id="mpvPrevBody" class="min-h-0 flex-1 bg-gray-100"></div>
        </div>
    </div>

<?php View::footer($config); ?>

    <script>
    const MPV_UPLOAD_URL = <?= json_encode($uploadUrl) ?>;
    const MPV_GUARDAR_URL = <?= json_encode($guardarUrl) ?>;
    const MPV_CSRF = <?= json_encode($csrf) ?>;
    const MPV_TIPO_INICIAL = <?= json_encode($tipoInicial) ?>;
    const MPV_ALLOWED = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    const MPV_TIPOS = {
        'mpv': {
            label: 'Documento general (Mesa de Partes)',
            title: 'Documento / trámite general',
            minFiles: 1,
            telReq: false,
            docsHint: 'Adjunte el documento que sustenta su presentación (PDF o imagen).',
            requisitos: [
                'Documento que sustenta la presentación, en PDF o imagen legible',
                'DNI del asociado (copia legible)',
                'Documento que acredite la representación, solo si actúa mediante apoderado'
            ]
        },
        'afiliacion': {
            label: 'Solicitud de Afiliación',
            title: 'Afiliación de nuevo asociado',
            minFiles: 1,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'Última boleta de pago (copia legible)',
                'DNI del asociado (copia legible)',
                'Solicitud de afiliación firmada',
                'Solicitud de autorización de uso de datos personales firmada'
            ]
        },
        'pre-evaluacion': {
            label: 'Pre-evaluación crediticia',
            title: 'Pre-evaluación crediticia',
            minFiles: 4,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'Tres (3) últimas boletas de pago, consecutivas',
                'DNI del asociado (copia legible)',
                'Documento de identidad del spouse o conviviente, si corresponde',
                'Comprobante o estado de cuenta de la cuenta de ahorro del asociado'
            ]
        },
        'auxilio-retiro': {
            label: 'Auxilio por Retiro',
            title: 'Auxilio por Retiro',
            minFiles: 1,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'DNI del asociado (copia legible)',
                'Última boleta de pago con aportaciones (copia legible)',
                'Resolución de Cese (copia legalizada actualizada o fedateada por la base)',
                'N° de cuenta de ahorro del asociado (voucher)'
            ]
        },
        'auxilio-invalidez': {
            label: 'Auxilio por Invalidez',
            title: 'Auxilio por Invalidez',
            minFiles: 1,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'DNI del asociado (copia legible)',
                'Última boleta de pago con aportaciones (copia legible)',
                'Resolución de Cese por invalidez permanente (copia legalizada por notario público o fedateada por la base), actualizada',
                'Acta de la Junta Médica (original o legalizada por notario público), actualizada',
                'N° de cuenta de ahorro del asociado (copia simple legible)'
            ]
        },
        'seguro-sepelio': {
            label: 'Seguro de Sepelio Familiar',
            title: 'Seguro de Sepelio Familiar',
            minFiles: 1,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'Acta de defunción (original)',
                'DNI del asociado (copia simple)',
                'Última boleta de pago con aportaciones (copia)',
                'Partida de Nacimiento del asociado (original o legalizada por notario público)',
                'Partida de Nacimiento del hijo (menor de 25 años) (original o legalizada por notario público)',
                'Partida de Matrimonio (original o legalizada por notario público)',
                'Certificado de Convivencia (original o legalizada por notario público)',
                'Factura o boleta de venta por uso de nicho/fosa o cremación (copia simple)',
                'N° de cuenta de ahorro del asociado (copia simple legible / voucher)'
            ]
        },
        'prestamo-solidario': {
            label: 'Préstamo Solidario',
            title: 'Préstamo Solidario',
            minFiles: 1,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'Fotocopia de última boleta de pago',
                'Fotocopia de boleta de incentivos laborales',
                'Fotocopia de documento de identidad (DNI)',
                'Fotocopia de teleahorro o voucher de su cuenta del Banco de la Nación',
                'Solicitud de préstamo entregada por la AMSP o imprimir la solicitud publicada aquí para luego llenarla y entregarla',
                'Autorización de descuento'
            ]
        },
        'auxilio-fallecimiento': {
            label: 'Auxilio por Fallecimiento',
            title: 'Auxilio por Fallecimiento',
            minFiles: 1,
            telReq: true,
            docsHint: 'Adjunte copias legibles de cada requisito indicado (PDF o imagen).',
            requisitos: [
                'Acta de defunción del asociado (original o legalizada por notario público), actualizada',
                'DNI del asociado (copia legible)',
                'Última boleta de pago con aportaciones (copia)',
                'Factura o boleta de venta por uso de nicho/fosa o cremación (copia simple)',
                'Testimonio de sucesión intestada + inscripción en la Sunarp (legalizada por notario público), siempre que no exista sobre de declaratoria de beneficiarios en la AMSP',
                'DNI de los beneficiarios o herederos (copia simple legible)',
                'N° de cuenta de ahorro de los beneficiarios o herederos (copia simple legible)'
            ]
        }
    };

    let mpvDocs = {};
    let mpvSubiendo = false;
    const mpvInput = document.createElement('input');
    mpvInput.type = 'file';
    mpvInput.accept = '.pdf,.jpg,.jpeg,.png,.webp';
    mpvInput.multiple = true;
    mpvInput.style.display = 'none';
    document.body.appendChild(mpvInput);

    function mpvTipoActual() {
        return document.getElementById('mpvTipo').value;
    }

    function mpvMsg(type, text) {
        const el = document.getElementById('mpvMessage');
        el.className = 'flex items-center gap-2 rounded-lg p-3 text-sm ' + (type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800');
        el.innerHTML = text;
        el.classList.remove('hidden');
    }

    function mpvTamano(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function mpvEsImagen(file) {
        return (file.type || '').startsWith('image/');
    }

    function mpvRender() {
        const list = document.getElementById('mpvFileList');
        const keys = Object.keys(mpvDocs);
        list.innerHTML = keys.map(k => {
            const d = mpvDocs[k];
            const f = d.file;
            const tile = mpvEsImagen(f)
                ? '<button type="button" onclick="window.__mpvPreview(\'' + k + '\')" class="h-12 w-12 shrink-0 cursor-zoom-in overflow-hidden rounded-lg border border-gray-200" title="Previsualizar">'
                    + '<img src="' + d.url + '" alt="" class="h-full w-full object-cover"></button>'
                : '<div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600"><span class="text-[9px] font-bold leading-none">PDF</span></div>';
            return '<li class="flex items-center gap-3 rounded-lg border bg-white px-3 py-2.5">'
                + tile
                + '<div class="flex-1 min-w-0"><p class="text-sm font-medium text-gray-900 truncate">' + f.name + '</p>'
                + '<p class="text-xs text-gray-400">' + mpvTamano(f.size) + '</p></div>'
                + '<button type="button" onclick="window.__mpvPreview(\'' + k + '\')" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">Previsualizar</button>'
                + '<button type="button" onclick="window.__mpvQuitar(\'' + k + '\')" class="shrink-0 text-xs font-medium text-red-500 hover:text-red-700 p-1">Quitar</button></li>';
        }).join('');
        mpvActualizar();
    }
    window.__mpvQuitar = function (key) {
        const d = mpvDocs[key];
        if (d && d.url) URL.revokeObjectURL(d.url);
        delete mpvDocs[key];
        mpvRender();
    };

    // ----- Previsualización local antes de enviar -----
    function mpvAbrirPrev(key) {
        const d = mpvDocs[key];
        if (!d) return;
        document.getElementById('mpvPrevTitulo').textContent = d.file.name;
        const body = document.getElementById('mpvPrevBody');
        body.innerHTML = mpvEsImagen(d.file)
            ? '<div class="flex min-h-full items-center justify-center"><img src="' + d.url + '" alt="" class="max-h-full max-w-full object-contain"></div>'
            : '<iframe src="' + d.url + '" class="h-full w-full border-0"></iframe>';
        document.getElementById('mpvPrev').classList.remove('hidden');
        document.getElementById('mpvPrev').classList.add('flex');
    }
    window.__mpvPreview = mpvAbrirPrev;
    window.__mpvCerrarPrev = function (e) {
        if (e && e.target !== e.currentTarget) return;
        const m = document.getElementById('mpvPrev');
        m.classList.add('hidden');
        m.classList.remove('flex');
        document.getElementById('mpvPrevBody').innerHTML = '';
    };

    function mpvActualizar() {
        const t = mpvTipoActual();
        const tipo = MPV_TIPOS[t] || MPV_TIPOS['mpv'];
        const btn = document.getElementById('mpvSubmitBtn');
        const nombreOk = (document.getElementById('mpvNombre').value || '').trim() !== '';
        const dniOk = /^\d{8}$/.test(document.getElementById('mpvDni').value || '');
        const telRaw = (document.getElementById('mpvTelefono').value || '').replace(/\D/g, '');
        const telOk = tipo.telReq ? /^\d{9}$/.test(telRaw) : (telRaw === '' || /^\d{7,15}$/.test(telRaw));
        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(document.getElementById('mpvEmail').value || '');
        const asuntoOk = t === 'mpv' ? (document.getElementById('mpvAsunto').value || '').trim().length >= 5 : true;
        const docsOk = Object.keys(mpvDocs).length >= tipo.minFiles;
        btn.disabled = mpvSubiendo || !nombreOk || !dniOk || !telOk || !emailOk || !asuntoOk || !docsOk;
    }

    function mpvRenderRequisitos() {
        const t = mpvTipoActual();
        const tipo = MPV_TIPOS[t] || MPV_TIPOS['mpv'];
        const wrap = document.getElementById('mpvRequisitosWrap');
        const list = document.getElementById('mpvRequisitos');
        // El bloque se muestra siempre. Antes se ocultaba cuando el tramite no
        // traia lista propia, y esos requisitos solo aparecian sueltos en texto
        // chico dentro de "documentos adjuntos", que es donde nadie los busca.
        const reqs = tipo.requisitos || [];
        if (reqs.length === 0) {
            list.innerHTML = '<li>Este tramite no tiene requisitos adicionales.</li>';
        } else {
            list.innerHTML = reqs.map(r => '<li>' + r + '</li>').join('');
        }
        wrap.classList.remove('hidden');
    }

    function mpvAplicarTipo() {
        const t = mpvTipoActual();
        const tipo = MPV_TIPOS[t] || MPV_TIPOS['mpv'];
        document.getElementById('mpvTipoDocWrap').classList.toggle('hidden', t !== 'mpv');
        document.getElementById('mpvAfiTipoWrap').classList.toggle('hidden', t !== 'afiliacion');
        document.getElementById('mpvAreaWrap').classList.toggle('hidden', t !== 'mpv');
        document.getElementById('mpvAsuntoWrap').classList.toggle('hidden', t !== 'mpv');
        document.getElementById('mpvDocsHint').textContent = tipo.docsHint;
        document.getElementById('mpvSubtitle').textContent = tipo.title + '. Los campos marcados con * son obligatorios. Documentos admisibles: PDF o imagen (máx. 10 MB cada uno).';
        document.getElementById('mpvTelefonoLabel').innerHTML = 'Teléfono' + (tipo.telReq ? ' *' : '');
        document.getElementById('mpvSubmitBtn').textContent = t === 'mpv' ? 'Registrar presentación' : 'Enviar solicitud';
        mpvRenderRequisitos();
        mpvActualizar();
    }

    document.getElementById('mpvSelectBtn').addEventListener('click', () => mpvInput.click());
    mpvInput.addEventListener('change', function (e) {
        for (let file of e.target.files) {
            if (!MPV_ALLOWED.includes(file.type)) { mpvMsg('error', 'Solo se permite PDF o imagen: ' + file.name); continue; }
            if (file.size > 10 * 1024 * 1024) { mpvMsg('error', 'Archivo mayor a 10 MB: ' + file.name); continue; }
            mpvDocs[file.name + '-' + Date.now()] = { file, url: URL.createObjectURL(file) };
        }
        mpvInput.value = '';
        mpvRender();
    });

    function mpvDescripcion() {
        const t = mpvTipoActual();
        if (t === 'afiliacion') return 'Solicitud de afiliación - ' + document.getElementById('mpvAfiTipo').value;
        if (t === 'credito') return 'Solicitud de crédito';
        if (t === 'pre-evaluacion') return 'Previa evaluación crediticia - 3 boletas de pago y DNI';
        if (t === 'auxilio-retiro') return 'Auxilio por Retiro';
        if (t === 'auxilio-invalidez') return 'Auxilio por Invalidez';
        if (t === 'seguro-sepelio') return 'Seguro de Sepelio Familiar';
        if (t === 'prestamo-solidario') return 'Préstamo Solidario';
        if (t === 'auxilio-fallecimiento') return 'Auxilio por Fallecimiento';
        return 'Trámite: ' + document.getElementById('mpvTipoDoc').value + ' | Asunto: ' + document.getElementById('mpvAsunto').value.trim();
    }

    document.getElementById('mpvForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (mpvSubiendo) return;
        mpvSubiendo = true;
        const btn = document.getElementById('mpvSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Registrando...';
        document.getElementById('mpvProgress').classList.remove('hidden');
        document.getElementById('mpvMessage').classList.add('hidden');

        const tipo = mpvTipoActual();

        try {
            const fileEntries = [];
            const submissionId = crypto.randomUUID();
            for (let key in mpvDocs) {
                const file = mpvDocs[key].file;
                const urlRes = await fetch(MPV_UPLOAD_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ fileName: file.name, fileType: file.type, fileSize: file.size, submissionType: tipo, submissionId })
                });
                if (!urlRes.ok) throw new Error('Error al obtener URL de carga');
                const { url, key: s3Key, headers } = await urlRes.json();
                const uploadRes = await fetch(url, { method: 'PUT', body: file, headers: Object.assign({ 'Content-Type': file.type }, headers || {}) });
                if (!uploadRes.ok) throw new Error('Error subiendo archivo: ' + file.name);
                fileEntries.push({ key: s3Key, originalName: file.name, fileType: file.type });
            }

            const areaId = (document.getElementById('mpvArea').value || '') ? parseInt(document.getElementById('mpvArea').value, 10) : 0;

            const guardarRes = await fetch(MPV_GUARDAR_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: MPV_CSRF,
                    submissionId,
                    areaId,
                    tipo,
                    nombre: document.getElementById('mpvNombre').value.trim(),
                    dni: document.getElementById('mpvDni').value,
                    telefono: document.getElementById('mpvTelefono').value,
                    email: document.getElementById('mpvEmail').value.trim(),
                    descripcion: mpvDescripcion(),
                    files: fileEntries
                })
            });
            const data = await guardarRes.json().catch(() => ({}));
            if (!guardarRes.ok) {
                const detail = (data.details && data.details.length) ? (': ' + data.details.join(' | ')) : '';
                throw new Error((data.error || 'Error al registrar') + detail);
            }
            const acuse = data.acuse || {};
            const acuseLink = '<a class="underline" href="../acuse.php?id=' + encodeURIComponent(submissionId) + '&t=' + encodeURIComponent(acuse.hash || '') + '" target="_blank">Descargar acuse de recibo</a>.';
            const continuar = tipo === 'pre-evaluacion'
                ? '<br>Puede continuar con su <a class="underline" href="index.php?tipo=credito">Solicitud de Crédito</a>.'
                : '';
            mpvMsg('success',
                'Trámite registrado con <strong>Nº de cargo ' + acuse.nroCargo + '</strong> y expediente '
                + (acuse.nroExpediente || '') + '. ' + acuseLink + continuar);
            document.getElementById('mpvForm').reset();
            for (const k in mpvDocs) if (mpvDocs[k].url) URL.revokeObjectURL(mpvDocs[k].url);
            mpvDocs = {};
            mpvRender();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            mpvMsg('error', err.message || 'Error al registrar el trámite');
        } finally {
            mpvSubiendo = false;
            btn.textContent = tipo === 'mpv' ? 'Registrar presentación' : 'Enviar solicitud';
            document.getElementById('mpvProgress').classList.add('hidden');
            mpvActualizar();
        }
    });

    // Aplicar el tipo inicial (según ?tipo=) al cargar.
    if (MPV_TIPO_INICIAL && MPV_TIPOS[MPV_TIPO_INICIAL]) {
        document.getElementById('mpvTipo').value = MPV_TIPO_INICIAL;
    }
    mpvAplicarTipo();
    </script>
<?php View::fin(); ?>
