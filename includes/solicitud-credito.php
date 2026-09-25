<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 items-start">
    <div class="lg:col-span-2">
        <form id="formCredito" class="space-y-3 sm:space-y-4">
            <div>
                <label for="crName" class="block text-sm font-medium text-gray-700 mb-1">Nombre completo</label>
                <input id="crName" type="text" readonly
                       value="<?= e(trim(idx($socio, 2))) ?>"
                       class="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-600 focus:outline-none cursor-not-allowed">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div>
                    <label for="crEmail" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input id="crEmail" type="email" required autocomplete="email"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label for="crDni" class="block text-sm font-medium text-gray-700 mb-1">DNI</label>
                    <input id="crDni" type="text" required readonly maxlength="8" inputmode="numeric"
                           value="<?= e($dni ?? '') ?>"
                           class="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-600 focus:outline-none cursor-not-allowed">
                </div>
            </div>
            <div>
                <label for="crTelefono" class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                <input id="crTelefono" type="tel" required placeholder="987654321" maxlength="9" inputmode="numeric"
                       oninput="this.value=this.value.replace(/\D/g,'').slice(0,9);actualizarBotonCredito()"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Subir documentos llenados (PDF)</h3>
                <div class="flex flex-wrap gap-2 mb-3">
                    <button type="button" id="crSelectBtn" class="flex items-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 hover:border-blue-500 hover:text-blue-600 transition-colors bg-transparent cursor-pointer">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        Seleccionar PDF o imagen para subir
                    </button>
                </div>
                <ul id="crFileList" class="space-y-2"></ul>
            </div>

            <div id="crProgress" class="hidden flex items-center gap-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-800">
                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Subiendo archivos y guardando formulario...
            </div>
            <div id="crMessage" class="hidden flex items-center gap-2 rounded-lg p-3 text-sm"></div>
            <button type="submit" id="crSubmitBtn" disabled class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                Enviar solicitud
            </button>
        </form>
    </div>

    <div class="space-y-4">
        <div class="bg-amber-50 border border-amber-200 rounded-xl shadow-sm">
            <div class="p-4 sm:p-6 pb-2 sm:pb-3">
                <h2 class="text-sm sm:text-base font-semibold">Requisitos para obtener un préstamo</h2>
            </div>
            <div class="p-4 sm:p-6 pt-0 sm:pt-0">
                <ul class="space-y-1.5">
                    <li class="flex items-start gap-2 text-xs sm:text-sm text-gray-700"><span class="text-amber-600 mt-0.5 shrink-0">•</span>Fotocopia de última boleta de Pago.</li>
                    <li class="flex items-start gap-2 text-xs sm:text-sm text-gray-700"><span class="text-amber-600 mt-0.5 shrink-0">•</span>Fotocopia de boleta de Incentivos Laborales.</li>
                    <li class="flex items-start gap-2 text-xs sm:text-sm text-gray-700"><span class="text-amber-600 mt-0.5 shrink-0">•</span>Fotocopia de documento de identidad (D.N.I.)</li>
                    <li class="flex items-start gap-2 text-xs sm:text-sm text-gray-700"><span class="text-amber-600 mt-0.5 shrink-0">•</span>Fotocopia de Teleahorro o "voucher" de su cuenta del Banco de la Nación.</li>
                    <li class="flex items-start gap-2 text-xs sm:text-sm text-gray-700"><span class="text-amber-600 mt-0.5 shrink-0">•</span>Solicitud de Préstamo entregada por la AMSP ó imprimir la Solicitud publicada aquí.</li>
                    <li class="flex items-start gap-2 text-xs sm:text-sm text-gray-700"><span class="text-amber-600 mt-0.5 shrink-0">•</span>Autorización de descuento.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
