<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/SubmissionRepositoryInterface.php';
require_once __DIR__ . '/../includes/AreaRepository.php';
require_once __DIR__ . '/../includes/CorrelativoRepository.php';
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
        return [];
    }

    public function buscarPorAcuse(string $dni, string $numero): ?array
    {
        return null;
    }

    public function archivos(string $submissionId): array
    {
        return [];
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
        $prefix = $tipo === CorrelativoRepository::TIPO_CARGO ? 'C-' : 'E-';

        return $prefix . date('Y') . '-' . str_pad((string) $this->seq[$tipo], 6, '0', STR_PAD_LEFT);
    }

    public function vecesPedidos(string $tipo): int
    {
        return count(array_filter($this->peticiones, static fn (string $t): bool => $t === $tipo));
    }
}
