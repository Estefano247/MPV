<?php

declare(strict_types=1);

/**
 * Router del servidor embebido de PHP (`php -S`).
 *
 * Sustituye al vhost de Apache: la app no separa público de privado (todo el
 * código está en el docroot), así que esta es la única barrera que impide servir
 * includes/, tests/, el esquema, el .env, etc. Devuelve 403 exactamente igual
 * que lo hacía `docker/apache-vhost.conf`.
 *
 * Vive FUERA del docroot (en /usr/local/bin) para que ni siquiera se pueda pedir
 * por URL. Cuando una petición no está bloqueada devuelve `false` y el servidor
 * la sirve como siempre: ejecuta los .php y entrega los estáticos.
 */

// --- Rutas y archivos que no son públicos ---------------------------------
$prefijos = [
    '/includes/',
    '/docs/',
    '/tests/',
    '/bin/',
    '/docker/',
    '/vendor/',
    '/.git/',
    '/storage/',
    '/uploads/',
];

$archivosOcultos = [
    '.env',
    '.htaccess',
    '.gitignore',
    '.gitattributes',
    'thumbs.db',
    'desktop.ini',
];

// Documentos, esquemas, logs y configuraciones: no son páginas (mismo listado
// que el <FilesMatch> del vhost de Apache).
$extensionesBloqueadas = ['sql', 'md', 'log', 'ini', 'lock', 'dist', 'bak', 'swp'];

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$ruta = rawurldecode($ruta);

// Normaliza: resuelve ../ y // para que un includes/../includes no se cuele.
$ruta = '/' . ltrim(preg_replace('#/+#', '/', $ruta), '/');
$partes = [];
foreach (explode('/', $ruta) as $parte) {
    if ($parte === '' || $parte === '.') {
        continue;
    }
    if ($parte === '..') {
        array_pop($partes);
        continue;
    }
    $partes[] = $parte;
}
$ruta = '/' . implode('/', $partes);

$bloqueada = false;

foreach ($prefijos as $prefijo) {
    if (str_starts_with($ruta, $prefijo)) {
        $bloqueada = true;
        break;
    }
}

// /algo.env o /.env.local
$archivo = basename($ruta);
if (in_array(strtolower($archivo), $archivosOcultos, true) || str_starts_with(strtolower($archivo), '.env')) {
    $bloqueada = true;
}

$extension = strtolower((string) pathinfo($archivo, PATHINFO_EXTENSION));
if (in_array($extension, $extensionesBloqueadas, true)) {
    $bloqueada = true;
}

if ($bloqueada) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403\n";
    return true;
}

// No bloqueada: el servidor embebido la sirve (ejecuta .php, entrega estáticos).
return false;
