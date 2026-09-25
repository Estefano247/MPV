<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Catálogo de áreas por las que puede derivarse un expediente.
 *
 * La interfaz existe para que el caso de uso no dependa de la conexión:
 * SolicitudService la recibe inyectada y en pruebas se le pasa un doble.
 */
interface AreaRepositoryInterface
{
    /**
     * @return array<int, array{id:int,nombre:string,siglas:string}>
     */
    public function listar(bool $soloActivas = true): array;

    public function existe(int $id): bool;

    /**
     * Id del área que representa a la Mesa de Partes, que es el origen por
     * defecto de una derivación. Null si el catálogo no la tiene.
     */
    public function mesaDePartesId(): ?int;
}

/**
 * Único dueño del acceso a la tabla `areas` (áreas de derivación de la Mesa de
 * Partes Virtual).
 */
final class AreaRepository implements AreaRepositoryInterface
{
    private DbConnection $db;

    public function __construct(?DbConnection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array<int, array{id:int,nombre:string,siglas:string}>
     */
    public function listar(bool $soloActivas = true): array
    {
        $sql = 'SELECT id, nombre, siglas FROM areas';
        if ($soloActivas) {
            $sql .= ' WHERE activa = true';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([]);
        return $stmt->fetchAll();
    }

    public function existe(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM areas WHERE id = :id AND activa = true');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll() !== [];
    }

    public function mesaDePartesId(): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM areas WHERE activa = true AND LOWER(nombre) LIKE '%mesa de partes%' ORDER BY id ASC"
        );
        $stmt->execute([]);
        $rows = $stmt->fetchAll();
        return $rows === [] ? null : (int) $rows[0]['id'];
    }
}
