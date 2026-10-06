<?php

declare(strict_types=1);

// Verificación de que el entorno tiene todo lo que el código necesita.
// CLI solamente: por HTTP no tiene que responder (filtra versiones, drivers
// y qué variables existen). Mismo guard que bin/migrate.php.
//   php bin/check-env.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$requeridas = [
    'pdo_pgsql'  => 'Database.php (ruta PDO)',
    'pgsql'      => 'Database.php (ruta nativa, respaldo)',
    'curl'       => 'S3Service y AmspApiClient (SigV4 a mano)',
    'mbstring'   => 'mb_strlen / mb_substr en acuses y vistas',
    'openssl'    => 'DataProtector y firma HMAC de S3',
    'iconv'      => 'conversión de texto en PDF',
    'json'       => 'respuestas de la API',
    'session'    => 'sesión del portal y del panel',
];

// Opcionales: si faltan, hay fallback en PHP puro (scrypt.php) o no se usan.
$opcionales = [
    'sodium'     => 'scrypt acelerado (hay fallback en PHP puro)',
    'fileinfo'   => 'validación de tipo de archivo (hoy no se usa)',
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
foreach ($opcionales as $ext => $paraQue) {
    printf("  %-12s %-6s %s\n", $ext, extension_loaded($ext) ? 'OK' : 'sin él', $paraQue);
}

echo "\nDriver de PDO: " . implode(', ', PDO::getAvailableDrivers()) . "\n";

// Carga el .env igual que la app: sin esto, las variables del archivo se
// imprimirían como "(vacio)" aunque estén bien puestas.
require_once __DIR__ . '/../includes/config.php';

echo "\nConfiguracion (.env):\n";
foreach (['DATABASE_URL', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION', 'S3_BUCKET_NAME',
          'APP_DATA_KEY', 'JWT_SECRET', 'PUBLIC_URL', 'SEED_ADMIN_USERNAME', 'SEED_STAFF_USERNAME'] as $k) {
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
