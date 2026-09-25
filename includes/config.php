<?php

declare(strict_types=1);

if (!function_exists('solicitudes_env')) {
    function solicitudes_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}

// La configuración se computa una sola vez y se cachea: todos los consumidores
// (`$config = require config.php`, `AcuseService::config()`, `S3Service::config()`,
// `dashboard_config()`) devuelven la misma instancia sin reprocesar el .env.
if (!isset($GLOBALS['__solicitudes_config'])) {
    // Cargar el .env local de /solicitudes (auto-contenido) sin sobrescribir vars reales.
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                if (getenv($key) === false) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    $GLOBALS['__solicitudes_config'] = [
        'aws' => [
            'region' => solicitudes_env('AWS_REGION', 'sa-east-1'),
            'accessKeyId' => solicitudes_env('AWS_ACCESS_KEY_ID', ''),
            'secretAccessKey' => solicitudes_env('AWS_SECRET_ACCESS_KEY', ''),
        ],
        's3' => [
            'bucketName' => solicitudes_env('S3_BUCKET_NAME', ''),
        ],
        'api' => [
            'base' => solicitudes_env('AMSP_API_BASE') ?: 'https://amspweb.net/api',
            'plazaUrl' => '/?:dni=&dia=&mes=&anio=',
        ],
        'app' => [
            'base' => solicitudes_env('PUBLIC_URL') ?: '',
            'uploadUrl' => 'upload-url.php',
            'guardarUrl' => 'guardar.php',
        ],
        'database' => [
            'url' => solicitudes_env('DATABASE_URL', 'postgresql://amspwebn_dbfiles_user:password@localhost:5432/amspwebn_dbarchivos'),
        ],
        'session' => [
            'key' => 'amsp_cliente_dni',
            'lifetime' => 3600,
        ],
        'auth' => [
            // Panel administrativo (admin/): cookie JWT httpOnly
            'cookieName' => 'dashboard_token',
            'jwtSecret' => solicitudes_env('JWT_SECRET', ''),
            'sessionDurationHours' => (int) solicitudes_env('SESSION_DURATION_HOURS', 24),
        ],
        'seed' => [
            'adminUsername' => (string) solicitudes_env('SEED_ADMIN_USERNAME', '@dm1n'),
            'adminPassword' => (string) solicitudes_env('SEED_ADMIN_PASSWORD', ''),
            'adminRole' => (string) solicitudes_env('SEED_ADMIN_ROLE', 'super-admin'),
        ],
        'data' => [
            // Clave de cifrado de datos personales en reposo (hex de 32 bytes, AES-256-GCM).
            'key' => (string) solicitudes_env('APP_DATA_KEY', ''),
        ],
        'mpv' => [
            // Datos institucionales de la Mesa de Partes Virtual
            'numero' => (string) solicitudes_env('MPV_NUMERO', 'MPV-DIR-001-2026'),
            'titulo' => (string) solicitudes_env('MPV_TITULO', 'Mesa de Partes Virtual'),
            'responsable' => (string) solicitudes_env('MPV_RESPONSABLE', 'Unidad de Tecnologías de la Información'),
            'responsableNombre' => (string) solicitudes_env('MPV_RESPONSABLE_NOMBRE', 'Jefe/a de Mesa de Partes'),
            'correo' => (string) solicitudes_env('MPV_CORREO', 'mesadepartes@amsp.org.pe'),
            'telefono' => (string) solicitudes_env('MPV_TELEFONO', '(01) 331-0083 / 424-3262'),
            'horario' => (string) solicitudes_env('MPV_HORARIO', 'Lunes a viernes de 8:00 a 17:00 h'),
            'apex' => (string) solicitudes_env('MPV_APEX', 'Asociación Mutualista Sanitaria del Perú (AMSP)'),
            'direccion' => (string) solicitudes_env('MPV_DIRECCION', 'Jr. Ramón Dagnino 117, Santa Beatriz, Cercado de Lima'),
        ],
    ];
}

return $GLOBALS['__solicitudes_config'];