<?php

declare(strict_types=1);

/**
 * Asserts mínimos y un runner. El proyecto no tiene Composer ni PHPUnit, así que
 * la suite es PHP plano: se ejecuta con `php tests/run.php` y sale con código 1
 * si algo falla.
 */
final class T
{
    private static int $ok = 0;
    private static int $fallos = 0;
    private static string $grupo = '';

    public static function grupo(string $nombre): void
    {
        self::$grupo = $nombre;
        echo "\n$nombre\n";
    }

    public static function igual(mixed $got, mixed $want, string $que): void
    {
        if ($got === $want) {
            self::$ok++;
            echo "  ok    $que\n";
            return;
        }
        self::$fallos++;
        echo "  FALLO $que\n";
        echo '         got:  ' . self::dump($got) . "\n";
        echo '         want: ' . self::dump($want) . "\n";
    }

    public static function cierto(bool $cond, string $que): void
    {
        self::igual($cond, true, $que);
    }

    public static function igualesEnOrden(array $got, array $want, string $que): void
    {
        self::igual(array_values($got), array_values($want), $que);
    }

    public static function lanza(string $clase, callable $fn, string $que): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            self::igual($e::class, $clase, $que);
            return;
        }
        self::igual('no lanzó', $clase, $que);
    }

    public static function resumen(): int
    {
        $total = self::$ok + self::$fallos;
        echo "\n" . str_repeat('-', 52) . "\n";
        printf("%d/%d OK%s\n", self::$ok, $total, self::$fallos > 0 ? sprintf(', %d FALLO(S)', self::$fallos) : '');
        return self::$fallos === 0 ? 0 : 1;
    }

    private static function dump(mixed $v): string
    {
        return is_scalar($v) || $v === null
            ? var_export($v, true)
            : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
