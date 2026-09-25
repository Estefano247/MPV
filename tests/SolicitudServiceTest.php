<?php

declare(strict_types=1);

/**
 * SolicitudService: reglas de negocio, verificación de adjuntos, correlativos y
 * trazabilidad. Todo con dobles, sin PostgreSQL ni S3.
 */

require_once __DIR__ . '/T.php';
require_once __DIR__ . '/Doubles.php';
require_once __DIR__ . '/../includes/SolicitudService.php';
require_once __DIR__ . '/../includes/ValidationException.php';
require_once __DIR__ . '/../includes/SubmissionRepository.php';
require_once __DIR__ . '/../includes/AcuseService.php';
require_once __DIR__ . '/../includes/Uuid.php';
require_once __DIR__ . '/../includes/S3Service.php';

const MAX = S3Service::MAX_FILE_SIZE;

function datosValidos(array $over = []): array
{
    return $over + [
        'nombre' => 'María Quispe',
        'email' => 'maria.quispe@correo.com',
        'dni' => '70123456',
        'telefono' => '987654321',
        'descripcion' => 'Solicito licencia de funcionamiento para mi bodega.',
        'tipo' => 'mpv',
    ];
}

function adjunto(string $key = 'mpv/2026/a.pdf'): array
{
    return ['key' => $key, 'originalName' => 'a.pdf', 'fileType' => 'application/pdf'];
}

/**
 * Repo, storage y servicio armados de forma coherente: por defecto todo
 * adjunto que se le pase existe en el storage con 1 KB.
 *
 * @param FakeCorrelativos|null $corrOUT $out para observar los correlativos
 * @return array{0:FakeSubmissions,1:FakeStorage,2:SolicitudService,FakeCorrelativos}
 */
function escenario(array $files, ?FakeAreas $areas = null, ?array $tamanos = null, ?FakeCorrelativos $corrOUT = null): array
{
    $storage = new FakeStorage($tamanos ?? array_fill_keys(array_column($files, 'key'), 1024));
    $repo = new FakeSubmissions();
    $corr = $corrOUT ?? new FakeCorrelativos();
    $corr->observa = $repo;

    return [$repo, $storage, new SolicitudService($repo, $areas ?? new FakeAreas(), $corr, $storage), $corr];
}

/**
 * Errores que lanza la llamada, o [] si la llamada tuvo éxito. Los casos de
 * rechazo comparan contra una lista no vacía, así que si una llamada deja de
 * lanzar, la aserción falla igual.
 *
 * @return array<int,string>
 */
function erroresDe(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errores();
    }
    return [];
}

// ---------------------------------------------------------------- validación

T::grupo('Validación de datos: un turno, todos los errores juntos');

$repo = new FakeSubmissions();
[, , $s] = escenario([]);
$errores = erroresDe(fn () => $s->registrar(
    datosValidos(['nombre' => '', 'email' => 'no-es-mail', 'dni' => '123', 'telefono' => 'abc', 'tipo' => 'inventado']),
    [],
    null,
    null,
    ''
));

T::igualesEnOrden($errores, [
    'Nombre requerido (máx 200)',
    'Email inválido',
    'DNI inválido',
    'Teléfono inválido',
    'Tipo de solicitud inválido',
    'Debe adjuntar al menos un archivo',
], 'los 6 errores se reportan juntos y en el orden del formulario');
T::igual($repo->guardados, [], 'no se persiste nada si la validación falla');
T::igual($repo->movimientos, [], 'no se mueve nada si la validación falla');

T::grupo('Validación: reglas individuales');

foreach ([
    [['nombre' => str_repeat('x', 201)], 'Nombre requerido (máx 200)'],
    [['email' => ''], 'Email inválido'],
    [['dni' => '7012345'], 'DNI inválido'],
    [['dni' => '701234567'], 'DNI inválido'],
    [['telefono' => '12'], 'Teléfono inválido'],
    [['tipo' => 'OTRO'], 'Tipo de solicitud inválido'],
] as $i => [$over, $msg]) {
    [, , $s] = escenario([adjunto()]);
    T::igual(erroresDe(fn () => $s->registrar(datosValidos($over), [adjunto()], null, null, '')), [$msg], "rechaza: $msg");
}

[, , $s] = escenario([adjunto()]);
T::igual(
    erroresDe(fn () => $s->registrar(datosValidos(['telefono' => '']), [adjunto()], null, null, '')),
    [],
    'el teléfono vacío es opcional'
);

[, , $s] = escenario([adjunto()]);
T::igual(
    erroresDe(fn () => $s->registrar(datosValidos(['tipo' => 'afiliacion']), [adjunto()], null, null, '')),
    [],
    'acepta los tipos del catálogo'
);

T::grupo('Validación: adjuntos');

foreach ([
    [['key' => '', 'originalName' => 'a.pdf', 'fileType' => 'application/pdf'], 'Archivo 0: clave S3 inválida'],
    [['key' => 'a.pdf', 'originalName' => '', 'fileType' => 'application/pdf'], 'Archivo 0: nombre inválido'],
    [['key' => 'a.pdf', 'originalName' => 'a.pdf', 'fileType' => ''], 'Archivo 0: tipo inválido'],
    [['key' => 'mpv/../../etc/passwd', 'originalName' => 'a.pdf', 'fileType' => 'application/pdf'], 'Archivo 0: clave S3 inválida'],
] as $i => [$file, $msg]) {
    [, , $s] = escenario([$file]);
    T::igual(erroresDe(fn () => $s->registrar(datosValidos(), [$file], null, null, '')), [$msg], "rechaza adjunto $i: $msg");
}

T::grupo('Validación: submissionId');

[, , $s] = escenario([adjunto()]);
T::igual(
    erroresDe(fn () => $s->registrar(datosValidos(), [adjunto()], 'no-es-uuid', null, '')),
    ['submissionId inválido'],
    'un id que no es UUID se rechaza con su propio mensaje'
);

[$repo] = escenario([adjunto()]);
[, , $s] = escenario([adjunto()]);
erroresDe(fn () => $s->registrar(datosValidos(), [adjunto()], 'no-es-uuid', null, ''));
T::igual($repo->guardados, [], 'un id inválido no persiste nada');

// ------------------------------------------------------------------ adjuntos

T::grupo('Adjuntos: verificación contra el storage');

[$repo, , $s] = escenario([adjunto('mpv/ok.pdf')]);
$res = $s->registrar(datosValidos(), [adjunto('mpv/ok.pdf')], null, null, '');
T::igual($res['total_archivos'], 1, 'un adjunto válido');
T::igual(
    $repo->guardados[0]['archivos'],
    [['file' => 'mpv/ok.pdf', 'nombre' => 'a.pdf', 'mime' => 'application/pdf']],
    'normaliza a file/nombre/mime'
);

[, , $s] = escenario([adjunto('mpv/fantasma.pdf')], null, []);
T::igual(
    erroresDe(fn () => $s->registrar(datosValidos(), [adjunto('mpv/fantasma.pdf')], null, null, '')),
    ['Archivo 0: no se encontró en el storage'],
    'lo que no está en S3 se reporta'
);

[$repo, $storage, $s] = escenario([adjunto('mpv/grande.pdf')], null, ['mpv/grande.pdf' => MAX + 1]);
T::igual(
    erroresDe(fn () => $s->registrar(datosValidos(), [adjunto('mpv/grande.pdf')], null, null, '')),
    ['Archivo 0: excede el tamaño máximo de 10MB'],
    'rechaza lo que excede 10MB'
);
T::igual($storage->borrados, ['mpv/grande.pdf'], 'el adjunto excedido se borra del storage');
T::igual($repo->guardados, [], 'un adjunto que excede el límite frena el alta');

[$repo, $storage, $s] = escenario([adjunto('mpv/ok.pdf'), adjunto('mpv/grande.pdf')], null, ['mpv/ok.pdf' => 1024, 'mpv/grande.pdf' => MAX + 1]);
T::igual(
    erroresDe(fn () => $s->registrar(datosValidos(), [adjunto('mpv/ok.pdf'), adjunto('mpv/grande.pdf')], null, null, '')),
    ['Archivo 1: excede el tamaño máximo de 10MB'],
    'el error señala el índice del adjunto culpable'
);
T::igual($storage->borrados, ['mpv/grande.pdf'], 'solo se borra el que sobra');
T::igual($repo->guardados, [], 'no se persiste un alta parcialmente válida');

[$repo, , $s] = escenario([adjunto('mpv/límite.pdf')], null, ['mpv/límite.pdf' => MAX]);
T::igual($s->registrar(datosValidos(), [adjunto('mpv/límite.pdf')], null, null, '')['total_archivos'], 1, '10MB exactos se aceptan');

// ---------------------------------------------------------- camino feliz

T::grupo('Camino feliz: alta completa');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$corr = new FakeCorrelativos();
$s = new SolicitudService($repo, new FakeAreas(), $corr, new FakeStorage(['mpv/a.pdf' => 100]));

$id = '3f2b1c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
$res = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], $id, null, 'María Quispe');

T::igual($res['id'], $id, 'respeta el id que generó el cliente');
T::igual($res['nro_cargo'], 'C-' . date('Y') . '-000001', 'primer correlativo de cargo');
T::igual($res['nro_expediente'], 'E-' . date('Y') . '-000001', 'primer correlativo de expediente');
T::igual($res['acuse_hash'], AcuseService::hash($id, $res['nro_cargo']), 'el hash se calcula con el cargo y el id reales');
T::igual($res['total_archivos'], 1, 'total de adjuntos');
T::igual($corr->peticiones, ['cargo', 'expediente'], 'pide el correlativo de cargo antes que el de expediente');
T::igual(
    $repo->guardados[0]['acuse'],
    ['nro_cargo' => $res['nro_cargo'], 'nro_expediente' => $res['nro_expediente'], 'acuse_hash' => $res['acuse_hash']],
    'el acuse se persiste junto al expediente'
);
T::igual($repo->transacciones, 1, 'la trazabilidad va en una sola transacción');
T::igual($repo->guardados[0]['id'], $id, 'se persiste el mismo id que se devuelve');

T::grupo('Camino feliz: id generado por el servidor');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$res = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, '');
T::igual(Uuid::isValid($res['id']), true, 'sin id del cliente se genera un UUID v4');
T::igual($res['acuse_hash'], AcuseService::hash($res['id'], $res['nro_cargo']), 'el hash usa el id generado, no otro');
T::igual($repo->guardados[0]['id'], $res['id'], 'el id devuelto es el persistido');

T::grupo('Camino feliz: los correlativos avanzan');

[$repo, , ] = escenario([adjunto('mpv/a.pdf')]);
$corr = new FakeCorrelativos();
$s = new SolicitudService($repo, new FakeAreas(), $corr, new FakeStorage(['mpv/a.pdf' => 100]));
$r1 = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, '');
$r2 = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, '');

T::igual([$r1['nro_cargo'], $r2['nro_cargo']], ['C-' . date('Y') . '-000001', 'C-' . date('Y') . '-000002'], 'el cargo avanza');
T::igual([$r1['nro_expediente'], $r2['nro_expediente']], ['E-' . date('Y') . '-000001', 'E-' . date('Y') . '-000002'], 'el expediente avanza');
T::igual(count(array_unique([$r1['id'], $r2['id']])), 2, 'dos altas dan dos ids distintos');
T::igual(count($repo->guardados), 2, 'se guardan ambas');

// ------------------------------------------------------------- trazabilidad

T::grupo('Trazabilidad: alta sin derivación');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, 'María Quispe');

T::igual(count($repo->movimientos), 1, 'sin área solo se registra la apertura');
T::igual($repo->movimientos[0]['tipo'], 'registro', 'movimiento de tipo registro');
T::igual($repo->movimientos[0]['estado'], 'pendiente', 'el expediente nace pendiente');
T::igual($repo->movimientos[0]['usuario'], 'María Quispe', 'el usuario es el nombre de la sesión');
T::igual($repo->movimientos[0]['aArea'], null, 'sin área no hay destino');
T::igual($repo->areasAsignadas, [], 'sin área no se asigna ninguna');

T::grupo('Trazabilidad: alta con derivación');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')], new FakeAreas([1, 7]));
$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, 7, 'María Quispe');

T::igual(count($repo->movimientos), 2, 'con área válida se registra y se deriva');
T::igual(array_column($repo->movimientos, 'tipo'), ['registro', 'derivacion'], 'el orden es registro -> derivacion');
T::igual($repo->movimientos[1]['deArea'], 1, 'el origen es la Mesa de Partes');
T::igual($repo->movimientos[1]['aArea'], 7, 'el destino es el área elegida');
T::igual($repo->movimientos[1]['estado'], 'pendiente', 'la derivación no cambia el estado');
T::igual($repo->areasAsignadas, [[$repo->guardados[0]['id'], 7]], 'actualiza el área actual del expediente');

T::grupo('Trazabilidad: área inválida o ausente en el catálogo');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')], new FakeAreas([1]));
$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, 99, 'María Quispe');
T::igual($repo->areasAsignadas, [], 'un área que no existe se ignora');
T::igual(count($repo->movimientos), 1, 'y no genera movimiento de derivación');
T::igual(count($repo->guardados), 1, 'pero el expediente se guarda igual');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, 'María Quispe');
T::igual($repo->areasAsignadas, [], 'sin área elegida no hay asignación');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')], new FakeAreas([1, 7], null));
$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, 7, 'María Quispe');
T::igual($repo->areasAsignadas, [[$repo->guardados[0]['id'], 7]], 'deriva igual si el catálogo no tiene Mesa de Partes');
T::igual($repo->movimientos[1]['deArea'], null, 'el origen queda null si no hay Mesa de Partes');

// ------------------------------------------------------ nombre del remitente

T::grupo('El nombre real del remitente viene de la sesión');

foreach (['PRE-EVALUACIÓN', 'SIN NOMBRE'] as $placeholder) {
    [$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
    $s->registrar(datosValidos(['nombre' => $placeholder]), [adjunto('mpv/a.pdf')], null, null, 'Juan Pérez');
    T::igual($repo->guardados[0]['datos']['nombre'], 'Juan Pérez', "sustituye $placeholder por el nombre real");
}

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$s->registrar(datosValidos(['nombre' => 'Escribió algo']), [adjunto('mpv/a.pdf')], null, null, 'Juan Pérez');
T::igual($repo->guardados[0]['datos']['nombre'], 'Escribió algo', 'un nombre propio del usuario no se pisa');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$s->registrar(datosValidos(['nombre' => 'PRE-EVALUACIÓN']), [adjunto('mpv/a.pdf')], null, null, '');
T::igual($repo->guardados[0]['datos']['nombre'], 'PRE-EVALUACIÓN', 'sin sesión, el placeholder queda');
T::igual($repo->movimientos[0]['usuario'], 'web', 'sin sesión, el movimiento queda a nombre de web');

// ============================================================== idempotencia

T::grupo('Idempotencia: el mismo submissionId no crea dos expedientes');

$id = '11111111-2222-4333-8444-555555555555';
[$repo, , $s, $corr] = escenario([adjunto('mpv/a.pdf')]);

$primero = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], $id, null, 'María Quispe');
T::igual($primero['reenvio'], false, 'la primera vez no es reenvío');
T::igual($primero['nro_cargo'], 'C-' . date('Y') . '-000001', 'la primera vez consume el correlativo 1');

$segundo = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], $id, null, 'María Quispe');
T::igual($segundo['reenvio'], true, 'la segunda vez se marca como reenvío');
T::igual($segundo['id'], $primero['id'], 'mismo expediente');
T::igual($segundo['nro_cargo'], $primero['nro_cargo'], 'devuelve el mismo Nº de cargo');
T::igual($segundo['nro_expediente'], $primero['nro_expediente'], 'devuelve el mismo Nº de expediente');
T::igual($segundo['acuse_hash'], $primero['acuse_hash'], 'devuelve el mismo hash: el acuse no se invalida');
T::igual($segundo['total_archivos'], $primero['total_archivos'], 'mismo total de adjuntos');

T::igual(count($repo->guardados), 1, 'no se inserta un segundo expediente');
T::igual(count($repo->movimientos), 1, 'no se duplica el movimiento de apertura');
T::igual($corr->vecesPedidos('cargo'), 1, 'el reintento NO quema un correlativo de cargo');
T::igual($corr->vecesPedidos('expediente'), 1, 'el reintento NO quema un correlativo de expediente');

T::grupo('Idempotencia: el reintento no vuelve a derivar');

$id = '22222222-3333-4444-8555-666666666666';
[$repo, , $s, $corr] = escenario([adjunto('mpv/a.pdf')], new FakeAreas([1, 7]));

$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], $id, 7, 'María Quispe');
$replay = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], $id, 7, 'María Quispe');

T::igual($replay['reenvio'], true, 'el reenvío se detecta');
T::igual(count($repo->movimientos), 2, 'no se repite el par registro+derivacion');
T::igual(count($repo->areasAsignadas), 1, 'no se reasigna el área');
T::igual($corr->vecesPedidos('cargo'), 1, 'y no se quema correlativo');

T::grupo('Idempotencia: un id distinto sí es un expediente nuevo');

[$repo, , $s, $corr] = escenario([adjunto('mpv/a.pdf')]);
$a = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], '33333333-4444-4555-8666-777777777777', null, '');
$b = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], '44444444-5555-4666-8777-888888888888', null, '');

T::igual($b['reenvio'], false, 'otro id no es reenvío');
T::igual([$a['nro_cargo'], $b['nro_cargo']], ['C-' . date('Y') . '-000001', 'C-' . date('Y') . '-000002'], 'cada uno consume su correlativo');
T::igual(count($repo->guardados), 2, 'dos expedientes');

// =================================================================== carreras

T::grupo('Carrera: dos peticiones con el mismo id a la vez');

[$repo, , $s, $corr] = escenario([adjunto('mpv/a.pdf')]);
// Simula que otra petición se adelantó entre el porId() y el INSERT: el INSERT
// choca con la clave primaria y el alta debe salir como no creada.
$repo->colisionarProximoGuardado = true;

$res = $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], '55555555-6666-4777-8888-999999999999', null, 'María Quispe');

T::igual($res['reenvio'], true, 'la que pierde la carrera se marca como reenvío');
T::igual($res['nro_cargo'], 'C-' . date('Y') . '-000001', 'devuelve el correlativo de la ganadora, no uno huérfano');
T::igual($res['acuse_hash'], AcuseService::hash('55555555-6666-4777-8888-999999999999', 'C-' . date('Y') . '-000001'), 'el hash corresponde a la ganadora');
T::igual($repo->movimientos, [], 'la perdedora no escribe trazabilidad sobre un alta que no hizo');
T::igual($corr->vecesPedidos('cargo'), 1, 'y no vuelve a pedir correlativo');

T::grupo('Atomicidad: los correlativos se piden dentro de la transacción');

[$repo, , $s, $corr] = escenario([adjunto('mpv/a.pdf')]);
$s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, '');

T::igual($corr->dentroDeTransaccion, [true, true], 'ambos correlativos se piden con la transacción abierta');
T::igual($repo->transacciones, 1, 'correlativos, alta y trazabilidad en un solo commit');

T::grupo('Atomicidad: si el alta falla no queda nada a medias');

[$repo, , $s] = escenario([adjunto('mpv/a.pdf')]);
$repo->fallarGuardando = 'Fallo de BD simulado';

try {
    $s->registrar(datosValidos(), [adjunto('mpv/a.pdf')], null, null, '');
    T::igual('no lanzó', 'RuntimeException', 'el error de la base sube al endpoint');
} catch (RuntimeException $e) {
    T::igual($e->getMessage(), 'Fallo de BD simulado', 'propaga el error original');
}

T::igual($repo->guardados, [], 'no queda expediente a medias');
T::igual($repo->movimientos, [], 'ni trazabilidad huérfana');
T::igual($repo->areasAsignadas, [], 'ni área asignada');
