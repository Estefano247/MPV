<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/SubmissionRepositoryInterface.php';
require_once __DIR__ . '/../includes/AreaRepository.php';
require_once __DIR__ . '/../includes/CorrelativoRepository.php';
require_once __DIR__ . '/../includes/ObservacionRepository.php';
require_once __DIR__ . '/../includes/Storage.php';

/**
 * Doubles en memoria de las costuras de SolicitudService. Guardan lo que
 * recibieron, para poder afirmar sobre la orquestación y no solo sobre el valor
 * devuelto.
 */

final class FakeSubmissions implements SubmissionRepositoryInterface
{
    public array $guardados = [];
    public array $movimientos = [];
    public array $estados = [];
    public array $areasAsignadas = [];
    public array $eliminados = [];
    public int $transacciones = 0;
    /** Adjuntos registrados por id, para simular la tabla files. */
    public array $adjuntosPorId = [];

    /**
     * Simula que otro proceso insertó este id justo después del porId() del caso de
     * uso: el INSERT debe chocar y devolverse como no creado.
     */
    public bool $colisionarProximoGuardado = false;

    /** @throws RuntimeException al guardar, para probar el rollback. */
    public ?string $fallarGuardando = null;

    /**
     * Expedientes prévios, como los que ya estaban en la base antes de la
     * prueba. El flujo de subsanación necesita uno: no se observa un trámite
     * que la prueba acaba de crear.
     *
     * @var array<int, array<string,mixed>>
     */
    public array $sembradas = [];

    /**
     * Estado vigente por expediente. `actualizarEstado` la mantiene al día para
     * que `porId()` y `buscarPorAcuse()` devuelvan el estado real y las
     * siguientes operaciones del flujo lo vean.
     *
     * @var array<string,string>
     */
    public array $estadosPorId = [];

    /** Adjuntos registrados por id de subsanación, para simular files.subsanacion_id. */
    public array $adjuntosDeSubsanacion = [];

    /** Filas que se adjunctaron a cada subsanación, con su expediente. */
    public array $subsanacionesRegistradas = [];

    /** Crea un expediente previo, como si ya estuviera en la base. */
    public function sembrar(array $fila): void
    {
        $id = (string) $fila['submission_id'];
        $this->sembradas[] = $fila + ['area_actual' => $fila['area_actual'] ?? ''];
        $this->estadosPorId[$id] = (string) ($fila['status'] ?? 'pendiente');
    }

    /** Estado vigente del expediente, o null si no existe. */
    public function estadoDe(string $id): ?string
    {
        return $this->estadosPorId[$id] ?? null;
    }

    /**
     * La fila sembrada lleva el estado con que se creó, pero `actualizarEstado`
     * lo cambia: sin pisarlo, el doble serviría un estado viejo y las pruebas
     * del flujo de subsanación pasarían por la vía equivocada.
     *
     * @param array<string,mixed> $fila
     * @return array<string,mixed>
     */
    private function conEstadoVigente(array $fila): array
    {
        $id = (string) $fila['submission_id'];
        $fila['status'] = $this->estadosPorId[$id] ?? $fila['status'] ?? 'pendiente';

        return $fila;
    }

    public function guardar(array $datos, array $archivos, ?string $id = null, ?array $acuse = null): array
    {
        if ($this->fallarGuardando !== null) {
            throw new RuntimeException($this->fallarGuardando);
        }

        $id ??= \Uuid::v4();

        if ($this->colisionarProximoGuardado) {
            $this->colisionarProximoGuardado = false;
            $this->guardados[] = [
                'id' => $id,
                'datos' => $datos,
                'archivos' => $archivos,
                'acuse' => $acuse,
                'ganadora' => false,
            ];
            $this->adjuntosPorId[$id] = count($archivos);
            return [
                'id' => $id,
                'creado' => false,
                'nro_cargo' => $acuse['nro_cargo'] ?? null,
                'nro_expediente' => $acuse['nro_expediente'] ?? null,
                'acuse_hash' => $acuse['acuse_hash'] ?? null,
            ];
        }

        $this->guardados[] = [
            'id' => $id,
            'datos' => $datos,
            'archivos' => $archivos,
            'acuse' => $acuse,
            'ganadora' => true,
        ];
        $this->adjuntosPorId[$id] = count($archivos);

        return [
            'id' => $id,
            'creado' => true,
            'nro_cargo' => $acuse['nro_cargo'] ?? null,
            'nro_expediente' => $acuse['nro_expediente'] ?? null,
            'acuse_hash' => $acuse['acuse_hash'] ?? null,
        ];
    }

    public function porId(string $id): ?array
    {
        foreach ($this->sembradas as $fila) {
            if ($fila['submission_id'] === $id) {
                return $this->conEstadoVigente($fila);
            }
        }
        foreach ($this->guardados as $g) {
            if ($g['id'] === $id) {
                return $g['datos'] + [
                    'submission_id' => $id,
                    'status' => 'pendiente',
                    'area_actual_id' => null,
                    'nro_cargo' => $g['acuse']['nro_cargo'] ?? null,
                    'nro_expediente' => $g['acuse']['nro_expediente'] ?? null,
                    'acuse_hash' => $g['acuse']['acuse_hash'] ?? null,
                ];
            }
        }
        return null;
    }

    public function listar(array $filtros, int $limit, int $offset): array
    {
        return [];
    }

    public function contarPorEstado(array $filtros): array
    {
        return [];
    }

    public function porDni(string $dni): array
    {
        return array_values(array_filter(
            $this->sembradas,
            static fn (array $f): bool => (string) $f['dni'] === $dni
        ));
    }

    public function buscarPorAcuse(string $dni, string $numero): ?array
    {
        foreach ($this->sembradas as $fila) {
            if ((string) $fila['dni'] !== $dni) {
                continue;
            }
            $coincide = (string) ($fila['nro_cargo'] ?? '') === $numero
                || (string) ($fila['nro_expediente'] ?? '') === $numero;
            if ($coincide) {
                return $this->conEstadoVigente($fila);
            }
        }
        return null;
    }

    public function archivos(string $submissionId): array
    {
        return [];
    }

    public function adjuntarSubsanacion(string $submissionId, string $subsanacionId, array $archivos): void
    {
        $this->subsanacionesRegistradas[] = [
            'submission_id' => $submissionId,
            'subsanacion_id' => $subsanacionId,
            'archivos' => $archivos,
        ];
        $this->adjuntosDeSubsanacion[$subsanacionId] = count($archivos);
    }

    public function archivosDeSubsanacion(string $subsanacionId): array
    {
        foreach ($this->subsanacionesRegistradas as $s) {
            if ($s['subsanacion_id'] === $subsanacionId) {
                return $s['archivos'];
            }
        }
        return [];
    }

    public function contarArchivosDeSubsanacion(string $subsanacionId): int
    {
        return $this->adjuntosDeSubsanacion[$subsanacionId] ?? 0;
    }

    public function s3Keys(string $submissionId): array
    {
        return [];
    }

    public function contarArchivos(string $submissionId): int
    {
        return $this->adjuntosPorId[$submissionId] ?? 0;
    }

    public function actualizarEstado(string $id, string $status): void
    {
        $this->estados[] = [$id, $status];
        $this->estadosPorId[$id] = $status;
    }

    public function actualizarArea(string $id, ?int $areaId): void
    {
        $this->areasAsignadas[] = [$id, $areaId];
    }

    public function eliminar(string $id): void
    {
        $this->eliminados[] = $id;
    }

    public function registrarMovimiento(
        string $submissionId,
        string $tipo,
        ?string $descripcion = null,
        ?int $deArea = null,
        ?int $aArea = null,
        ?string $estado = null,
        ?string $usuario = null
    ): void {
        $this->movimientos[] = compact('submissionId', 'tipo', 'descripcion', 'deArea', 'aArea', 'estado', 'usuario');
    }

    public function movimientos(string $submissionId): array
    {
        return [];
    }

    public function transaccion(callable $fn): mixed
    {
        $this->transacciones++;
        $this->enTransaccion = true;
        try {
            return $fn($this);
        } finally {
            $this->enTransaccion = false;
        }
    }

    /** Permite afirmar que algo se hizo dentro (o fuera) de la transacción. */
    public bool $enTransaccion = false;
}

final class FakeStorage implements StorageInterface
{
    /** @var array<string,int|null> */
    public array $tamanos;
    public array $borrados = [];

    public function __construct(array $tamanos = [])
    {
        $this->tamanos = $tamanos;
    }

    public function objectSize(string $key): ?int
    {
        return $this->tamanos[$key] ?? null;
    }

    public function delete(string $key): void
    {
        $this->borrados[] = $key;
        unset($this->tamanos[$key]);
    }
}

final class FakeAreas implements AreaRepositoryInterface
{
    /** @param int[] $ids */
    public function __construct(private array $ids = [], private ?int $mesaDePartes = 1)
    {
    }

    public function listar(bool $soloActivas = true): array
    {
        return array_map(static fn (int $id): array => ['id' => $id, 'nombre' => 'Área ' . $id, 'siglas' => 'A' . $id], $this->ids);
    }

    public function existe(int $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    public function mesaDePartesId(): ?int
    {
        return $this->mesaDePartes;
    }
}

final class FakeCorrelativos implements CorrelativoRepositoryInterface
{
    public array $peticiones = [];

    /** Estado de la transacción observado en cada llamada, para auditoría de orden. */
    public array $dentroDeTransaccion = [];

    /** @var FakeSubmissions|null Observador opcional: sabe si hay transacción abierta. */
    public ?FakeSubmissions $observa = null;

    private array $seq = [];

    public function siguiente(string $tipo): string
    {
        $this->peticiones[] = $tipo;
        $this->dentroDeTransaccion[] = $this->observa !== null && $this->observa->enTransaccion;
        $this->seq[$tipo] = ($this->seq[$tipo] ?? 0) + 1;
        $prefix = match ($tipo) {
            CorrelativoRepository::TIPO_CARGO => 'C-',
            CorrelativoRepository::TIPO_SUBSANACION => 'S-',
            default => 'E-',
        };

        return $prefix . date('Y') . '-' . str_pad((string) $this->seq[$tipo], 6, '0', STR_PAD_LEFT);
    }

    public function vecesPedidos(string $tipo): int
    {
        return count(array_filter($this->peticiones, static fn (string $t): bool => $t === $tipo));
    }
}

/**
 * Observaciones y subsanaciones en memoria.
 *
 * Replica lo que hace ObservacionRepository sobre PostgreSQL, incluida la
 * unicidad de una sola observación pendiente: el índice único parcial de
 * `observaciones` es parte del contrato que la prueba verifica.
 */
final class FakeObservaciones implements ObservacionRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    public array $observaciones = [];

    /** @var array<string, array<string,mixed>> */
    public array $subsanaciones = [];

    private int $seq = 0;
    private int $seqSub = 0;

    /**
     * Simula el índice único parcial: no admite una segunda observación
     * pendiente sobre el mismo expediente.
     */
    public bool $unicidadPendiente = true;

    public function __construct(private ?FakeSubmissions $submissions = null)
    {
    }

    public function registrar(string $submissionId, string $detalle, int $plazoDias, string $usuario): array
    {
        $this->seq++;
        $id = 'obs-' . $this->seq;

        if ($this->unicidadPendiente && $this->pendiente($submissionId) !== null) {
            throw new RuntimeException('duplicate key: una sola observación pendiente');
        }

        $fila = [
            'id' => $id,
            'submission_id' => $submissionId,
            'detalle' => $detalle,
            'plazo_dias' => $plazoDias,
            'fecha_limite' => (new DateTimeImmutable('now'))
                ->modify('+' . $plazoDias . ' days')->format('Y-m-d 17:00:00'),
            'estado' => 'pendiente',
            'usuario' => $usuario,
            'created_at' => date('Y-m-d H:i:s'),
            'atendida_at' => null,
        ];
        $this->observaciones[$id] = $fila;

        return $fila;
    }

    public function porSubmission(string $submissionId): array
    {
        $filas = array_values(array_filter(
            $this->observaciones,
            static fn (array $o): bool => (string) $o['submission_id'] === $submissionId
        ));
        usort($filas, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $filas;
    }

    public function pendiente(string $submissionId): ?array
    {
        foreach ($this->porSubmission($submissionId) as $o) {
            if ((string) $o['estado'] === 'pendiente') {
                return $o;
            }
        }
        return null;
    }

    public function porId(string $observacionId): ?array
    {
        return $this->observaciones[$observacionId] ?? null;
    }

    public function atender(string $observacionId): void
    {
        $this->observaciones[$observacionId]['estado'] = 'atendida';
        $this->observaciones[$observacionId]['atendida_at'] = date('Y-m-d H:i:s');
    }

    public function reabrir(string $observacionId, string $nuevaFechaLimite): void
    {
        $this->observaciones[$observacionId]['estado'] = 'pendiente';
        $this->observaciones[$observacionId]['fecha_limite'] = $nuevaFechaLimite;
        $this->observaciones[$observacionId]['atendida_at'] = null;
    }

    public function desestimar(string $observacionId): void
    {
        $this->atender($observacionId);
    }

    public function registrarSubsanacion(array $datos, ?string $id = null): array
    {
        $id ??= 'sub-' . (++$this->seqSub);

        if (isset($this->subsanaciones[$id])) {
            $existente = $this->subsanaciones[$id];
            return [
                'id' => $id,
                'creado' => false,
                'nro_cargo' => (string) $existente['nro_cargo'],
                'acuse_hash' => (string) $existente['acuse_hash'],
            ];
        }

        $this->subsanaciones[$id] = [
            'id' => $id,
            'submission_id' => $datos['submission_id'],
            'observacion_id' => $datos['observacion_id'] ?? null,
            'dni' => $datos['dni'],
            'nombre' => $datos['nombre'],
            'email' => $datos['email'],
            'telefono' => $datos['telefono'],
            // En memoria queda en claro: el cifrado en reposo lo hace la
            // implementación real y no forma parte de este contrato.
            'descripcion' => $datos['descripcion'],
            'nro_cargo' => $datos['nro_cargo'],
            'acuse_hash' => $datos['acuse_hash'],
            'estado' => 'registrada',
            'usuario' => $datos['usuario'],
            'created_at' => date('Y-m-d H:i:s'),
            'revisada_at' => null,
            'revisada_por' => null,
        ];

        return [
            'id' => $id,
            'creado' => true,
            'nro_cargo' => (string) $datos['nro_cargo'],
            'acuse_hash' => (string) $datos['acuse_hash'],
        ];
    }

    public function subsanaciones(string $submissionId): array
    {
        $filas = array_values(array_filter(
            $this->subsanaciones,
            static fn (array $s): bool => (string) $s['submission_id'] === $submissionId
        ));
        usort($filas, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_map(function (array $s): array {
            $obs = $s['observacion_id'] === null ? null : ($this->observaciones[$s['observacion_id']] ?? null);
            $s['observacion_detalle'] = $obs === null ? null : $obs['detalle'];
            return $s;
        }, $filas);
    }

    public function subsanacionPorId(string $id): ?array
    {
        $fila = $this->subsanaciones[$id] ?? null;
        if ($fila === null) {
            return null;
        }

        // El join con submissions es lo que permite al acuse y a la revisión leer
        // los números y el estado del expediente desde la subsanación.
        $exp = $this->submissions?->porId((string) $fila['submission_id']);
        $obs = $fila['observacion_id'] === null ? null : ($this->observaciones[$fila['observacion_id']] ?? null);

        return $fila + [
            'nro_cargo_expediente' => $exp['nro_cargo'] ?? null,
            'nro_expediente' => $exp['nro_expediente'] ?? null,
            'status' => $exp['status'] ?? null,
            'type' => $exp['type'] ?? null,
            'area_actual_id' => $exp['area_actual_id'] ?? null,
            'area_actual' => $exp['area_actual'] ?? null,
            'observacion_detalle' => $obs === null ? null : $obs['detalle'],
            'observacion_fecha_limite' => $obs === null ? null : $obs['fecha_limite'],
        ];
    }

    public function revisar(string $subsanacionId, string $estado, string $usuario): void
    {
        $this->subsanaciones[$subsanacionId]['estado'] = $estado;
        $this->subsanaciones[$subsanacionId]['revisada_at'] = date('Y-m-d H:i:s');
        $this->subsanaciones[$subsanacionId]['revisada_por'] = $usuario;
    }

    /** Nº de filas de subsanación: atajo para las aserciones. */
    public function totalSubsanaciones(): int
    {
        return count($this->subsanaciones);
    }
}
