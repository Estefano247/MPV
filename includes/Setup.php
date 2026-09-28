<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/password.php';

/**
 * Setup automático idempotente:
 *   - Crea el esquema completo de PostgreSQL si no existe (schema.sql).
 *   - Al crearlo por primera vez, siembra el usuario administrador del panel
 *     desde SEED_ADMIN_USERNAME / SEED_ADMIN_PASSWORD / SEED_ADMIN_ROLE.
 *
 * La comprobación es por existencia de la tabla `submissions`: si el esquema ya
 * está, no se reejecuta el DDL y se lee un solo registro de information_schema.
 * Es lo único que paga cada request (Railway lo llama desde las páginas del
 * panel y desde los endpoints del portal), así que el costo es mínimo.
 *
 * El CORS del bucket S3 se configura manualmente en AWS y no se toca.
 *
 * Se invoca desde guardar.php, mis-solicitudes.php, mpv/, acuse.php,
 * seguimiento.php, subsanacion.php, guardar-subsanacion.php, las páginas del
 * panel (login, index, auditoría, setup, APIs) y bin/migrate.php.
 */
final class Setup
{
    public static function ensureDatabase(): void
    {
        $db = Database::getConnection();

        if (!self::esquemaExiste($db)) {
            $schemaFile = __DIR__ . '/../schema.sql';
            if (!is_file($schemaFile)) {
                throw new RuntimeException('No se encontró schema.sql en ' . $schemaFile);
            }
            $db->exec((string) file_get_contents($schemaFile));
        }

        // El seed va SIEMPRE, no solo cuando se crea el esquema: si el primer
        // arranque ocurrió sin SEED_ADMIN_PASSWORD (Railway, primer deploy sin
        // variables) el esquema quedó creado pero sin usuario, y con el seed atado
        // a la creación del esquema ese usuario nunca aparecía. Es un SELECT
        // barato, el precio lo paga cada request.
        self::seedAdmin($db);
    }

    private static function esquemaExiste(DbConnection $db): bool
    {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.tables
              WHERE table_schema = 'public'
                AND table_name = 'submissions'"
        );
        $stmt->execute();

        return $stmt->fetchAll() !== [];
    }

    /**
     * Crea el usuario admin del panel si no existe. Es idempotente y no cambia
     * la contraseña si el usuario ya existe, para no dejar el panel colgado
     * por un cambio de SEED_ADMIN_PASSWORD sobre una base en uso.
     */
    private static function seedAdmin(DbConnection $db): void
    {
        $config = require __DIR__ . '/config.php';

        $username = (string) ($config['seed']['adminUsername'] ?? '');
        $password = (string) ($config['seed']['adminPassword'] ?? '');
        $role = (string) ($config['seed']['adminRole'] ?? 'super-admin');

        if ($username === '' || $password === '') {
            return;
        }

        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $existente = $stmt->fetchAll();

        if ($existente !== []) {
            $hash = (string) $existente[0]['password_hash'];
            if ($hash !== '' && dashboard_verify_password($password, $hash)) {
                return;
            }
            error_log('[setup] el usuario del panel existe con otra contraseña: se dejó como está.');
            return;
        }

        $db->prepare(
            'INSERT INTO users (username, password_hash, role, active) VALUES (:u, :h, :r, true)'
        )->execute([':u' => $username, ':h' => dashboard_hash_password($password), ':r' => $role]);
    }
}