<?php

declare(strict_types=1);

/**
 * El anidamiento de transacciones vive en DbConnection (contador de profundidad)
 * pero se maneja desde SubmissionRepository::transaccion(), que es la ruta que
 * usan los casos de uso. Aquí se verifica que dos anidamientos produzcan un solo
 * BEGIN/COMMIT real, que un rollback descarte la transacción completa y que una
 * excepción no deje el contador colgado.
 *
 * El constructor de DbConnection siempre conecta, así que se inyecta un doble
 * que extiende PDO para satisfacer el tipo de la propiedad y sobrescribe los tres
 * métodos que se usan. El doble lanza si recibe una secuencia incoherente, igual
 * que haría el driver.
 */

require_once __DIR__ . '/T.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/SubmissionRepository.php';

final class FakePdo extends PDO
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $dentro = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        if ($this->dentro) {
            throw new LogicException('BEGIN con una transaccion ya abierta: habria revuelto.');
        }
        $this->begins++;
        $this->dentro = true;
        return true;
    }

    public function commit(): bool
    {
        if (!$this->dentro) {
            throw new LogicException('COMMIT sin transaccion activa: habria revuelto.');
        }
        $this->commits++;
        $this->dentro = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->dentro) {
            throw new LogicException('ROLLBACK sin transaccion activa: habria revuelto.');
        }
        $this->rollbacks++;
        $this->dentro = false;
        return true;
    }

    public function abierta(): bool
    {
        return $this->dentro;
    }
}

/** DbConnection con el PDO inyectado, saltando el constructor que exige un DSN real. */
function conexionCon(FakePdo $pdo): DbConnection
{
    $ref = new ReflectionClass(DbConnection::class);
    $db = $ref->newInstanceWithoutConstructor();

    $usando = $ref->getProperty('usingPdo');
    $usando->setAccessible(true);
    $usando->setValue($db, true);

    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue($db, $pdo);

    return $db;
}

T::grupo('Anidamiento: dos transacciones anidadas son una sola real');

$pdo = new FakePdo();
$db = conexionCon($pdo);
$repo = new SubmissionRepository($db);

$repo->transaccion(function () use ($db, $pdo): void {
    T::igual($db->inTransaction(), true, 'dentro del callback hay transaccion');

    $anidado = new SubmissionRepository($db);
    $anidado->transaccion(function () use ($db): void {
        T::igual($db->inTransaction(), true, 'el anidado tambien ve la transaccion');
    });

    T::igual($pdo->abierta(), true, 'el commit del anidado NO cierra la transaccion real');
});

T::igual($pdo->begins, 1, 'un solo BEGIN real para dos anidamientos');
T::igual($pdo->commits, 1, 'un solo COMMIT real');
T::igual($pdo->rollbacks, 0, 'ningun rollback');
T::igual($db->inTransaction(), false, 'al salir queda sin transaccion');

T::grupo('Rollback: un rollback explícito descarta toda la transacción');

$pdo = new FakePdo();
$db = conexionCon($pdo);
$repo = new SubmissionRepository($db);

$repo->transaccion(function () use ($repo, $db): void {
    $repo->transaccion(function (): void {
    });
    $db->rollBack();
    T::igual($db->inTransaction(), false, 'no queda un nivel de profundidad colgado');
});

T::igual($pdo->begins, 1, 'un solo BEGIN');
T::igual($pdo->rollbacks, 1, 'un solo ROLLBACK real');
T::igual($pdo->commits, 0, 'el commit interno no confirmo nada');
T::igual($pdo->abierta(), false, 'la transaccion real quedo cerrada');

T::grupo('Excepción: se propaga intacta y no deja la transacción colgada');

$pdo = new FakePdo();
$db = conexionCon($pdo);
$repo = new SubmissionRepository($db);

$mensaje = null;
try {
    $repo->transaccion(function () use ($repo): void {
        $repo->transaccion(function (): void {
            throw new RuntimeException('fallo al guardar');
        });
    });
} catch (RuntimeException $e) {
    $mensaje = $e->getMessage();
}

T::igual($mensaje, 'fallo al guardar', 'la excepción sube sin envolver');
T::igual($pdo->rollbacks, 1, 'hubo exactamente un rollback');
T::igual($pdo->abierta(), false, 'el rollback automático cerró la transacción');
T::igual($db->inTransaction(), false, 'y el contador quedó en cero');
T::igual($pdo->commits, 0, 'no se confirmó nada');
