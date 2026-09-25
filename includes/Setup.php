<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Setup automático idempotente:
 *   - Crea el esquema completo de PostgreSQL si no existe (schema.sql).
 *
 * Se invoca solo desde los endpoints que lo necesitan (guardar.php,
 * mis-solicitudes.php, mpv/, admin/setup.php).
 *
 * El CORS del bucket S3 se configura manualmente en AWS y no se toca.
 */
final class Setup
{
    public static function ensureDatabase(): void
    {
        $schemaFile = __DIR__ . '/../schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('No se encontró schema.sql en ' . $schemaFile);
        }
        $sql = (string) file_get_contents($schemaFile);
        $db = Database::getConnection();
        $db->exec($sql);
    }
}