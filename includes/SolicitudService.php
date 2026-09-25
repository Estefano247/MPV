<?php

declare(strict_types=1);

require_once __DIR__ . '/AcuseService.php';
require_once __DIR__ . '/AreaRepository.php';
require_once __DIR__ . '/CorrelativoRepository.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/SubmissionRepository.php';
require_once __DIR__ . '/SubmissionRepositoryInterface.php';
require_once __DIR__ . '/Uuid.php';
require_once __DIR__ . '/ValidationException.php';

/**
 * Caso de uso: presentar una solicitud en la Mesa de Partes Virtual.
 *
 * Encadena en un solo lugar lo que antes estaba desarmado dentro de
 * guardar.php — reglas de negocio, resolución del identificador del
 * expediente, verificación real de los adjuntos, correlativos, persistencia y
 * trazabilidad inicial — para que el endpoint solo tenga que traducir la
 * petición y la respuesta.
 *
 * Las tres dependencias externas (expedientes, catálogos y almacenamiento)
 * entran por el constructor con la implementación real por defecto, así que el
 * caso de uso se puede ejercitar entero sin PostgreSQL ni S3.
 *
 * Los errores de validación se acumulan y se lanzan juntos en una
 * ValidationException; el cliente los muestra en una sola línea.
 */
final class SolicitudService
{
    /** Nombres que enviaba el formulario antiguo en lugar del del asociado. */
    private const NOMBRES_PLACEHOLDER = ['PRE-EVALUACIÓN', 'SIN NOMBRE'];

    private SubmissionRepositoryInterface $repo;
    private AreaRepositoryInterface $areas;
    private CorrelativoRepositoryInterface $correlativos;
    private StorageInterface $storage;

    public function __construct(
        ?SubmissionRepositoryInterface $repo = null,
        ?AreaRepositoryInterface $areas = null,
        ?CorrelativoRepositoryInterface $correlativos = null,
        ?StorageInterface $storage = null
    ) {
        $this->repo = $repo ?? new SubmissionRepository();
        $this->areas = $areas ?? new AreaRepository();
        $this->correlativos = $correlativos ?? new CorrelativoRepository();
        $this->storage = $storage ?? new S3Storage();
    }

    /**
     * Registra la solicitud y devuelve los datos de su acuse.
     *
     * @param array{nombre:string,email:string,dni:string,telefono:string,descripcion:string,tipo:string} $datos
     * @param array<int, mixed> $files Adjuntos ya subidos a S3 con pre-signed URLs
     * @param string|null $submissionId UUID que generó el cliente, si lo generó
     * @param int|null $areaId Área de destino elegida por el remitente
     * @param string $nombreSesion Nombre real del asociado en el portal ('' si no hay)
     * @return array{id:string,nro_cargo:?string,nro_expediente:?string,acuse_hash:?string,total_archivos:int,reenvio:bool}
     * @throws ValidationException
     */
    public function registrar(
        array $datos,
        array $files,
        ?string $submissionId,
        ?int $areaId,
        string $nombreSesion
    ): array {
        // Fase 1: estructura de la entrada. Se reportan todos los errores juntos.
        $errores = array_merge($this->erroresDeDatos($datos), $this->erroresDeArchivos($files));

        $id = $submissionId;
        if ($id !== null && $id !== '') {
            if (!Uuid::isValid($id)) {
                $errores[] = 'submissionId inválido';
                $id = null;
            } else {
                $id = strtolower($id);
            }
        }

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        // Si el cliente no generó su ID (p. ej. afiliación), se crea aquí para que
        // la huella del acuse corresponda al mismo id que se persiste.
        $id ??= Uuid::v4();

        $datos = $this->conNombreReal($datos, $nombreSesion);

        // El tipo se guarda siempre en su forma canónica ('credito' se persiste
        // como 'prestamo-solidario'), para que el mismo trámite no quede
        // repartido en dos según por dónde entró la solicitud.
        $canonico = SubmissionRepository::tipoCanonico($datos['tipo']);
        if ($canonico !== null) {
            $datos['tipo'] = $canonico;
        }

        // Fase 2: los adjuntos existen y pesan lo que dicen pesar.
        $adjuntos = $this->verificarAdjuntos($files);

        // Fase 3: alta del expediente, en una sola transacción.
        //
        // Todo el bloque va junto a propósito. Los correlativos se incrementan con
        // un upsert sobre `correlativos`; si el alta fallara después, el rollback
        // devuelve el contador a su valor anterior en vez de quemar el Nº de cargo
        // y el de expediente, que deben ir en pareja. Y la trazabilidad se escribe
        // en el mismo commit que el alta, para que no exista un expediente sin su
        // movimiento de apertura.
        return $this->repo->transaccion(function (SubmissionRepositoryInterface $repo) use ($datos, $adjuntos, $id, $areaId, $nombreSesion): array {
            // Idempotencia: si el id ya está registrado se devuelve el acuse ya
            // emitido, sin correlativos nuevos y sin tocar los adjuntos. Es el caso
            // del doble clic o del reintento tras un timeout: el cliente se lleva
            // el mismo Nº de cargo que la primera vez.
            $previo = $repo->porId($id);
            if ($previo !== null) {
                return [
                    'id' => $id,
                    'nro_cargo' => $this->texto($previo['nro_cargo'] ?? null),
                    'nro_expediente' => $this->texto($previo['nro_expediente'] ?? null),
                    'acuse_hash' => $this->texto($previo['acuse_hash'] ?? null),
                    'total_archivos' => $repo->contarArchivos($id),
                    'reenvio' => true,
                ];
            }

            $nroCargo = $this->correlativos->siguiente(CorrelativoRepository::TIPO_CARGO);
            $nroExpediente = $this->correlativos->siguiente(CorrelativoRepository::TIPO_EXPEDIENTE);
            $acuse = [
                'nro_cargo' => $nroCargo,
                'nro_expediente' => $nroExpediente,
                'acuse_hash' => AcuseService::hash($id, $nroCargo),
            ];

            $alta = $repo->guardar($datos, $adjuntos, $id, $acuse);

            // Carrera perdida: otra petición con el mismo id se adelantado entre el
            // porId() y este INSERT. `guardar()` no insertó nada, así que se
            // devuelve el acuse de la ganadora en vez de un correlativo huérfano.
            if ($alta['creado'] === false) {
                return [
                    'id' => $alta['id'],
                    'nro_cargo' => $alta['nro_cargo'],
                    'nro_expediente' => $alta['nro_expediente'],
                    'acuse_hash' => $alta['acuse_hash'],
                    'total_archivos' => $repo->contarArchivos($alta['id']),
                    'reenvio' => true,
                ];
            }

            $this->registrarTrazabilidad($repo, $alta['id'], $adjuntos, $nombreSesion, $areaId);

            return [
                'id' => $alta['id'],
                'nro_cargo' => $alta['nro_cargo'],
                'nro_expediente' => $alta['nro_expediente'],
                'acuse_hash' => $alta['acuse_hash'],
                'total_archivos' => count($adjuntos),
                'reenvio' => false,
            ];
        });
    }

    /**
     * @param array{nombre:string,email:string,dni:string,telefono:string,descripcion:string,tipo:string} $datos
     * @return string[]
     */
    private function erroresDeDatos(array $datos): array
    {
        $errores = [];

        if (empty($datos['nombre']) || strlen($datos['nombre']) > 200) {
            $errores[] = 'Nombre requerido (máx 200)';
        }
        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Email inválido';
        }
        if (!preg_match('/^\d{8}$/', $datos['dni'])) {
            $errores[] = 'DNI inválido';
        }
        if ($datos['telefono'] !== '' && !preg_match('/^\d{7,15}$/', $datos['telefono'])) {
            $errores[] = 'Teléfono inválido';
        }
        if (SubmissionRepository::tipoCanonico($datos['tipo']) === null) {
            $errores[] = 'Tipo de solicitud inválido';
        }

        return $errores;
    }

    /**
     * @param array<int, mixed> $files
     * @return string[]
     */
    private function erroresDeArchivos(array $files): array
    {
        if ($files === []) {
            return ['Debe adjuntar al menos un archivo'];
        }

        $errores = [];
        foreach ($files as $i => $file) {
            $file = is_array($file) ? $file : [];
            $key = (string) ($file['key'] ?? '');

            if ($key === '' || strlen($key) > 1024) {
                $errores[] = "Archivo {$i}: clave S3 inválida";
            }
            if (empty($file['originalName'])) {
                $errores[] = "Archivo {$i}: nombre inválido";
            }
            if (empty($file['fileType'])) {
                $errores[] = "Archivo {$i}: tipo inválido";
            }
            if (str_contains($key, '..')) {
                $errores[] = "Archivo {$i}: clave S3 inválida";
            }
        }

        return $errores;
    }

    /**
     * El nombre real del asociado lo fija el servidor desde la sesión del
     * portal, para que en el panel administrativo aparezca la persona y no los
     * placeholders que enviaba el formulario antiguo.
     *
     * @param array{nombre:string,email:string,dni:string,telefono:string,descripcion:string,tipo:string} $datos
     * @return array{nombre:string,email:string,dni:string,telefono:string,descripcion:string,tipo:string}
     */
    private function conNombreReal(array $datos, string $nombreSesion): array
    {
        if ($nombreSesion !== '' && in_array($datos['nombre'], self::NOMBRES_PLACEHOLDER, true)) {
            $datos['nombre'] = $nombreSesion;
        }
        return $datos;
    }

    /**
     * Confirma que cada adjunto exista en el almacenamiento y no exceda el
     * máximo, devolviendo la lista normalizada para persistir. Lo que no cumple
     * se borra para no dejar basura.
     *
     * @param array<int, mixed> $files
     * @return array<int, array{file:string,nombre:string,mime:string}>
     * @throws ValidationException
     */
    private function verificarAdjuntos(array $files): array
    {
        $maxBytes = S3Service::MAX_FILE_SIZE;
        $maxMb = intdiv($maxBytes, 1024 * 1024);

        $adjuntos = [];
        $errores = [];

        foreach ($files as $i => $file) {
            $key = (string) ($file['key'] ?? '');

            $size = $this->storage->objectSize($key);
            if ($size === null) {
                $errores[] = "Archivo {$i}: no se encontró en el storage";
                continue;
            }
            if ($size > $maxBytes) {
                $errores[] = "Archivo {$i}: excede el tamaño máximo de {$maxMb}MB";
                $this->storage->delete($key);
                continue;
            }

            $adjuntos[] = [
                'file'   => $key,
                'nombre' => (string) ($file['originalName'] ?? ''),
                'mime'   => (string) ($file['fileType'] ?? ''),
            ];
        }

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        return $adjuntos;
    }

    /**
     * Movimiento de apertura del expediente y, si el remitente indicó un área
     * válida, derivación a esa área dejando la Mesa de Partes como origen.
     *
     * No abre transacción: se ejecuta dentro de la de `registrar()`, para que el
     * alta y su trazabilidad entren en el mismo commit.
     *
     * @param array<int, array{file:string,nombre:string,mime:string}> $adjuntos
     */
    private function registrarTrazabilidad(SubmissionRepositoryInterface $repo, string $expedienteId, array $adjuntos, string $nombreSesion, ?int $areaId): void
    {
        $usuario = $nombreSesion !== '' ? $nombreSesion : 'web';
        $destino = ($areaId !== null && $this->areas->existe($areaId)) ? $areaId : null;
        $origen = null;

        if ($destino !== null) {
            $origen = $this->areas->mesaDePartesId();
        }

        $repo->registrarMovimiento(
            $expedienteId,
            'registro',
            'Registro del trámite y recepción de ' . count($adjuntos) . ' documento(s).',
            null,
            null,
            'pendiente',
            $usuario
        );

        if ($destino === null) {
            return;
        }

        $repo->actualizarArea($expedienteId, $destino);
        $repo->registrarMovimiento(
            $expedienteId,
            'derivacion',
            'Derivado al área de destino indicada por el remitente.',
            $origen,
            $destino,
            'pendiente',
            $usuario
        );
    }

    /** Normaliza un valor de columna a string o null. */
    private function texto(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }
}
