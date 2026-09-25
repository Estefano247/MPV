(function () {
    'use strict';

    const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    const MAX_SIZE = 10 * 1024 * 1024;

    // ---------- Utilidades comunes ----------
    function mostrarMsg(id, type, text) {
        const el = document.getElementById(id);
        if (!el) return;
        el.className = 'flex items-center gap-2 rounded-lg p-3 text-sm ' +
            (type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800');
        el.innerHTML = (type === 'success'
            ? '<svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
            : '<svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>')
            + text;
        el.classList.remove('hidden');
    }

    function validarArchivo(file, msgId) {
        if (!ALLOWED_TYPES.includes(file.type)) {
            mostrarMsg(msgId, 'error', 'Solo se permiten archivos PDF o imagen: ' + file.name);
            return false;
        }
        if (file.size > MAX_SIZE) {
            mostrarMsg(msgId, 'error', 'Archivo demasiado grande (máx 10MB): ' + file.name);
            return false;
        }
        return true;
    }

    // ---------- Solicitud de Crédito ----------
    let crDocs = {};
    let crSubiendo = false;
    let crFileInput = null;

    function renderCrFiles() {
        const list = document.getElementById('crFileList');
        const keys = Object.keys(crDocs);
        if (keys.length === 0) { list.innerHTML = ''; actualizarBotonCredito(); return; }
        list.innerHTML = keys.map(function (key) {
            const file = crDocs[key];
            return '<li class="flex items-center gap-3 rounded-lg border bg-white px-3 py-2.5">'
                + '<div class="flex-1 min-w-0"><p class="text-sm font-medium truncate">' + file.name + '</p></div>'
                + '<button type="button" onclick="window.__crQuitar(\'' + key + '\')" class="flex items-center gap-1 shrink-0 text-xs font-medium text-red-500 hover:text-red-700 transition-colors p-1">'
                + '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg> Quitar</button></li>';
        }).join('');
        actualizarBotonCredito();
    }

    window.__crQuitar = function (key) {
        delete crDocs[key];
        renderCrFiles();
    };

    function actualizarBotonCredito() {
        const btn = document.getElementById('crSubmitBtn');
        const dni = (document.getElementById('crDni').value || '').replace(/\D/g, '');
        const email = (document.getElementById('crEmail').value || '').trim();
        const tel = (document.getElementById('crTelefono').value || '').replace(/\D/g, '');
        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        btn.disabled = crSubiendo || Object.keys(crDocs).length < 1 || !/^\d{8}$/.test(dni) || !/^\d{9}$/.test(tel) || !emailOk;
    }
    window.actualizarBotonCredito = actualizarBotonCredito;

    function initCredito() {
        const btn = document.getElementById('crSelectBtn');
        if (!btn) return;
        crFileInput = document.createElement('input');
        crFileInput.type = 'file';
        crFileInput.accept = '.pdf,.jpg,.jpeg,.png,.webp';
        crFileInput.multiple = true;
        crFileInput.style.display = 'none';
        document.body.appendChild(crFileInput);
        btn.addEventListener('click', function () { crFileInput.click(); });
        crFileInput.addEventListener('change', function (e) {
            for (let file of e.target.files) {
                if (validarArchivo(file, 'crMessage')) {
                    crDocs[file.name + '-' + Date.now()] = file;
                }
            }
            crFileInput.value = '';
            renderCrFiles();
        });
        document.getElementById('crDni').addEventListener('input', actualizarBotonCredito);
        document.getElementById('crEmail').addEventListener('input', function () {
            actualizarBotonCredito();
        });
        document.getElementById('crTelefono').addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 9);
            actualizarBotonCredito();
        });
        document.getElementById('formCredito').addEventListener('submit', async function (e) {
            e.preventDefault();
            if (crSubiendo) return;
            crSubiendo = true;
            document.getElementById('crSubmitBtn').disabled = true;
            document.getElementById('crSubmitBtn').textContent = 'Enviando...';
            document.getElementById('crProgress').classList.remove('hidden');
            document.getElementById('crMessage').classList.add('hidden');

            const payload = {
                name: document.getElementById('crName').value.trim(),
                email: document.getElementById('crEmail').value.trim().toLowerCase(),
                dni: document.getElementById('crDni').value.trim(),
                telefono: document.getElementById('crTelefono').value.trim(),
                description: 'Solicitud de crédito',
                type: 'credito'
            };

            try {
                const { entries, submissionId } = await subirArchivos(crDocs, 'credito');
                payload.files = entries;
                payload.submissionId = submissionId;
                const res = await enviarSolicitud(payload, CSRF_TOKEN);
                if (!res.ok) {
                    const err = await res.json();
                    const detail = (err.details && err.details.length) ? (': ' + err.details.join(' | ')) : '';
                    throw new Error((err.error || 'Error al enviar formulario') + detail);
                }
                const guardado = await res.json().catch(() => ({}));
                const acuse = guardado.acuse || {};
                const msjAcuse = acuse.nroCargo
                    ? 'Expediente registrado con Nº de cargo <strong>' + acuse.nroCargo + '</strong>. '
                    + '<a class="underline" href="acuse.php?id=' + encodeURIComponent(submissionId) + '&t=' + encodeURIComponent(acuse.hash || '') + '" target="_blank">Descargar acuse de recibo</a>.'
                    : 'Solicitud enviada exitosamente. Un asesor se comunicará con usted.';
                mostrarMsg('crMessage', 'success', msjAcuse);
                document.getElementById('crEmail').value = '';
                document.getElementById('crTelefono').value = '';
                crDocs = {};
                renderCrFiles();
                if (window.cargarMisSolicitudes) cargarMisSolicitudes();
            } catch (err) {
                mostrarMsg('crMessage', 'error', err.message || 'Error al enviar el formulario');
            } finally {
                crSubiendo = false;
                document.getElementById('crSubmitBtn').textContent = 'Enviar solicitud';
                document.getElementById('crProgress').classList.add('hidden');
                actualizarBotonCredito();
            }
        });
    }

    // ---------- Pre-evaluación ----------
    const PRE_DOCS = ['boleta1', 'boleta2', 'boleta3', 'dni'];
    let preFiles = {};
    let preSubiendo = false;
    let preInputs = {};

    function renderPreDoc(key) {
        const el = document.getElementById('doc-' + key);
        const file = preFiles[key];
        if (!file) {
            el.className = 'doc-placeholder flex items-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 mt-1 cursor-pointer hover:border-blue-500 hover:text-blue-600 transition-colors';
            el.innerHTML = '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg><span>Seleccionar</span>';
            return;
        }
        el.className = 'flex items-center gap-3 rounded-lg border bg-white px-3 py-2.5 mt-1';
        el.innerHTML = '<div class="flex-1 min-w-0"><p class="text-sm font-medium truncate">' + file.name + '</p></div>'
            + '<button type="button" onclick="window.__preQuitar(\'' + key + '\')" class="flex items-center gap-1 shrink-0 text-xs font-medium text-red-500 hover:text-red-700 p-1">'
            + '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg> Quitar</button>';
    }

    window.__preQuitar = function (key) {
        delete preFiles[key];
        renderPreDoc(key);
        actualizarBotonPre();
    };

    function actualizarBotonPre() {
        const btn = document.getElementById('preSubmitBtn');
        if (!btn) return;
        const dni = (document.getElementById('preDni').value || '').replace(/\D/g, '');
        const tel = (document.getElementById('preTelefono').value || '').replace(/\D/g, '');
        const email = (document.getElementById('preEmail').value || '').trim();
        const docsOk = PRE_DOCS.every(function (k) { return !!preFiles[k]; });
        btn.disabled = preSubiendo || !docsOk || !/^\d{8}$/.test(dni) || !/^\d{9}$/.test(tel) || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    window.actualizarBotonPre = actualizarBotonPre;

    function initPreEvaluacion() {
        const container = document.getElementById('formPre');
        if (!container) return;
        PRE_DOCS.forEach(function (key) {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.pdf,.jpg,.jpeg,.png,.webp';
            input.style.display = 'none';
            document.body.appendChild(input);
            preInputs[key] = input;
            input.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (!file) return;
                if (!validarArchivo(file, 'preMessage')) return;
                preFiles[key] = file;
                renderPreDoc(key);
                input.value = '';
                actualizarBotonPre();
            });
            document.getElementById('doc-' + key).addEventListener('click', function () {
                if (!preSubiendo) input.click();
            });
        });
        ['preDni', 'preTelefono', 'preEmail'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', actualizarBotonPre);
        });
        document.getElementById('formPre').addEventListener('submit', async function (e) {
            e.preventDefault();
            if (preSubiendo) return;
            preSubiendo = true;
            document.getElementById('preSubmitBtn').disabled = true;
            document.getElementById('preSubmitBtn').textContent = 'Enviando...';
            document.getElementById('preProgress').classList.remove('hidden');
            document.getElementById('preMessage').classList.add('hidden');

            const payload = {
                name: (document.getElementById('preNombre') ? document.getElementById('preNombre').value : '').trim() || 'SIN NOMBRE',
                email: document.getElementById('preEmail').value.trim().toLowerCase(),
                dni: document.getElementById('preDni').value.trim(),
                telefono: document.getElementById('preTelefono').value.trim(),
                description: 'Previa evaluación crediticia - 3 boletas de pago y DNI',
                type: 'pre-evaluacion'
            };

            try {
                const { entries, submissionId } = await subirArchivos(preFiles, 'pre-evaluacion');
                payload.files = entries;
                payload.submissionId = submissionId;
                const res = await enviarSolicitud(payload, CSRF_TOKEN);
                if (!res.ok) {
                    const err = await res.json();
                    const detail = (err.details && err.details.length) ? (': ' + err.details.join(' | ')) : '';
                    throw new Error((err.error || 'Error al enviar formulario') + detail);
                }
                const preGuardado = await res.json().catch(() => ({}));
                const preAcuse = preGuardado.acuse || {};
                const preTxt = preAcuse.nroCargo
                    ? 'Expediente registrado (Nº ' + preAcuse.nroCargo + '). Redirigiendo al formulario de crédito...'
                    : 'Documentos recibidos. Redirigiendo al formulario de crédito...';
                mostrarMsg('preMessage', 'success', preTxt);
                setTimeout(function () { window.location.href = 'login.php?tab=credito'; }, 2000);
            } catch (err) {
                mostrarMsg('preMessage', 'error', err.message || 'Error al enviar los documentos');
                preSubiendo = false;
                document.getElementById('preSubmitBtn').textContent = 'Enviar y continuar';
                actualizarBotonPre();
            }
        });
    }

    // ---------- Tracking de solicitudes ----------
    const ESTADOS = {
        'pendiente':    { label: 'Pendiente',   css: 'bg-yellow-100 text-yellow-800' },
        'en_revision':  { label: 'En revisión', css: 'bg-blue-100 text-blue-800' },
        'aprobado':     { label: 'Aprobado',    css: 'bg-green-100 text-green-800' },
        'denegado':     { label: 'Denegado',    css: 'bg-red-100 text-red-800' }
    };
    const TIPOS = {
        'credito': 'Solicitud de crédito',
        'pre-evaluacion': 'Pre-evaluación',
        'afiliacion': 'Afiliación'
    };

    window.cargarMisSolicitudes = async function () {
        const cont = document.getElementById('misSolicitudes');
const empty = document.getElementById('misSolicitudesVacio');
            const cargando = document.getElementById('misSolicitudesCargando');
            if (!cont || !empty) return;
            try {
                if (cargando) cargando.classList.add('hidden');
                const res = await fetch('mis-solicitudes.php?dni=' + encodeURIComponent((document.getElementById('crDni') || { value: '' }).value), {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) throw new Error('Error al consultar solicitudes');
                const data = await res.json();
                const list = data.solicitudes || [];
                if (list.length === 0) {
                    cont.classList.add('hidden');
                    empty.classList.remove('hidden');
                    return;
                }
                window.__misFiles = list.map(function (s) { return s.files || []; });
                empty.classList.add('hidden');
                cont.classList.remove('hidden');
                cont.innerHTML = list.map(function (s, i) {
                    const est = ESTADOS[s.status] || { label: s.status, css: 'bg-gray-100 text-gray-700' };
                    const tipo = TIPOS[s.type] || s.type;
                    const fecha = new Date(s.fecha_solicitud);
                    const fechaStr = isNaN(fecha) ? (s.fecha_solicitud || '') : fecha.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    const acuseUrl = 'acuse.php?id=' + encodeURIComponent(s.submission_id) + '&t=' + encodeURIComponent(s.acuse_hash || '');
                    const segUrl = 'seguimiento.php';
                    return '<div class="rounded-lg border border-gray-200 bg-white px-4 py-3">'
                        + '<div class="flex items-center justify-between gap-3">'
                        + '<div class="min-w-0">'
                        + '<p class="text-sm font-medium text-gray-900">' + tipo + '</p>'
                        + '<p class="text-xs text-gray-500 mt-0.5">' + (s.nro_cargo || '') + ' &middot; ' + (s.nro_expediente || '') + '</p>'
                        + '<p class="text-xs text-gray-500 mt-0.5">' + (s.description || '') + ' &middot; ' + fechaStr + '</p>'
                        + '</div>'
                        + '<span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + est.css + '">' + est.label + '</span>'
                        + '</div>'
                        + '<div class="mt-2 flex flex-wrap gap-2 text-xs">'
                        + '<a href="' + acuseUrl + '" class="rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50">Acuse de recibo</a>'
                        + '<a href="' + segUrl + '" class="rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50">Seguimiento</a>'
                        + '<button type="button" onclick="window.__verArchivos(' + i + ')" class="rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50">Archivos (' + (s.files || []).length + ')</button>'
                        + '</div>'
                        + '</div>';
                }).join('');
            } catch (e) {
                console.error('Error cargando solicitudes:', e);
            }
    };

    // ---------- Previsualización de archivos enviados ----------
    window.__verArchivos = function (idx) {
        const files = (window.__misFiles && window.__misFiles[idx]) || [];
        window.__prevList = files;
        window.__prevIdx = idx;
        const body = document.getElementById('prevModalBody');
        if (!body) return;
        document.getElementById('prevModalTitulo').textContent = 'Archivos enviados';
        if (files.length === 0) {
            body.innerHTML = '<p class="py-4 text-center text-sm text-gray-500">Sin archivos adjuntos.</p>';
        } else {
            body.innerHTML = files.map(function (f, k) {
                const esImg = (f.fileType || '').startsWith('image/');
                const url = f.url || '';
                const tile = esImg
                    ? '<img src="' + url + '" alt="" class="h-12 w-12 shrink-0 rounded-lg border object-cover">'
                    : '<div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg border border-red-200 bg-red-50 text-[9px] font-bold text-red-600"><span>PDF</span></div>';
                return '<div class="flex items-center gap-3 rounded-lg border p-3">'
                    + tile
                    + '<div class="min-w-0 flex-1"><p class="truncate text-sm font-medium text-gray-900">' + (f.originalName || '') + '</p></div>'
                    + (url ? '<button type="button" data-i="' + k + '" onclick="window.__prevAbrir(this.dataset.i)" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Previsualizar</button>' : '')
                    + (url ? '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Descargar</a>' : '')
                    + '</div>';
            }).join('');
        }
        const m = document.getElementById('prevModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    };

    window.__prevAbrir = function (i) {
        const f = window.__prevList && window.__prevList[i];
        if (!f || !f.url) return;
        document.getElementById('prevModalTitulo').textContent = f.originalName || 'Vista previa';
        const body = document.getElementById('prevModalBody');
        const esImg = (f.fileType || '').startsWith('image/');
        body.innerHTML = '<div class="mb-3 flex justify-end">'
            + '<button type="button" onclick="window.__prevVolver()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">&larr; Volver a la lista</button></div>'
            + (esImg
                ? '<div class="flex justify-center"><img src="' + f.url + '" alt="" class="max-h-[60vh] max-w-full object-contain"></div>'
                : '<iframe src="' + f.url + '" class="h-[60vh] w-full rounded-lg border"></iframe>');
        body.scrollTop = 0;
    };
    window.__prevVolver = function () {
        if (window.__prevList !== undefined) window.__verArchivos(window.__prevIdx);
    };
    window.__cerrarPrev = function (e) {
        if (e && e.target !== e.currentTarget) return;
        const m = document.getElementById('prevModal');
        if (!m) return;
        m.classList.add('hidden');
        m.classList.remove('flex');
    };

    document.addEventListener('DOMContentLoaded', function () {
        initCredito();
        initPreEvaluacion();
        if (document.getElementById('misSolicitudes')) cargarMisSolicitudes();
    });
})();
