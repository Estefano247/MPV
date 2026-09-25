<?php

declare(strict_types=1);

/**
 * Contrato de persistencia de solicitudes.
 *
 * Existe para que los casos de uso dependan de las operaciones de dominio y no
 * de la conexión: SolicitudService recibe esta interfaz, así que en pruebas se
 * le puede pasar un doble sin abrir PostgreSQL.
 *
 * Deliberadamente no expone la conexión. Cada tabla tiene su repository
 * (áreas, correlativos) y así ninguno necesita filtrar el DbConnection.
 */
interface SubmissionRepositoryInterface
{
    /**
     * Alta de una solicitud. Es idempotente sobre `id`: si ya existe una fila
     * con ese identificador no inserta nada, no vuelve a registrar los adjuntos
     * y devuelve `creado: false` con los datos de la fila existente. Así un
     * reintento del cliente (doble clic, timeout) no duplica el expediente ni
     * invalida el acuse ya emitido.
     *
     * No abre transacción propia si ya hay una en curso, para que el caso de uso
     * pueda envolver correlativos + alta + trazabilidad en un solo commit.
     *
     * @param array{nombre:string,email:string,dni:string,telefono:string,descripcion:string,tipo:string} $datos
     * @param array<int, array{file:string,nombre:string,mime:string}> $archivos
     * @param array{nro_cargo:string,nro_expediente:string,acuse_hash:string}|null $acuse
     * @return array{id:string,creado:bool,nro_cargo:?string,nro_expediente:?string,acuse_hash:?string}
     */
    public function guardar(array $datos, array $archivos, ?string $id = null, ?array $acuse = null): array;

    /**
     * @return array<string,mixed>|null
     */
    public function porId(string $id): ?array;

    /**
     * @param array{tipo:string,buscar:string,estados:string[]} $filtros
     * @return array<int, array<string,mixed>>
     */
    public function listar(array $filtros, int $limit, int $offset): array;

    /**
     * @param array{tipo:string,buscar:string,estados:string[]} $filtros
     * @return array<string,int>
     */
    public function contarPorEstado(array $filtros): array;

    /**
     * @return array<int, array<string,mixed>>
     */
    public function porDni(string $dni): array;

    /**
     * @return array<string,mixed>|null
     */
    public function buscarPorAcuse(string $dni, string $numero): ?array;

    /**
     * @return array<int, array<string,mixed>>
     */
    public function archivos(string $submissionId): array;

    /**
     * @return string[]
     */
    public function s3Keys(string $submissionId): array;

    public function contarArchivos(string $submissionId): int;

    public function actualizarEstado(string $id, string $status): void;

    public function actualizarArea(string $id, ?int $areaId): void;

    public function eliminar(string $id): void;

    public function registrarMovimiento(
        string $submissionId,
        string $tipo,
        ?string $descripcion = null,
        ?int $deArea = null,
        ?int $aArea = null,
        ?string $estado = null,
        ?string $usuario = null
    ): void;

    /**
     * @return array<int, array<string,mixed>>
     */
    public function movimientos(string $submissionId): array;

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaccion(callable $fn): mixed;
}
