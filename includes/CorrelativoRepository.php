<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Correlativos anuales de la Mesa de Partes Virtual.
 *
 * La interfaz existe para que el caso de uso no dependa de la conexión ni de la
 * fecha del servidor.
 */
interface CorrelativoRepositoryInterface
{
    /**
     * Siguiente número del correlativo indicado para el año en curso.
     *
     * @return string Ej.: C-2026-000123
     */
    public function siguiente(string $tipo): string;
}

final class CorrelativoRepository implements CorrelativoRepositoryInterface
{
    public const TIPO_CARGO = 'cargo';
    public const TIPO_EXPEDIENTE = 'expediente';

    private DbConnection $db;

    public function __construct(?DbConnection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Siguiente número del correlativo indicado para el año en curso.
     *
     * El contador se incrementa con un upsert atómico para que dos
     * presentaciones simultáneas no obtengan el mismo número.
     *
     * @return string Ej.: C-2026-000123
     */
    public function siguiente(string $tipo): string
    {
        $stmt = $this->db->prepare(
            'INSERT INTO correlativos (anno, tipo, seq) VALUES (:anno, :tipo, 1)
             ON CONFLICT (anno, tipo) DO UPDATE SET seq = correlativos.seq + 1
             RETURNING seq'
        );
        $stmt->execute([':anno' => (int) date('Y'), ':tipo' => $tipo]);

        $rows = $stmt->fetchAll();
        $seq = (int) ($rows[0]['seq'] ?? 1);
        $prefix = $tipo === self::TIPO_CARGO ? 'C-' : 'E-';

        return $prefix . date('Y') . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
