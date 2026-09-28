<?php

declare(strict_types=1);

/**
 * SubsanacionService: el ciclo completo de la observación y la subsanación.
 *
 * Todo con dobles: sin PostgreSQL, sin S3 y sin panel. Las pruebas afirman sobre
 * el efecto observable (estado del expediente, filas, trazabilidad) y no sobre la
 * implementación, para que el flujo se pueda cambiar por dentro sin romperlas.
 */

require_once __DIR__ . '/T.php';
require_once __DIR__ . '/Doubles.php';
require_once __DIR__ . '/../includes/SubsanacionService.php';
require_once __DIR__ . '/../includes/ValidationException.php';
require_once __DIR__ . '/../includes/SubmissionRepository.php';
require_once __DIR__ . '/../includes/CorrelativoRepository.php';
require_once __DIR__ . '/../includes/ObservacionRepository.php';
require_once __DIR__ . '/../includes/AcuseService.php';
require_once __DIR__ . '/../includes/S3Service.php';
require_once __DIR__ . '/../includes/Uuid.php';

const EXP_ID = '11111111-1111-4111-8111-111111111111';
const EXP_DNI = '70123456';
const EXP_CARGO = 'C-2026-000001';
const EXP_SALA = 'E-2026-000045';

/**
 * @param FakeSubmissions $sub Repositorio de expedientes a sembrar.
 * @param array<string,mixed> $over
 */
function sembrarExpediente(FakeSubmissions $sub, array $over = []): void
{
    $sub->sembrar($over + [
        'submission_id' => EXP_ID,
        'dni' => EXP_DNI,
        'name' => 'María Quispe',
        'email' => 'maria.quispe@correo.com',
        'telefono' => '987654321',
        'type' => 'mpv',
        'nro_cargo' => EXP_CARGO,
        'nro_expediente' => EXP_SALA,
        'acuse_hash' => 'hash-original',
        'area_actual_id' => 7,
        'area_actual' => 'Mesa de Partes',
        'status' => 'en_revision',
    ]);
}

/** @return array{key:string,originalName:string,fileType:string} */
function adjSubs(string $key = 'subsanacion/2026/x.pdf'): array
{
    return ['key' => $key, 'originalName' => 'x.pdf', 'fileType' => 'application/pdf'];
}

/**
 * Repo, observaciones, storage y servicio armados de forma coherente: por
 * defecto todo adjunto que se le pase existe en el storage con 1 KB.
 *
 * @param array<int,array<string,string>> $files
 * @param array<string,int>|null $tamanos
 * @return array{0:FakeSubmissions,1:FakeObservaciones,2:FakeStorage,3:SubsanacionService,4:FakeCorrelativos}
 */
function escenarioSubs(array $files, ?array $tamanos = null, ?FakeCorrelativos $corrOUT = null): array
{
    $sub = new FakeSubmissions();
    $obs = new FakeObservaciones($sub);
    $storage = new FakeStorage($tamanos ?? array_fill_keys(array_column($files, 'key'), 1024));
    $corr = $corrOUT ?? new FakeCorrelativos();
    $corr->observa = $sub;

    return [$sub, $obs, $storage, new SubsanacionService($sub, $obs, $corr, $storage), $corr];
}

/** Errores que lanza la llamada, o [] si la llamada tuvo éxito. */
function erroresDeSubs(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errores();
    }
    return [];
}

// =====================================================================
// 1. El área abre el requerimiento
// =====================================================================

T::grupo('registrarObservacion: abre el requerimiento');

$files = [];
[$sub, $obs, $storage, $svc, $corr] = escenarioSubs($files);
sembrarExpediente($sub);

$res = $svc->registrarObservacion(EXP_ID, 'Falta la copia simple del contrato.', null, 'jperez');

T::igual($sub->estadoDe(EXP_ID), 'observado', 'deja el expediente en observado');
T::igual($res['plazo_dias'], SubsanacionService::plazoPorDefecto(), 'usa el plazo por defecto de la directiva');
T::igual($obs->pendiente(EXP_ID)['detalle'], 'Falta la copia simple del contrato.', 'guarda el detalle');
T::igual($obs->pendiente(EXP_ID)['usuario'], 'jperez', 'guarda quién observó');
T::igual(
    date('Y-m-d', strtotime((string) $res['fecha_limite'])),
    date('Y-m-d', strtotime('+' . $res['plazo_dias'] . ' days')),
    'calcula la fecha límite con el plazo'
);
T::igual(substr((string) $res['fecha_limite'], -8), '17:00:00', 'la fecha límite es a las 17:00 del último día');
T::igual(count($sub->movimientos), 1, 'registra un movimiento por la observación');
T::igual($sub->movimientos[0]['tipo'], 'observacion', 'el movimiento es de tipo observación');
T::igual($sub->movimientos[0]['estado'], 'observado', 'el movimiento deja el estado observado');
T::igual($sub->movimientos[0]['usuario'], 'jperez', 'el movimiento atribuye al operador');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el DNI escaneado.', 30, 'jperez');
T::igual($obs->pendiente(EXP_ID)['plazo_dias'], 30, 'respeta el plazo que fija el área');
T::igual(
    date('Y-m-d', strtotime((string) $obs->pendiente(EXP_ID)['fecha_limite'])),
    date('Y-m-d', strtotime('+30 days')),
    'un plazo de 30 días vence en 30 días'
);

T::grupo('registrarObservacion: rechazos');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
T::cierto(erroresDeSubs(fn () => $svc->registrarObservacion(EXP_ID, '   ', 10, 'jperez')) !== [], 'exige detalle');
T::igual($sub->estadoDe(EXP_ID), 'en_revision', 'no cambia el estado si la observación es inválida');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarObservacion(EXP_ID, str_repeat('a', 1001), 10, 'jperez')) !== [],
    'rechaza un detalle de más de 1000 caracteres'
);

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarObservacion(EXP_ID, 'Falta algo.', 2, 'jperez')) !== [],
    'rechaza un plazo por debajo del mínimo legal'
);
T::igual($obs->pendiente(EXP_ID), null, 'un plazo inválido no abre la observación');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarObservacion(EXP_ID, 'Falta algo.', 120, 'jperez')) !== [],
    'rechaza un plazo por encima del máximo'
);

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarObservacion('no-existe', 'Falta algo.', 10, 'jperez')) !== [],
    'rechaza un expediente inexistente'
);

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub, ['status' => 'aprobado']);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarObservacion(EXP_ID, 'Falta algo.', 10, 'jperez')) !== [],
    'no observa un expediente resuelto'
);

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
T::cierto(
    erroresDeSubs(fn () => $svc->registrarObservacion(EXP_ID, 'Falta el recibo.', 10, 'jperez')) !== [],
    'no abre una segunda observación sobre el mismo expediente'
);
T::igual(count($obs->observaciones), 1, 'la unicidad deja una sola observación');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
$obs->registrar(EXP_ID, 'Observación vieja ya atendida.', 10, 'jperez');
$obs->atender('obs-1');
$svc->registrarObservacion(EXP_ID, 'Falta otro documento.', 10, 'jperez');
T::igual(count($obs->observaciones), 2, 'admite observar otra vez si la anterior ya se atendió');

// =====================================================================
// 2. El área se desistió del requerimiento
// =====================================================================

T::grupo('desestimar: el área retira el requerimiento');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$svc->desestimar(EXP_ID, 'jperez');

T::igual($obs->pendiente(EXP_ID), null, 'cierra la observación pendiente');
T::igual($obs->observaciones['obs-1']['estado'], 'atendida', 'queda como atendida en la historia');
T::igual($sub->estadoDe(EXP_ID), 'observado', 'no cambia el estado: lo hace el endpoint por la vía normal');
T::igual(count($sub->movimientos), 2, 'deja rastro del desistimiento');
T::igual($sub->movimientos[1]['tipo'], 'observacion', 'el desistimiento se traza como observación');

[$sub, $obs, $storage, $svc] = escenarioSubs([]);
sembrarExpediente($sub);
$svc->desestimar(EXP_ID, 'jperez');
T::igual(count($sub->movimientos), 0, 'no inventa movimientos si no había observación');

// =====================================================================
// 3. El presentante consulta qué le falta
// =====================================================================

T::grupo('consultar: qué puede ver el presentante');

$uno = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($uno);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta la copia simple del contrato.', 10, 'jperez');

$info = $svc->consultar(EXP_CARGO, EXP_DNI);
T::igual($info['submission_id'], EXP_ID, 'encuentra el expediente por Nº de cargo y DNI');
T::igual($info['nombre'], 'María Quispe', 'devuelve los datos para el formulario');
T::igual($info['observacion']['detalle'], 'Falta la copia simple del contrato.', 'devuelve qué se le pide');
T::igual($info['observacion']['vencida'], false, 'el plazo vigente no está vencido');
T::igual($info['cerrado'], false, 'el expediente sigue abierto');
T::igual($info['en_revision'], false, 'puede subsanar porque hay observación pendiente');
T::igual($svc->consultar(EXP_SALA, EXP_DNI)['submission_id'], EXP_ID, 'también acepta el Nº de expediente');
T::igual($svc->consultar(strtolower(EXP_CARGO), EXP_DNI)['submission_id'], EXP_ID, 'el número se normaliza a mayúsculas');
T::igual($svc->consultar(EXP_CARGO, '99999999'), null, 'no responde con un DNI que no corresponde');
T::igual($svc->consultar('Z-2026-999999', EXP_DNI), null, 'no responde con un cargo inexistente');
T::igual($svc->consultar('basura', EXP_DNI), null, 'ignora formatos que no son de cargo ni expediente');
T::igual($svc->consultar(EXP_CARGO, '123'), null, 'ignora un DNI que no tiene 8 dígitos');

$svc->registrarSubsanacion(
    ['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto la copia simple.'],
    $uno
);
$info2 = $svc->consultar(EXP_CARGO, EXP_DNI);
T::igual($info2['observacion'], null, 'ya no ofrece el requerimiento si entregó la subsanación');
T::igual($info2['en_revision'], true, 'le informa que su subsanación está en revisión');
T::igual(count($info2['subsanaciones']), 1, 'le muestra su subsanación');
T::igual($info2['subsanaciones'][0]['nro_cargo'], 'S-2026-000001', 'la subsanación tiene correlativo propio');

// =====================================================================
// 4. El presentante subsana
// =====================================================================

T::grupo('registrarSubsanacion: camino feliz');

$ok = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc, $corr] = escenarioSubs($ok);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');

$alta = $svc->registrarSubsanacion(
    [
        'dni' => EXP_DNI,
        'numero' => EXP_CARGO,
        'descripcion' => 'Adjunto la copia simple del contrato.',
        'email' => 'nuevo.correo@correo.com',
    ],
    $ok
);

T::igual($alta['nro_cargo'], 'S-2026-000001', 'emite su propio correlativo de subsanación');
T::igual($alta['reenvio'], false, 'no es un reenvío');
T::igual($alta['total_archivos'], 1, 'cuenta los adjuntos');
T::igual(
    $alta['acuse_hash'],
    AcuseService::hash($alta['id'], 'S-2026-000001'),
    'el acuse se firma con el id de la subsanación y su cargo'
);
T::igual($obs->pendiente(EXP_ID), null, 'cierra la observación atendida');
T::igual($sub->estadoDe(EXP_ID), 'observado', 'el expediente sigue observado hasta que el área acepte');
T::igual(count($sub->subsanacionesRegistradas), 1, 'adjunta los documentos a la subsanación');
T::igual($sub->subsanacionesRegistradas[0]['subsanacion_id'], $alta['id'], 'los adjuntos quedan en la subsanación');
T::igual($sub->contarArchivosDeSubsanacion($alta['id']), 1, 'los adjuntos son contables por subsanación');
T::igual($obs->subsanaciones(EXP_ID)[0]['observacion_id'], 'obs-1', 'la subsanación sabe a qué observación responde');
T::igual($obs->subsanaciones(EXP_ID)[0]['observacion_detalle'], 'Falta el contrato.', 'el panel puede mostrar el par detalle/respuesta');
T::igual($obs->subsanaciones(EXP_ID)[0]['nombre'], 'María Quispe', 'toma el nombre del expediente si el presentante no manda otro');
T::igual($obs->subsanaciones(EXP_ID)[0]['email'], 'nuevo.correo@correo.com', 'respeta el email que manda el presentante');
T::igual($obs->subsanaciones(EXP_ID)[0]['estado'], 'registrada', 'nace como registrada, a la espera del área');
T::igual($corr->vecesPedidos(CorrelativoRepository::TIPO_SUBSANACION), 1, 'quema un correlativo de subsanación');
T::igual($corr->vecesPedidos(CorrelativoRepository::TIPO_CARGO), 0, 'no toca el correlativo de los trámites');
T::igual($corr->dentroDeTransaccion[0], true, 'el correlativo se pide dentro de la transacción');
T::igual(count($sub->movimientos), 2, 'deja un movimiento de subsanación');
T::igual($sub->movimientos[1]['tipo'], 'subsanacion', 'el movimiento es de tipo subsanación');
T::igual($sub->movimientos[1]['usuario'], 'web', 'se atribuye al canal web, no a un operador');

T::grupo('registrarSubsanacion: idempotencia');

$idem = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc, $corr] = escenarioSubs($idem);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');

$uuid = Uuid::v4();
$entrada = ['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto la copia simple.'];
$primera = $svc->registrarSubsanacion($entrada, $idem, $uuid);
$reintento = $svc->registrarSubsanacion($entrada, $idem, $uuid);

T::igual($reintento['id'], $primera['id'], 'el reintento devuelve el mismo id');
T::igual($reintento['nro_cargo'], $primera['nro_cargo'], 'el reintento devuelve el mismo Nº de cargo');
T::igual($reintento['acuse_hash'], $primera['acuse_hash'], 'el reintento devuelve el mismo acuse');
T::igual($reintento['reenvio'], true, 'marca la respuesta como reenvío');
T::igual($obs->totalSubsanaciones(), 1, 'no se registra una segunda subsanación');
T::igual($corr->vecesPedidos(CorrelativoRepository::TIPO_SUBSANACION), 1, 'no se quema un segundo correlativo');
T::igual(count($sub->subsanacionesRegistradas), 1, 'no se duplican los adjuntos');

// El id lo genera el cliente: si llega el de otra respuesta, no se puede
// devolver su acuse.
$otroId = '22222222-2222-4222-8222-222222222222';
[$sub, $obs, $storage, $svc] = escenarioSubs($idem);
sembrarExpediente($sub);
sembrarExpediente($sub, [
    'submission_id' => $otroId,
    'dni' => '87654321',
    'nro_cargo' => 'C-2026-000002',
    'nro_expediente' => 'E-2026-000046',
]);
$svc->registrarObservacion($otroId, 'Falta el contrato.', 10, 'jperez');
$svc->registrarSubsanacion(
    ['dni' => '87654321', 'numero' => 'C-2026-000002', 'descripcion' => 'Otro expediente.'],
    $idem,
    $uuid
);
T::igual($obs->totalSubsanaciones(), 1, 'la primera respuesta queda registrada');

$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(
        ['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Con id ajena.'],
        $idem,
        $uuid
    )) !== [],
    'no devuelve el acuse de otra respuesta si se reusa su id'
);
T::igual($obs->totalSubsanaciones(), 1, 'el reintento con id ajeno no registra nada');

T::grupo('registrarSubsanacion: rechazos');

$val = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($val);
sembrarExpediente($sub);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], [])) !== [],
    'exige al menos un documento'
);
T::igual($obs->totalSubsanaciones(), 0, 'un envío sin archivos no deja rastro');

[$sub, $obs, $storage, $svc] = escenarioSubs($val);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => '123', 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $val)) !== [],
    'rechaza un DNI inválido'
);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => 'basura', 'descripcion' => 'Listo.'], $val)) !== [],
    'rechaza un número que no es de cargo ni de expediente'
);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => '  '], $val)) !== [],
    'exige describir la subsanación'
);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.', 'email' => 'no-es-mail'], $val)) !== [],
    'rechaza un email inválido'
);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $val, 'no-es-uuid')) !== [],
    'rechaza un subsanacionId que no es UUID'
);
T::igual($obs->totalSubsanaciones(), 0, 'un envío inválido no registra nada');

[$sub, $obs, $storage, $svc] = escenarioSubs($val);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => '87654321', 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $val)) !== [],
    'no subsana un expediente ajeno por conocer su número de cargo'
);
T::igual($obs->totalSubsanaciones(), 0, 'el intento ajeno no registra nada');

[$sub, $obs, $storage, $svc] = escenarioSubs($val);
sembrarExpediente($sub, ['status' => 'denegado']);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $val)) !== [],
    'no subsana un expediente ya denegado'
);

[$sub, $obs, $storage, $svc] = escenarioSubs($val);
sembrarExpediente($sub);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $val)) !== [],
    'no subsana un expediente sin observación'
);

[$sub, $obs, $storage, $svc] = escenarioSubs($val);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $val);
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Otra vez.'], $val)) !== [],
    'no admite dos subsanaciones seguidas de la misma observación'
);
T::igual($obs->totalSubsanaciones(), 1, 'la segunda sigue sin registrarse');

T::grupo('registrarSubsanacion: verificación de adjuntos');

$roto = [adjSubs('subsanacion/2026/falta.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($roto, []);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $roto)) !== [],
    'rechaza un archivo que no llegó al storage'
);
T::igual($obs->totalSubsanaciones(), 0, 'un adjunto inexistente no registra la subsanación');

$grande = [adjSubs('subsanacion/2026/enorme.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($grande, [$grande[0]['key'] => S3Service::MAX_FILE_SIZE + 1]);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
T::cierto(
    erroresDeSubs(fn () => $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Listo.'], $grande)) !== [],
    'rechaza un archivo que excede el máximo'
);
T::igualesEnOrden($storage->borrados, [$grande[0]['key']], 'borra del storage el archivo que excede el máximo');

// =====================================================================
// 5. Presentar fuera de plazo
// =====================================================================

T::grupo('registrarSubsanacion: plazo vencido');

$tarde = [adjSubs('subsanacion/2026/tarde.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($tarde);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 5, 'jperez');
$obs->reabrir('obs-1', date('Y-m-d 17:00:00', strtotime('-2 days')));

T::igual($svc->consultar(EXP_CARGO, EXP_DNI)['observacion']['vencida'], true, 'avisa al presentante que venció el plazo');
T::igual(
    $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto el contrato.'], $tarde)['nro_cargo'],
    'S-2026-000001',
    'igual admite la subsanación fuera de plazo: decide el área, no el sistema'
);

// =====================================================================
// 6. El área revisa lo subsanado
// =====================================================================

T::grupo('revisarSubsanacion: aceptar');

$aceptar = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($aceptar);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$alta = $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto el contrato.'], $aceptar);

$r = $svc->revisarSubsanacion($alta['id'], 'aceptada', 'jperez');
T::igual($r['status'], 'en_revision', 'aceptar devuelve el expediente a revisión');
T::igual($sub->estadoDe(EXP_ID), 'en_revision', 'el estado refleja la aceptación');
T::igual($obs->subsanaciones(EXP_ID)[0]['estado'], 'aceptada', 'califica la subsanación como aceptada');
T::igual($obs->subsanaciones(EXP_ID)[0]['revisada_por'], 'jperez', 'guarda quién aceptó');
T::igual($obs->pendiente(EXP_ID), null, 'no reabre el requerimiento aceptado');
T::igual(count($sub->movimientos), 3, 'deja rastro del cambio');
T::igual($sub->movimientos[2]['estado'], 'en_revision', 'el movimiento lleva el nuevo estado');
T::igual($sub->movimientos[2]['usuario'], 'jperez', 'el movimiento atribuye al operador');

T::grupo('revisarSubsanacion: rechazar');

$rechazar = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($rechazar);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$alta = $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto el contrato.'], $rechazar);

$r = $svc->revisarSubsanacion($alta['id'], 'rechazada', 'jperez');
T::igual($r['status'], 'observado', 'rechazar deja el expediente observado');
T::igual($sub->estadoDe(EXP_ID), 'observado', 'el estado sigue siendo observado');
T::igual($obs->subsanaciones(EXP_ID)[0]['estado'], 'rechazada', 'califica la subsanación como rechazada');
T::igual($obs->pendiente(EXP_ID)['estado'], 'pendiente', 'vuelve a haber observación pendiente');
T::igual($obs->pendiente(EXP_ID)['id'], 'obs-1', 'es la misma observación, no una nueva');
T::cierto(
    (string) $obs->pendiente(EXP_ID)['fecha_limite'] > date('Y-m-d H:i:s'),
    'rechazar abre un plazo nuevo por delante'
);
T::igual($svc->consultar(EXP_CARGO, EXP_DNI)['en_revision'], false, 'el presentante puede volver a subsanar');
T::igual($svc->consultar(EXP_CARGO, EXP_DNI)['observacion']['vencida'], false, 'el plazo nuevo no está vencido');

T::grupo('revisarSubsanacion: el ciclo completo');

$intento1 = [adjSubs('subsanacion/2026/intento1.pdf')];
$intento2 = [adjSubs('subsanacion/2026/intento2.pdf')];
[$sub, $obs, $storage, $svc, $corr] = escenarioSubs([$intento1[0], $intento2[0]]);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$primera = $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Intento 1.'], $intento1);
$svc->revisarSubsanacion($primera['id'], 'rechazada', 'jperez');
$segunda = $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Intento 2.'], $intento2);
$final = $svc->revisarSubsanacion($segunda['id'], 'aceptada', 'jperez');

T::igual($final['status'], 'en_revision', 'el ciclo completo cierra en revisión');
T::igual($obs->totalSubsanaciones(), 2, 'quedan las dos subsanaciones en el historial');
T::igual($segunda['nro_cargo'], 'S-2026-000002', 'la segunda subsanación lleva su propio correlativo');
T::igual($sub->estadoDe(EXP_ID), 'en_revision', 'el expediente vuelve a revisión');
T::igual($obs->pendiente(EXP_ID), null, 'ya no queda nada pendiente');

T::grupo('revisarSubsanacion: rechazos');

$otra = [adjSubs('subsanacion/2026/contrato.pdf')];
[$sub, $obs, $storage, $svc] = escenarioSubs($otra);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$alta = $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto el contrato.'], $otra);

T::cierto(erroresDeSubs(fn () => $svc->revisarSubsanacion($alta['id'], 'tal vez', 'jperez')) !== [], 'rechaza una decisión que no sea aceptar o rechazar');
T::cierto(erroresDeSubs(fn () => $svc->revisarSubsanacion('no-existe', 'aceptada', 'jperez')) !== [], 'rechaza una subsanación inexistente');

$svc->revisarSubsanacion($alta['id'], 'aceptada', 'jperez');
T::cierto(erroresDeSubs(fn () => $svc->revisarSubsanacion($alta['id'], 'rechazada', 'jperez')) !== [], 'no revisa dos veces la misma subsanación');
T::igual($obs->pendiente(EXP_ID), null, 'el segundo intento fallido no alteró la observación');

[$sub, $obs, $storage, $svc] = escenarioSubs($otra);
sembrarExpediente($sub);
$svc->registrarObservacion(EXP_ID, 'Falta el contrato.', 10, 'jperez');
$altaCerrado = $svc->registrarSubsanacion(['dni' => EXP_DNI, 'numero' => EXP_CARGO, 'descripcion' => 'Adjunto.'], $otra);
$sub->actualizarEstado(EXP_ID, 'aprobado');
T::cierto(
    erroresDeSubs(fn () => $svc->revisarSubsanacion($altaCerrado['id'], 'aceptada', 'jperez')) !== [],
    'no revisa la subsanación de un expediente que ya se resolvió'
);
