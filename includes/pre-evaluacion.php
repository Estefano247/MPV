<div class="space-y-4">
    <div class="flex items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>Adjunte los siguientes documentos para la evaluación previa de su crédito.</span>
    </div>

    <form id="formPre" class="space-y-4">
        <input type="hidden" id="preNombre" value="<?= e(trim($preNombre ?? idx($socio ?? [], 2))) ?>">
        <div class="space-y-3">
            <?php
            $preDocs = [
                ['key' => 'boleta1', 'label' => '1ra Boleta de Pago'],
                ['key' => 'boleta2', 'label' => '2da Boleta de Pago'],
                ['key' => 'boleta3', 'label' => '3ra Boleta de Pago'],
                ['key' => 'dni', 'label' => 'DNI (documento de identidad)'],
            ];
            foreach ($preDocs as $doc): ?>
            <div>
                <label class="text-sm font-medium text-gray-700"><?= e($doc['label']) ?></label>
                <div id="doc-<?= e($doc['key']) ?>" data-dockey="<?= e($doc['key']) ?>" class="doc-placeholder flex items-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 mt-1 cursor-pointer hover:border-blue-500 hover:text-blue-600 transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    <span>Seleccionar <?= e($doc['label']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
            <div>
                <label for="preDni" class="block text-sm font-medium text-gray-700 mb-1">Número de DNI</label>
                <input id="preDni" type="text" readonly maxlength="8" inputmode="numeric"
                       value="<?= e($dni ?? '') ?>"
                       class="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-600 focus:outline-none cursor-not-allowed">
            </div>
            <div>
                <label for="preTelefono" class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                <input id="preTelefono" type="tel" placeholder="987654321" maxlength="9" inputmode="numeric"
                       oninput="this.value=this.value.replace(/\D/g,'').slice(0,9);actualizarBotonPre()"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label for="preEmail" class="block text-sm font-medium text-gray-700 mb-1">Correo electrónico</label>
                <input id="preEmail" type="email" placeholder="correo@ejemplo.com"
                       oninput="actualizarBotonPre()"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <div id="preProgress" class="hidden flex items-center gap-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-800">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            Subiendo archivos y guardando solicitud...
        </div>
        <div id="preMessage" class="hidden flex items-center gap-2 rounded-lg p-3 text-sm"></div>
        <button type="submit" id="preSubmitBtn" disabled class="w-full bg-blue-600 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
            Enviar y continuar
        </button>
    </form>
</div>
