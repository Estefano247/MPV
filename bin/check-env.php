<?php

// Verificación de que la imagen tiene todo lo que el código necesita.
// Se ejecuta dentro del contenedor:  docker compose exec -T app php /var/www/html/bin/check-env.php

$requeridas = [
    'pdo_pgsql'  => 'Database.php (ruta PDO)',
    'pgsql'      => 'Database.php (ruta nativa, respaldo)',
    'curl'       => 'S3Service y AmspApiClient (SigV4 a mano)',
    'mbstring'   => 'mb_strlen / mb_substr en acuses y vistas',
    'sodium'     => 'scrypt acelerado (hay fallback en PHP puro)',
    'openssl'    => 'DataProtector y firma HMAC de S3',
    'iconv'      => 'conversión de texto en PDF',
    'fileinfo'   => 'detección de tipo de archivo',
    'json'       => 'respuestas de la API',
    'session'    => 'sesión del portal y del panel',
];

echo "PHP " . PHP_VERSION . "\n\n";

$faltan = [];
foreach ($requeridas as $ext => $paraQue) {
    $ok = extension_loaded($ext);
    if (!$ok) {
        $faltan[] = $ext;
    }
    printf("  %-12s %-6s %s\n", $ext, $ok ? 'OK' : 'FALTA', $paraQue);
}

echo "\nDriver de PDO: " . implode(', ', PDO::getAvailableDrivers()) . "\n";

echo "\nConfiguracion:\n";
foreach (['DATABASE_URL', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION', 'S3_BUCKET_NAME',
          'APP_DATA_KEY', 'JWT_SECRET', 'SEED_ADMIN_USERNAME'] as $k) {
    $v = getenv($k);
    if ($v === false || $v === '') {
        printf("  %-22s (vacio)\n", $k);
        continue;
    }
    // No se imprime el valor: solo si viene y de qué largo.
    printf("  %-22s definido (%d chars)\n", $k, strlen($v));
}

if ($faltan !== []) {
    echo "\nFALTAN EXTENSIONES: " . implode(', ', $faltan) . "\n";
    exit(1);
}

echo "\nTodo presente.\n";
exit(0);
