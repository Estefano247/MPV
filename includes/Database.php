<?php

declare(strict_types=1);

require_once __DIR__ . '/Uuid.php';

/**
 * Conexión a PostgreSQL con doble driver.
 *
 * - Si el hosting tiene `pdo_pgsql` (está en PDO::getAvailableDrivers) usa PDO.
 * - Si no, usa la extensión nativa `pgsql` (funciones pg_*), que es la que cPanel
 *   dispone cuando `pdo_pgsql` es "skipped as conflict" con pdo_mysql.
 *
 * Lee DATABASE_URL desde el .env raíz (mismo esquema que src/).
 */
final class Database
{
    private static ?DbConnection $instance = null;

    public static function getConnection(): DbConnection
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/config.php';
            self::$instance = new DbConnection($config['database']['url']);
        }
        return self::$instance;
    }

    public static function uuid(): string
    {
        return Uuid::v4();
    }
}

/**
 * Wrapper de conexión a PostgreSQL compatible con la API PDO que usa la app
 * (beginTransaction, prepare/execute, fetchAll, exec, commit, rollBack), que
 * también funciona cuando solo está disponible la extensión nativa `pgsql`.
 */
final class DbConnection
{
    public readonly bool $usingPdo;

    private false|\PDO $pdo = false;
    /** @var resource|false */
    private $pg = false;

    /**
     * Profundidad de anidamiento. PostgreSQL no tiene transacciones anidadas, así
     * que un BEGIN anidado se ignora y el COMMIT interno solo baja el contador: la
     * transacción real se cierra cuando vuelve a 0. Así `transaccion()` puede
     * envolver un caso de uso que a su vez llama a repositorios que también la
     * abren, sin que el commit interno cierre la externa.
     */
    private int $nivel = 0;

    public function __construct(string $dbUrl)
    {
        $parsed = parse_url($dbUrl);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 5432;
        $dbname = ltrim($parsed['path'] ?? '', '/');
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';

        $pdoAvailable = class_exists('PDO', false) && in_array('pgsql', PDO::getAvailableDrivers(), true)
            && getenv('FORCE_NATIVE_PG') !== '1';

        if ($pdoAvailable) {
            $this->usingPdo = true;
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return;
        }

        if (!function_exists('pg_connect')) {
            throw new RuntimeException(
                'No hay driver PostgreSQL disponible (ni pdo_pgsql ni pgsql). Revisa las extensiones de PHP del hosting.'
            );
        }

        $this->usingPdo = false;
        // La contraseña puede contener caracteres especiales; se pasa por variable
        // de entorno para no tener que escapar el conninfo string.
        putenv('PGPASSWORD=' . $pass);
        $connStr = sprintf('host=%s port=%s dbname=%s user=%s', $host, $port, $dbname, $user);
        $this->pg = pg_connect($connStr);
        if ($this->pg === false) {
            throw new RuntimeException('No se pudo conectar a PostgreSQL: ' . (string) pg_last_error());
        }
    }

    public function beginTransaction(): void
    {
        $this->nivel++;
        if ($this->nivel > 1) {
            return; // PostgreSQL no anida: la transacción real sigue abierta.
        }
        if ($this->usingPdo) {
            $this->pdo->beginTransaction();
            return;
        }
        $this->pgRun('BEGIN');
    }

    public function commit(): void
    {
        if ($this->nivel === 0) {
            throw new RuntimeException('commit() sin transacción activa');
        }
        $this->nivel--;
        if ($this->nivel > 0) {
            return;
        }
        if ($this->usingPdo) {
            $this->pdo->commit();
            return;
        }
        $this->pgRun('COMMIT');
    }

    public function rollBack(): void
    {
        if ($this->nivel === 0) {
            return;
        }
        // Un rollback descarta toda la transacción real, así que el contador
        // vuelve a cero: no puede quedar un nivel intermedio pendiente.
        $this->nivel = 0;
        if ($this->usingPdo) {
            $this->pdo->rollBack();
            return;
        }
        $this->pgRun('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->nivel > 0;
    }

    private function pgRun(string $sql): void
    {
        $result = @pg_query($this->pg, $sql);
        if ($result === false) {
            $err = pg_last_error($this->pg);
            throw new RuntimeException(
                'Error PostgreSQL: ' . (is_string($err) && $err !== '' ? $err : 'desconocido')
                . ' | SQL: ' . substr($sql, 0, 200)
            );
        }
    }

    /**
     * Ejecuta una consulta que no devuelve filas (schema, DDL, etc.).
     * Acepta múltiples instrucciones separadas por ; como hacía PDO::exec.
     */
    public function exec(string $sql): void
    {
        if ($this->usingPdo) {
            $this->pdo->exec($sql);
            return;
        }
        $this->pgRun($sql);
    }

    public function prepare(string $sql): DbStatement
    {
        if ($this->usingPdo) {
            return new DbStatement($this->pdo->prepare($sql));
        }
        return new DbStatement($sql, $this);
    }

    /**
     * Modo nativo: ejecuta la consulta con :nombre  convertidos a $1..$n.
     * pg_query_params prepara y ejecuta en un solo paso con valores posicionales.
     */
    public function runNative(string $sql, array $params): array
    {
        helper_pg_convert($sql, $params, $positionalSql, $ordered);

        if ($ordered === []) {
            $result = @pg_query($this->pg, $positionalSql);
        } else {
            $result = @pg_query_params($this->pg, $positionalSql, $ordered);
        }

        if ($result === false) {
            $err = pg_last_error($this->pg);
            throw new RuntimeException(
                'Error PostgreSQL: ' . (is_string($err) && $err !== '' ? $err : 'desconocido')
                . ' | SQL: ' . substr($positionalSql, 0, 200)
            );
        }

        $rows = pg_fetch_all($result);
        return $rows === false ? [] : $rows;
    }
}

/**
 * Convierte marcadores PDO-style (:nombre) a $1..$n y reordena los valores.
 *
 * @param array<int|string, mixed> $params
 * @param array<int, mixed> $ordered Valores en orden posicional (por referencia)
 */
function helper_pg_convert(string $sql, array $params, ?string &$positionalSql, ?array &$ordered): void
{
    $indexByName = [];
    $nextIndex = 1;
    $positionalSql = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', function ($m) use (&$indexByName, &$nextIndex) {
        $name = $m[1];
        if (!isset($indexByName[$name])) {
            $indexByName[$name] = $nextIndex++;
        }
        return '$' . $indexByName[$name];
    }, $sql);

    // Poder pasar parámetros con o sin ":" como prefijo
    $values = [];
    foreach ($params as $key => $value) {
        $name = str_starts_with($key, ':') ? substr($key, 1) : $key;
        $values[$name] = $value;
    }

    $ordered = [];
    foreach ($indexByName as $name => $index) {
        $ordered[$index] = $values[$name] ?? null;
    }
    ksort($ordered);
    $ordered = array_values($ordered);
}

final class DbStatement
{
    private false|\PDOStatement $pdoStmt = false;
    private string $sql = '';
    private false|DbConnection $conn = false;
    private array $rows = [];

    public function __construct(false|\PDOStatement|string $first, false|DbConnection $conn = false)
    {
        if ($first instanceof \PDOStatement) {
            $this->pdoStmt = $first;
        } else {
            $this->sql = (string) $first;
            $this->conn = $conn;
        }
    }

    public function execute(array $params = []): void
    {
        if ($this->pdoStmt !== false) {
            $this->pdoStmt->execute($params);
            return;
        }
        $this->rows = $this->conn->runNative($this->sql, $params);
    }

    public function fetchAll(): array
    {
        if ($this->pdoStmt !== false) {
            return $this->pdoStmt->fetchAll();
        }
        return $this->rows;
    }
}