<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Cualquier aviso, warning o deprecación hace fallar la suite: en un proyecto sin
// CI, los errores silenciosos se cuelan hasta producción.
set_error_handler(static function (int $n, string $msg, string $file, int $line): bool {
    fwrite(STDERR, "\nPHP error/notice: $msg in $file:$line\n");
    exit(1);
});

$archivos = glob(__DIR__ . '/*Test.php') ?: [];
sort($archivos);

if ($archivos === []) {
    fwrite(STDERR, "No hay tests en " . __DIR__ . "\n");
    exit(1);
}

require_once __DIR__ . '/T.php';

echo "Suite: solicitudes\n" . str_repeat('=', 52) . "\n";

$archivos = glob(__DIR__ . '/*Test.php') ?: [];
sort($archivos);

if ($archivos === []) {
    fwrite(STDERR, "No hay tests en " . __DIR__ . "\n");
    exit(1);
}

foreach ($archivos as $archivo) {
    echo "\n>>> " . basename($archivo) . "\n";
    require $archivo;
}

exit(T::resumen());
