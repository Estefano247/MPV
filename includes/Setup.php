<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/password.php';

/**
 * Setup automático idempotente:
 *   - Crea el esquema completo de PostgreSQL si no existe (schema.sql), que ya
 *     siembra superadmin y admin con la contraseña por defecto.
 *   - Sincroniza esos dos usuarios del panel con SEED_ADMIN_* / SEED_STAFF_*
 *     del .env (el .env manda sobre el hash de la base).
 *
 * La comprobación es por existencia de la tabla `submissions`: si el esquema ya
 * está, no se reejecuta el DDL y se lee un solo registro de information_schema.
 * Es lo único que paga cada request (Railway lo llama desde las páginas del
 * panel y desde los endpoints públicos), así que el costo es mínimo.
 *
 * El CORS del bucket S3 se configura manualmente en AWS y no se toca.
 *
 * Se invoca desde guardar.php, mpv/, acuse.php,
 * seguimiento.php, subsanacion.php, guardar-subsanacion.php, las páginas del
 * panel (login, index, auditoría, setup, APIs) y bin/migrate.php.
 */
final class Setup
{
    /**
     * Roles que acepta la restricción CHECK de `users.role` (schema.sql). Si
     * SEED_ADMIN_ROLE trae otra cosa, el INSERT muere y el panel no arranca.
     */
    private const ROLES = ['admin', 'empleado', 'super-admin'];

    /** Motivo por el que el usuario del panel no quedó listo, para bin/migrate.php. */
    private static ?string $ultimoError = null;

    public static function ultimoError(): ?string
    {
        return self::$ultimoError;
    }

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
        // arranque ocurrió sin SEED_ADMIN_PASSWORD (primer deploy sin variables)
        // el esquema quedó creado pero sin usuario, y con el seed atado a la
        // creación del esquema ese usuario nunca aparecía. Es un SELECT
        // barato, el precio lo paga cada request. Además, como el .env manda
        // sobre la base, este es el punto donde rotar una contraseña surte
        // efecto.
        self::seedUsuarios($db);
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
     * Crea o sincroniza los dos usuarios del panel desde el .env
     * (SEED_ADMIN_* y SEED_STAFF_*). Es idempotente y el .env MANDA: si la
     * contraseña del archivo no corresponde al hash de la base, se reescribe el
     * hash. Así rotar una contraseña es editar el .env y recargar cualquier
     * página, sin tocar la base ni depender de admin/setup.php.
     *
     * Nunca propaga un error. Se llama en el arranque de cada request (acuse,
     * guardar, mpv, seguimiento, panel y los nueve endpoints) y una SQLException
     * aquí —un SEED_ADMIN_ROLE mal escrito, por ejemplo— se convertiría en un 500
     * en todo el panel, sin forma de arreglarlo desde la web porque
     * admin/setup.php ejecuta este mismo código. El motivo queda en el log y en
     * ultimoError(), que bin/migrate.php grita al arrancar.
     */
    private static function seedUsuarios(DbConnection $db): void
    {
        self::$ultimoError = null;

        try {
            $config = require __DIR__ . '/config.php';
            $seed = $config['seed'] ?? [];

            $usuarios = [
                ['clave' => 'admin', 'defecto' => 'super-admin'],
                ['clave' => 'staff', 'defecto' => 'admin'],
            ];

            foreach ($usuarios as $u) {
                self::sincronizarUsuario(
                    $db,
                    (string) ($seed[$u['clave'] . 'Username'] ?? ''),
                    (string) ($seed[$u['clave'] . 'Password'] ?? ''),
                    (string) ($seed[$u['clave'] . 'Role'] ?? ''),
                    $u['defecto']
                );
            }
        } catch (Throwable $e) {
            self::fallar('no se pudo dejar listos los usuarios del panel: ' . $e->getMessage());
        }
    }

    /**
     * Asegura un usuario del panel: lo crea si falta y, si existe, reescribe
     * hash/rol si no coinciden con el .env. Usuario vacío o sin contraseña en
     * el .env = ese usuario no se toca (pero sí se avisa, porque un panel sin
     * nadie que entre es un incidente, no un detalle).
     */
    private static function sincronizarUsuario(
        DbConnection $db,
        string $username,
        string $password,
        string $role,
        string $rolPorDefecto
    ): void {
        if ($username === '' || $password === '') {
            self::fallar(
                'faltan las variables de usuario en el .env (SEED_ADMIN_* o SEED_STAFF_*)'
                . ' y ese usuario del panel no se puede crear ni sincronizar.'
            );
            return;
        }

        if ($role === '') {
            $role = $rolPorDefecto;
        } elseif (!in_array($role, self::ROLES, true)) {
            error_log(sprintf(
                '[setup] role="%s" de "%s" no es un rol válido (%s): se usa "%s".',
                $role,
                $username,
                implode(', ', self::ROLES),
                $rolPorDefecto
            ));
            $role = $rolPorDefecto;
        }

        $stmt = $db->prepare('SELECT id, password_hash, role, active FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $existente = $stmt->fetchAll();

        if ($existente === []) {
            $db->prepare(
                'INSERT INTO users (username, password_hash, role, active) VALUES (:u, :h, :r, true)'
            )->execute([':u' => $username, ':h' => dashboard_hash_password($password), ':r' => $role]);
            return;
        }

        $fila = $existente[0];
        $hash = (string) $fila['password_hash'];
        $cambios = [];

        // El .env manda: una contraseña que no verifica contra el hash se
        // reescribe. Sin esto, cambiar SEED_*_PASSWORD no tendría ningún efecto
        // y el panel quedaría con la contraseña vieja (o con la por defecto de
        // schema.sql) para siempre.
        if ($hash === '' || !dashboard_verify_password($password, $hash)) {
            $cambios['password_hash'] = dashboard_hash_password($password);
        }
        if ((string) $fila['role'] !== $role) {
            $cambios['role'] = $role;
        }
        if (!$fila['active']) {
            $cambios['active'] = true;
        }

        if ($cambios === []) {
            return;
        }

        $sets = [];
        $params = [':u' => $username];
        foreach ($cambios as $col => $valor) {
            $sets[] = "{$col} = :{$col}";
            $params[":{$col}"] = $valor;
        }
        $sets[] = 'updated_at = NOW()';

        $db->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE username = :u'
        )->execute($params);

        error_log(sprintf(
            '[setup] "%s" sincronizado con el .env (%s).',
            $username,
            implode(', ', array_keys($cambios))
        ));
    }

    private static function fallar(string $motivo): void
    {
        self::$ultimoError = $motivo;
        error_log('[setup] ' . $motivo);
    }
}