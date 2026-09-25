<?php

declare(strict_types=1);

$config = require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/View.php';

session_name('AMSP_CLIENTE');
session_start();

View::head('Simulador de Préstamo | AMSP', $config, 'calculadora');
?>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">Simulador de Préstamo &ndash; Cronograma Mensual</h1>
            <p class="text-sm text-gray-500 mb-4">Asociación Mutualista Sanitaria del Perú</p>

            <div class="rounded-lg bg-amber-50 border border-amber-300 p-4 mb-6">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    <div>
                        <p class="text-sm font-semibold text-amber-800">Aviso importante</p>
                        <p class="text-sm text-amber-700 mt-1">Los valores mostrados son <strong>estimados y solo tienen carácter de referencia</strong>. El monto final, tasa y condiciones serán determinados después de superar la <strong>pre-evaluación crediticia</strong>. Esta simulación no constituye oferta ni compromiso de préstamo.</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 rounded-lg bg-blue-50 border border-blue-200 p-3 text-sm text-blue-800 mb-6">
                No puedes solicitar un crédito si no eres asociado. Primero debes estar afiliado a la AMSP.
            </div>

            <div class="flex flex-col lg:flex-row gap-6">
                <div class="flex-1 min-w-0">
                    <div class="bg-white rounded-2xl shadow-xl border p-6 sm:p-8 space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Tipo de asociado</label>
                            <select id="tipoAsociado" onchange="calcular()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Selecciona tipo de asociado</option>
                                <option value="nuevo">Nuevo (1.5% mensual)</option>
                                <option value="normal">Normal (2.25% mensual)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Monto del préstamo (S/.)</label>
                            <select id="monto" onchange="calcular()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Selecciona el monto</option>
                                <option value="500">500</option>
                                <option value="1000">1000</option>
                                <option value="1500">1500</option>
                                <option value="2000">2000</option>
                                <option value="2500">2500</option>
                                <option value="3000">3000</option>
                                <option value="3500">3500</option>
                                <option value="4000">4000</option>
                                <option value="4500">4500</option>
                                <option value="5000">5000</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Plazo (meses)</label>
                            <select id="plazo" onchange="calcular()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Selecciona el plazo</option>
                            </select>
                        </div>
                    </div>

                    <div id="resultados" class="hidden bg-blue-50 rounded-xl border border-blue-100 p-4 sm:p-6 mt-6">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <div><p class="text-xs text-gray-500">Monto</p><p id="rMonto" class="text-lg font-bold text-gray-900"></p></div>
                            <div><p class="text-xs text-gray-500">Plazo</p><p id="rPlazo" class="text-lg font-bold text-gray-900"></p></div>
                            <div><p class="text-xs text-gray-500">Tasa mensual</p><p id="rTasa" class="text-lg font-bold text-gray-900"></p></div>
                            <div><p class="text-xs text-gray-500">Cuota mensual</p><p id="rCuota" class="text-lg font-bold text-blue-700"></p></div>
                            <div><p class="text-xs text-gray-500">Total intereses</p><p id="rInteres" class="text-lg font-bold text-gray-900"></p></div>
                            <div><p class="text-xs text-gray-500">Total gastos adm.</p><p id="rGastoAdm" class="text-lg font-bold text-gray-900"></p></div>
                            <div><p class="text-xs text-gray-500">Total seguro</p><p id="rSeguro" class="text-lg font-bold text-gray-900"></p></div>
                            <div><p class="text-xs text-gray-500">Total a pagar</p><p id="rTotal" class="text-lg font-bold text-green-700"></p></div>
                        </div>
                        <p class="text-xs text-amber-600 mt-4 italic">* Valores referenciales. Sujetos a aprobación de la pre-evaluación crediticia.</p>
                    </div>

                    <div id="tablaContainer" class="hidden bg-white rounded-2xl shadow-lg border p-4 sm:p-6 mt-6 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-blue-700 text-white">
                                    <th class="p-2 text-center">N&deg;</th>
                                    <th class="p-2 text-center">Saldo inicial</th>
                                    <th class="p-2 text-center">Inter&eacute;s</th>
                                    <th class="p-2 text-center">Amortizaci&oacute;n</th>
                                    <th class="p-2 text-center">G. Adm.</th>
                                    <th class="p-2 text-center">Seguro</th>
                                    <th class="p-2 text-center">Cuota total</th>
                                    <th class="p-2 text-center">Saldo final</th>
                                </tr>
                            </thead>
                            <tbody id="tablaBody"></tbody>
                        </table>
                    </div>

                    <div class="mt-6 mb-6 flex flex-wrap gap-3 justify-center">
                        <a href="login.php" class="inline-flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-lg text-base font-semibold hover:bg-green-700 transition-colors">Iniciar sesión / Portal del Asociado</a>
                    </div>
                </div>

                <div class="w-full lg:w-72 shrink-0">
                    <div class="bg-white rounded-2xl shadow-lg border p-5 lg:sticky lg:top-8">
                        <h3 class="font-semibold text-blue-700 border-b border-blue-100 pb-2 mb-3">Parámetros fijos</h3>
                        <ul class="space-y-3 text-sm text-gray-700">
                            <li><strong>Tasa de interés</strong><br>Según tipo de asociado.</li>
                            <li><strong>Gasto administrativo</strong><br>S/. 10.00 mensual.<br>GAD.OPE 6.875 / F.Solidario 1.875 / F.Servicio 1.25</li>
                            <li><strong>Seguro de desgravamen</strong><br>1% del monto inicial, prorrateado en cada cuota.</li>
                            <li><strong>Restricción de plazo</strong><br>500 &rarr; 6 meses<br>1000 &rarr; 12 meses<br>&ge;1500 &rarr; 24 meses</li>
                        </ul>
                        <p class="text-xs text-amber-600 mt-4 pt-3 border-t border-gray-100 italic">Estos parámetros son referenciales. Las condiciones reales se confirman tras la pre-evaluación crediticia.</p>
                    </div>
                </div>
            </div>
        </div>
<?php View::footer($config); ?>

    <script>
    const CFG = {
        gastoAdm: 10,
        seguroPct: 0.01,
        tasas: { nuevo: 0.015, normal: 0.0225 },
        plazos: m => m === 500 ? [6] : m === 1000 ? [6, 12] : [6, 12, 18, 24]
    };

    const $ = id => document.getElementById(id);
    const fmt = v => 'S/ ' + v.toFixed(2);

    function pmt(r, n, pv) {
        if (r === 0) return pv / n;
        const f = Math.pow(1 + r, n);
        return pv * r * f / (f - 1);
    }

    function calcular() {
        const montoVal = $('monto').value;
        const tipo = $('tipoAsociado').value;
        const rBox = $('resultados'), tBox = $('tablaContainer');

        if (!montoVal || !tipo) { rBox.classList.add('hidden'); tBox.classList.add('hidden'); return; }

        const monto = +montoVal, tasa = CFG.tasas[tipo];
        const plazos = CFG.plazos(monto);
        const sel = $('plazo');
        const cur = +sel.value;
        sel.innerHTML = '<option value="">Selecciona el plazo</option>'
            + plazos.map(p => `<option value="${p}"${p === cur && plazos.includes(cur) ? ' selected' : ''}>${p} meses</option>`).join('');
        const plazo = +sel.value;
        if (!plazo) { rBox.classList.add('hidden'); tBox.classList.add('hidden'); return; }

        const seguroMensual = (monto * CFG.seguroPct) / plazo;
        const cuota = pmt(tasa, plazo, monto) + CFG.gastoAdm + seguroMensual;

        let saldo = monto, tI = 0, tA = 0, tG = 0, tS = 0;
        const rows = [];
        for (let i = 1; i <= plazo; i++) {
            const interes = saldo * tasa;
            const amort = i === plazo ? saldo : cuota - interes - CFG.gastoAdm - seguroMensual;
            saldo -= amort;
            tI += interes; tA += amort; tG += CFG.gastoAdm; tS += seguroMensual;
            rows.push({ i, saldo, tasa, amort, g: CFG.gastoAdm, s: seguroMensual, cuota, sf: saldo });
        }

        rBox.classList.remove('hidden'); tBox.classList.remove('hidden');
        $('rMonto').textContent = fmt(monto);
        $('rPlazo').textContent = plazo + ' meses';
        $('rTasa').textContent = (tasa * 100).toFixed(2) + '%';
        $('rCuota').textContent = fmt(cuota);
        $('rInteres').textContent = fmt(tI);
        $('rGastoAdm').textContent = fmt(tG);
        $('rSeguro').textContent = fmt(tS);
        $('rTotal').textContent = fmt(tA + tI + tG + tS);

        $('tablaBody').innerHTML = rows.map(r =>
            `<tr class="border-b border-gray-100 even:bg-gray-50">`
            + `<td class="p-2 text-center">${r.i}</td>`
            + `<td class="p-2 text-center">${fmt(r.saldo + r.amort)}</td>`
            + `<td class="p-2 text-center">${fmt(r.tasa * (r.saldo + r.amort))}</td>`
            + `<td class="p-2 text-center">${fmt(r.amort)}</td>`
            + `<td class="p-2 text-center">${fmt(r.g)}</td>`
            + `<td class="p-2 text-center">${fmt(r.s)}</td>`
            + `<td class="p-2 text-center font-bold">${fmt(r.cuota)}</td>`
            + `<td class="p-2 text-center">${fmt(r.sf)}</td></tr>`
        ).join('')
        + `<tr class="font-bold bg-blue-100"><td class="p-2 text-center">TOTAL</td><td></td>`
        + `<td class="p-2 text-center">${fmt(tI)}</td>`
        + `<td class="p-2 text-center">${fmt(tA)}</td>`
        + `<td class="p-2 text-center">${fmt(tG)}</td>`
        + `<td class="p-2 text-center">${fmt(tS)}</td>`
        + `<td class="p-2 text-center">${fmt(tA + tI + tG + tS)}</td><td></td></tr>`;
    }
    </script>
<?php View::fin(); ?>
