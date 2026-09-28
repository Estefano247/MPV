<?php

declare(strict_types=1);

require_once __DIR__ . '/AcuseService.php';
require_once __DIR__ . '/CorrelativoRepository.php';
require_once __DIR__ . '/ObservacionRepository.php';
require_once __DIR__ . '/S3Service.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/SubmissionRepository.php';
require_once __DIR__ . '/SubmissionRepositoryInterface.php';
require_once __DIR__ . '/Uuid.php';
require_once __DIR__ . '/ValidationException.php';

/**
 * Caso de uso: observaciones y subsanaciones.
 *
 * Cubre los tres momentos del ciclo:
 *
 *  1. `registrarObservacion()`  — el área revisa, le falta algo al expediente y
 *     abre un plazo para que el presentante lo complete. El expediente queda
 *     en estado 'observado'.
 *  2. `registrarSubsanacion()`  — el presentante, con su Nº de cargo y su DNI,
 *     entrega lo que faltaba. Emite su propio Nº de cargo y su propio acuse.
 *  3. `revisarSubsanacion()`    — el área califica lo subsanado: lo acepta (el
 *     expediente vuelve a revisión) o lo rechaza (el requisito sigue en pie con
 *     un plazo nuevo).
 *
 * Igual que SolicitudService, no deja SQL ni peticiones de red: las cuatro
 * dependencias entran por el constructor con la implementación real por defecto,
 * así que el flujo completo se puede ejercitar en pruebas sin PostgreSQL ni S3.
 */
final class SubsanacionService
{
    /**
     * Artículo 115.5 de la Ley 27444: el plazo para subsanar no puede ser menor
     * a cinco días hábiles. El tope evita que se abra un plazo tan largo que el
     * expediente se quede parado por falta de respuesta.
     */
    public const PLAZO_MINIMO_DIAS = 5;
    public const PLAZO_MAXIMO_DIAS = 90;

    /** Estados en los que el expediente ya no admite requerimientos. */
    private const ESTADOS_CERRADOS = ['aprobado', 'denegado'];

    private SubmissionRepositoryInterface $submissions;
    private ObservacionRepositoryInterface $observaciones;
    private CorrelativoRepositoryInterface $correlativos;
    private StorageInterface $storage;

    public function __construct(
        ?SubmissionRepositoryInterface $submissions = null,
        ?ObservacionRepositoryInterface $observaciones = null,
        ?CorrelativoRepositoryInterface $correlativos = null,
        ?StorageInterface $storage = null
    ) {
        $this->submissions = $submissions ?? new SubmissionRepository();
        $this->observaciones = $observaciones ?? new ObservacionRepository();
        $this->correlativos = $correlativos ?? new CorrelativoRepository();
        $this->storage = $storage ?? new S3Storage();
    }

    /** Plazo por defecto, acotado al rango permitido por la directiva. */
    public static function plazoPorDefecto(): int
    {
        $config = require __DIR__ . '/config.php';
        $dias = (int) ($config['mpv']['plazoObservacionDias'] ?? 10);

        return max(self::PLAZO_MINIMO_DIAS, min(self::PLAZO_MAXIMO_DIAS, $dias));
    }

    // -----------------------------------------------------------------
    // 1. El área abre un requerimiento
    // -----------------------------------------------------------------

    /**
     * Abre una observación sobre el expediente y lo deja en 'observado'.
     *
     * @return array{id:string,detalle:string,fecha_limite:string,plazo_dias:int}
     * @throws ValidationException
     */
    public function registrarObservacion(string $submissionId, string $detalle, ?int $plazoDias, string $usuario): array
    {
        $errores = [];
        $detalle = trim($detalle);

        if ($detalle === '') {
            $errores[] = 'Detalle de la observación requerido';
        } elseif (mb_strlen($detalle) > 1000) {
            $errores[] = 'Detalle de la observación demasiado largo (máx 1000 caracteres)';
        }

        $plazoDias ??= self::plazoPorDefecto();
        if ($plazoDias < self::PLAZO_MINIMO_DIAS || $plazoDias > self::PLAZO_MAXIMO_DIAS) {
            $errores[] = 'El plazo debe estar entre ' . self::PLAZO_MINIMO_DIAS . ' y ' . self::PLAZO_MAXIMO_DIAS . ' días';
            $plazoDias = self::plazoPorDefecto();
        }

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        $expediente = $this->submissions->porId($submissionId);
        if ($expediente === null) {
            throw new ValidationException(['El expediente no existe']);
        }

        $estado = (string) $expediente['status'];
        if (in_array($estado, self::ESTADOS_CERRADOS, true)) {
            throw new ValidationException(['El expediente ya está resuelto; no admite observaciones']);
        }
        if ($estado === 'observado') {
            throw new ValidationException(['El expediente ya tiene una observación pendiente']);
        }

        if ($this->observaciones->pendiente($submissionId) !== null) {
            throw new ValidationException(['El expediente ya tiene una observación pendiente']);
        }

        $usuario = $this->usuario($usuario);

        // La observación, el cambio de estado y el movimiento van en un solo
        // commit: un expediente en 'observado' sin registro del requerimiento
        // dejaría al presentante sin saber qué le falta, y una observación
        // escrita sin cambiar el estado no se vería en el panel.
        return $this->submissions->transaccion(function (SubmissionRepositoryInterface $repo) use ($submissionId, $detalle, $plazoDias, $usuario, $estado): array {
            $observacion = $this->observaciones->registrar($submissionId, $detalle, $plazoDias, $usuario);

            if ($estado !== 'observado') {
                $repo->actualizarEstado($submissionId, 'observado');
            }

            $repo->registrarMovimiento(
                $submissionId,
                'observacion',
                'Observación: ' . $this->recortar($detalle, 380) . ' (plazo ' . $plazoDias . ' días).',
                null,
                null,
                'observado',
                $usuario
            );

            return [
                'id' => (string) $observacion['id'],
                'detalle' => $detalle,
                'fecha_limite' => (string) $observacion['fecha_limite'],
                'plazo_dias' => (int) $observacion['plazo_dias'],
            ];
        });
    }

    /**
     * Cierra una observación sin subsanación (el área se desistió de ella).
     *
     * No cambia el estado del expediente: eso lo hace el endpoint que la llama
     * por la vía normal de transición, de modo que queda registrado en la
     * trazabilidad como un cambio de estado más, y no como un atajo.
     */
    public function desestimar(string $submissionId, string $usuario): void
    {
        $observacion = $this->observaciones->pendiente($submissionId);
        if ($observacion === null) {
            return;
        }

        $this->submissions->transaccion(function () use ($observacion, $submissionId, $usuario): void {
            $this->observaciones->desestimar((string) $observacion['id']);
            $this->submissions->registrarMovimiento(
                $submissionId,
                'observacion',
                'Observación retirada por el área: ' . $this->recortar((string) $observacion['detalle'], 380) . '.',
                null,
                null,
                null,
                $this->usuario($usuario)
            );
        });
    }

    // -----------------------------------------------------------------
    // 2. El presentante subsana
    // -----------------------------------------------------------------

    /**
     * Estado de la subsanación para el formulario público: qué se le pide, con
     * qué plazo y si todavía puede presentar la subsanación.
     *
     * Devuelve null si el par (número, DNI) no corresponde a un expediente, para
     * que el endpoint no distinga entre "no existe" y "no le corresponde".
     *
     * @return array<string,mixed>|null
     */
    public function consultar(string $numero, string $dni): ?array
    {
        $numero = strtoupper(trim($numero));
        if (!preg_match('/^\d{8}$/', $dni) || !preg_match('/^[A-Z]-\d{4}-\d{6}$/', $numero)) {
            return null;
        }

        $expediente = $this->submissions->buscarPorAcuse($dni, $numero);
        if ($expediente === null) {
            return null;
        }

        $observacion = $this->observaciones->pendiente((string) $expediente['submission_id']);
        $subsanaciones = $this->observaciones->subsanaciones((string) $expediente['submission_id']);
        $estado = (string) $expediente['status'];

        return [
            'submission_id' => (string) $expediente['submission_id'],
            'dni' => (string) $expediente['dni'],
            'nro_cargo' => (string) $expediente['nro_cargo'],
            'nro_expediente' => (string) $expediente['nro_expediente'],
            'nombre' => (string) $expediente['name'],
            'email' => (string) $expediente['email'],
            'telefono' => (string) $expediente['telefono'],
            'status' => $estado,
            'cerrado' => in_array($estado, self::ESTADOS_CERRADOS, true),
            'observacion' => $observacion === null ? null : [
                'detalle' => (string) $observacion['detalle'],
                'plazo_dias' => (int) $observacion['plazo_dias'],
                'fecha_limite' => (string) $observacion['fecha_limite'],
                'vencida' => $this->vencida((string) $observacion['fecha_limite']),
            ],
            // Con la observación ya atendida el presentante ya entregó su
            // subsanación: se le informa que está en revisión en vez de volver a
            // ofrecerle el formulario.
            'en_revision' => $observacion === null && $estado === 'observado',
            'subsanaciones' => array_map(
                static fn (array $s): array => [
                    'id' => (string) $s['id'],
                    'nro_cargo' => (string) $s['nro_cargo'],
                    'estado' => (string) $s['estado'],
                    'created_at' => (string) $s['created_at'],
                ],
                $subsanaciones
            ),
        ];
    }

    /**
     * Registra la subsanación y devuelve los datos de su acuse.
     *
     * @param array{dni:string,numero:string,descripcion:string,nombre?:string,email?:string,telefono?:string} $entrada
     * @param array<int, mixed> $files Adjuntos ya subidos a S3
     * @param string|null $subsanacionId UUID que generó el cliente, si lo generó
     * @return array{id:string,nro_cargo:?string,acuse_hash:?string,total_archivos:int,reenvio:bool}
     * @throws ValidationException
     */
    public function registrarSubsanacion(array $entrada, array $files, ?string $subsanacionId = null): array
    {
        $errores = $this->erroresDeEntrada($entrada, $files);

        if ($subsanacionId !== null && $subsanacionId !== '') {
            if (!Uuid::isValid($subsanacionId)) {
                $errores[] = 'subsanacionId inválido';
                $subsanacionId = null;
            } else {
                $subsanacionId = strtolower($subsanacionId);
            }
        }

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        $subsanacionId ??= Uuid::v4();

        // El expediente se localiza por (Nº de cargo o de expediente, DNI), los
        // mismos dos datos con los que el presentante consulta el seguimiento.
        // El identificador del expediente no se acepta desde el cliente: si lo
        // mandara, bastaría conocer su UUID para subsanar un trámite ajeno.
        $expediente = $this->submissions->buscarPorAcuse($entrada['dni'], strtoupper($entrada['numero']));
        if ($expediente === null) {
            throw new ValidationException(['El número de cargo y el DNI no corresponden a ningún expediente']);
        }

        $expedienteId = (string) $expediente['submission_id'];
        $estado = (string) $expediente['status'];
        if (in_array($estado, self::ESTADOS_CERRADOS, true)) {
            throw new ValidationException(['El expediente ya está resuelto y no admite subsanaciones']);
        }

        // Idempotencia por id: el reintento del cliente (doble clic, timeout)
        // devuelve el mismo acuse en vez de registrar dos subsanaciones.
        $previa = $this->observaciones->subsanacionPorId($subsanacionId);
        if ($previa !== null) {
            // El id lo genera el cliente, así que se comprueba que la subsanación
            // guardada sea de este expediente: si no, el reintento pertenece a
            // otra respuesta y no se puede devolver su acuse.
            if ((string) $previa['submission_id'] !== $expedienteId) {
                throw new ValidationException(['El identificador de subsanación ya fue usado en otro expediente']);
            }

            return [
                'id' => $subsanacionId,
                'nro_cargo' => (string) $previa['nro_cargo'],
                'acuse_hash' => (string) $previa['acuse_hash'],
                'total_archivos' => $this->submissions->contarArchivosDeSubsanacion($subsanacionId),
                'reenvio' => true,
            ];
        }

        $observacion = $this->observaciones->pendiente($expedienteId);
        if ($observacion === null) {
            if ($estado === 'observado') {
                throw new ValidationException(['Ya entregó la subsanación de esta observación; está en revisión']);
            }
            throw new ValidationException(['El expediente no tiene una observación pendiente']);
        }

        $adjuntos = $this->verificarAdjuntos($files);

        $datos = [
            'dni' => $entrada['dni'],
            'nombre' => $this->textoOpcional($entrada['nombre'] ?? '', (string) $expediente['name']),
            'email' => $this->textoOpcional($entrada['email'] ?? '', (string) $expediente['email']),
            'telefono' => $this->textoOpcional($entrada['telefono'] ?? '', (string) $expediente['telefono']),
        ];

        // El correlativo, el acuse, la subsanación, sus adjuntos, el cierre de la
        // observación y la trazabilidad entran en un único commit. Así no puede
        // quedar una subsanación sin su acuse, ni un acuse sin su registro, ni
        // quemarse el correlativo S- si algo falla después.
        return $this->submissions->transaccion(function (SubmissionRepositoryInterface $repo) use (
            $expedienteId,
            $observacion,
            $datos,
            $entrada,
            $adjuntos,
            $subsanacionId
        ): array {
            $nroCargo = $this->correlativos->siguiente(CorrelativoRepository::TIPO_SUBSANACION);
            $acuseHash = AcuseService::hash($subsanacionId, $nroCargo);

            $alta = $this->observaciones->registrarSubsanacion([
                'submission_id' => $expedienteId,
                'observacion_id' => (string) $observacion['id'],
                'dni' => $datos['dni'],
                'nombre' => $datos['nombre'],
                'email' => $datos['email'],
                'telefono' => $datos['telefono'],
                'descripcion' => $entrada['descripcion'],
                'nro_cargo' => $nroCargo,
                'acuse_hash' => $acuseHash,
                'usuario' => 'web',
            ], $subsanacionId);

            if ($alta['creado'] === false) {
                // Carrera perdida: otra petición con el mismo id se adelantó.
                return [
                    'id' => $alta['id'],
                    'nro_cargo' => $alta['nro_cargo'],
                    'acuse_hash' => $alta['acuse_hash'],
                    'total_archivos' => $repo->contarArchivosDeSubsanacion($alta['id']),
                    'reenvio' => true,
                ];
            }

            $repo->adjuntarSubsanacion($expedienteId, $alta['id'], $adjuntos);
            $this->observaciones->atender((string) $observacion['id']);

            $repo->registrarMovimiento(
                $expedienteId,
                'subsanacion',
                'Subsanación presentada (Nº ' . $nroCargo . ') con ' . count($adjuntos) . ' documento(s).',
                null,
                null,
                'observado',
                'web'
            );

            return [
                'id' => $alta['id'],
                'nro_cargo' => $alta['nro_cargo'],
                'acuse_hash' => $alta['acuse_hash'],
                'total_archivos' => count($adjuntos),
                'reenvio' => false,
            ];
        });
    }

    // -----------------------------------------------------------------
    // 3. El área califica lo subsanado
    // -----------------------------------------------------------------

    /**
     * Acepta o rechaza la subsanación.
     *
     * Aceptarla devuelve el expediente a revisión, salvo que quede otra
     * observación abierta. Rechazarla reabre el requerimiento con un plazo
     * nuevo: el requisito sigue en pie y el presentante tiene otra oportunidad.
     *
     * @return array{subsanacion_id:string,estado:string,status:string}
     * @throws ValidationException
     */
    public function revisarSubsanacion(string $subsanacionId, string $decision, string $usuario): array
    {
        if (!in_array($decision, ['aceptada', 'rechazada'], true)) {
            throw new ValidationException(['Decisión inválida: use "aceptada" o "rechazada"']);
        }

        $subsanacion = $this->observaciones->subsanacionPorId($subsanacionId);
        if ($subsanacion === null) {
            throw new ValidationException(['La subsanación no existe']);
        }
        if ((string) $subsanacion['estado'] !== 'registrada') {
            throw new ValidationException(['La subsanación ya fue revisada']);
        }

        $expedienteId = (string) $subsanacion['submission_id'];
        $usuario = $this->usuario($usuario);
        $estadoExpediente = (string) $subsanacion['status'];

        if (in_array($estadoExpediente, self::ESTADOS_CERRADOS, true)) {
            throw new ValidationException(['El expediente ya está resuelto; la subsanación no puede revisarse']);
        }

        return $this->submissions->transaccion(function (SubmissionRepositoryInterface $repo) use (
            $subsanacion,
            $subsanacionId,
            $expedienteId,
            $decision,
            $usuario,
            $estadoExpediente
        ): array {
            $this->observaciones->revisar($subsanacionId, $decision, $usuario);

            $nroCargo = (string) $subsanacion['nro_cargo'];

            if ($decision === 'aceptada') {
                // Solo vuelve a revisión si el presentante ya respondió todo: si
                // queda alguna observación abierta, el expediente sigue
                // 'observado' y el panel lo muestra así.
                $abiertas = $this->observacionesPendientes($expedienteId);
                $nuevoEstado = $abiertas === [] ? 'en_revision' : 'observado';
                $repo->actualizarEstado($expedienteId, $nuevoEstado);

                $repo->registrarMovimiento(
                    $expedienteId,
                    'estado',
                    'Subsanación (Nº ' . $nroCargo . ') aceptada.',
                    null,
                    null,
                    $nuevoEstado,
                    $usuario
                );

                return ['subsanacion_id' => $subsanacionId, 'estado' => $decision, 'status' => $nuevoEstado];
            }

            $plazo = self::plazoPorDefecto();
            $observacionId = (string) ($subsanacion['observacion_id'] ?? '');
            if ($observacionId !== '' && $observacionId !== '0') {
                $this->observaciones->reabrir($observacionId, $this->nuevaFechaLimite($plazo));
            }

            $repo->actualizarEstado($expedienteId, 'observado');
            $repo->registrarMovimiento(
                $expedienteId,
                'subsanacion',
                'Subsanación (Nº ' . $nroCargo . ') rechazada; se mantiene el requerimiento con nuevo plazo.',
                null,
                null,
                'observado',
                $usuario
            );

            return ['subsanacion_id' => $subsanacionId, 'estado' => $decision, 'status' => 'observado'];
        });
    }

    // -----------------------------------------------------------------
    // Consultas auxiliares
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string,mixed>>
     */
    public function observacionesDe(string $submissionId): array
    {
        return $this->observaciones->porSubmission($submissionId);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function subsanacionesDe(string $submissionId): array
    {
        return $this->observaciones->subsanaciones($submissionId);
    }

    /** Observaciones que el presentante todavía no ha atendido. */
    public function hayObservacionesPendientes(string $submissionId): bool
    {
        return $this->observacionesPendientes($submissionId) !== [];
    }

    /** La observación pendiente actual del expediente, si existe. */
    public function observacionPendiente(string $submissionId): ?array
    {
        $pendientes = $this->observacionesPendientes($submissionId);

        return $pendientes[0] ?? null;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function observacionesPendientes(string $submissionId): array
    {
        return array_values(array_filter(
            $this->observaciones->porSubmission($submissionId),
            static fn (array $o): bool => (string) $o['estado'] === 'pendiente'
        ));
    }

    // -----------------------------------------------------------------
    // Validación
    // -----------------------------------------------------------------

    /**
     * @param array{dni:string,numero:string,descripcion:string,nombre?:string,email?:string,telefono?:string} $entrada
     * @param array<int, mixed> $files
     * @return string[]
     */
    private function erroresDeEntrada(array $entrada, array $files): array
    {
        $errores = [];

        if (!preg_match('/^\d{8}$/', (string) ($entrada['dni'] ?? ''))) {
            $errores[] = 'DNI inválido';
        }
        if (!preg_match('/^[A-Za-z]-\d{4}-\d{6}$/', (string) ($entrada['numero'] ?? ''))) {
            $errores[] = 'Número de cargo o expediente inválido';
        }
        $descripcion = trim((string) ($entrada['descripcion'] ?? ''));
        if ($descripcion === '') {
            $errores[] = 'Describa la subsanación realizada';
        } elseif (mb_strlen($descripcion) > 2000) {
            $errores[] = 'Descripción demasiado larga (máx 2000 caracteres)';
        }

        if (!empty($entrada['email']) && !filter_var((string) $entrada['email'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Email inválido';
        }
        if (!empty($entrada['telefono']) && !preg_match('/^\d{7,15}$/', (string) $entrada['telefono'])) {
            $errores[] = 'Teléfono inválido';
        }

        if ($files === []) {
            $errores[] = 'Debe adjuntar al menos un archivo';
        } else {
            foreach ($files as $i => $file) {
                $file = is_array($file) ? $file : [];
                $key = (string) ($file['key'] ?? '');
                if ($key === '' || strlen($key) > 1024 || str_contains($key, '..')) {
                    $errores[] = "Archivo {$i}: clave S3 inválida";
                }
                if (empty($file['originalName']) || empty($file['fileType'])) {
                    $errores[] = "Archivo {$i}: nombre o tipo inválido";
                }
            }
        }

        return $errores;
    }

    /**
     * Confirma que cada adjunto exista en el almacenamiento y no exceda el
     * máximo, y borra lo que no cumple para no dejar basura.
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
                'file' => $key,
                'nombre' => (string) ($file['originalName'] ?? ''),
                'mime' => (string) ($file['fileType'] ?? ''),
            ];
        }

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        return $adjuntos;
    }

    // -----------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------

    private function nuevaFechaLimite(int $plazoDias): string
    {
        return (new DateTimeImmutable('now'))
            ->modify('+' . $plazoDias . ' days')
            ->format('Y-m-d 17:00:00');
    }

    /** El plazo vencido es un dato calculado, no un estado que se guarde. */
    public function vencida(string $fechaLimite): bool
    {
        try {
            $limite = new DateTimeImmutable($fechaLimite);
        } catch (Throwable) {
            return false;
        }

        return $limite < new DateTimeImmutable('now');
    }

    private function usuario(string $usuario): string
    {
        $usuario = trim($usuario);

        return $usuario === '' ? 'panel' : mb_substr($usuario, 0, 50);
    }

    /** Toma el dato del presentante y, si no lo manda, el del expediente original. */
    private function textoOpcional(string $valor, string $porDefecto): string
    {
        $valor = trim($valor);

        return $valor === '' ? $porDefecto : mb_substr($valor, 0, 255);
    }

    private function recortar(string $texto, int $max): string
    {
        return mb_strlen($texto) <= $max ? $texto : mb_substr($texto, 0, $max - 1) . '…';
    }
}
