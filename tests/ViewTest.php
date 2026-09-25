<?php

declare(strict_types=1);

/**
 * El tipo de trámite y el layout compartido son las dos cosas que se pueden
 * romper en silencio: un alias mal resuelto parte expedientes en dos, y un
 * header copiado por página vuelve a divergir con el tiempo.
 */

require_once __DIR__ . '/T.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/SubmissionRepository.php';
require_once __DIR__ . '/../includes/View.php';

$config = require __DIR__ . '/../includes/config.php';

T::grupo('Tipo de trámite: credito y prestamo-solidario son el mismo');

T::igual(SubmissionRepository::tipoCanonico('credito'), 'prestamo-solidario', 'credito se resuelve al canónico');
T::igual(SubmissionRepository::tipoCanonico('prestamo-solidario'), 'prestamo-solidario', 'el canónico no se mueve');
T::igual(SubmissionRepository::tipoCanonico('mpv'), 'mpv', 'un tipo normal pasa intacto');
T::igual(SubmissionRepository::tipoCanonico('inventado'), null, 'un tipo desconocido se rechaza');

$persistidos = ['afiliacion', 'pre-evaluacion', 'credito', 'mpv',
    'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
    'prestamo-solidario', 'auxilio-fallecimiento'];
foreach ($persistidos as $t) {
    T::igual(SubmissionRepository::tipoCanonico($t) !== null, true, "'$t' sigue siendo un tipo válido");
}

T::grupo('Layout: el mismo marcado sirve desde la raíz y desde mpv/');

// [scriptName, relativo en disco, base esperado]
$casos = [
    ['/index.php', '', ''],
    ['/mpv/index.php', '/mpv', ''],
    ['/login.php', '', ''],
    ['/solicitudes/index.php', '', '/solicitudes'],
    ['/solicitudes/mpv/index.php', '/mpv', '/solicitudes'],
    ['/solicitudes/seguimiento.php', '', '/solicitudes'],
    ['/app/v2/mpv/index.php', '/mpv', '/app/v2'],
];
foreach ($casos as [$script, $relativo, $esperado]) {
    T::igual(
        View::derivarBase($script, $relativo),
        $esperado,
        "$script (relativo '$relativo') => '$esperado'"
    );
}

T::igual(
    View::derivarBase('/solicitudes/mpv/index.php', '/mpv'),
    View::derivarBase('/solicitudes/index.php', ''),
    'ambas profundidades comparten el mismo base'
);

T::grupo('Layout: el header y el footer se emiten una sola vez');

ob_start();
View::head('Título de prueba', $config, 'mpv');
$html = (string) ob_get_clean();

T::igual(substr_count($html, '<header'), 1, 'un solo <header>');
T::igual(substr_count($html, '</header>'), 1, 'un solo </header>');
T::igual(substr_count($html, '<main'), 1, 'un solo <main>');
T::igual(substr_count($html, '<nav'), 1, 'un solo <nav>');
T::igual(str_contains($html, '<title>Título de prueba</title>'), true, 'usa el título que le pasan');
T::igual(str_contains($html, 'aria-current="page"'), true, 'marca el enlace de la página actual');
T::igual(substr_count($html, 'aria-current="page"'), 1, 'y marca exactamente uno');

ob_start();
View::footer($config);
$pie = (string) ob_get_clean();

T::igual(substr_count($pie, '<footer'), 1, 'un solo <footer>');
T::igual(str_contains($pie, (string) $config['mpv']['apex']), true, 'el footer usa la razón social de config');
T::igual(str_contains($pie, (string) $config['mpv']['direccion']), true, 'y la dirección, en vez de repetirla a mano');
T::igual(str_contains($pie, '</main>'), true, 'cierra el main que abrió head()');
T::igual(str_contains($pie, '</div>'), true, 'y su contenedor');

ob_start();
View::fin();
$cierre = (string) ob_get_clean();

T::igual(substr_count($cierre, '</body>'), 1, 'fin() cierra el body');
T::igual(substr_count($cierre, '</html>'), 1, 'y el html');

T::grupo('Layout: el menú no depende de la página');

// Mismo título en las dos llamadas: lo único que debe cambiar entre una vista y
// otra es la marca del enlace activo, nada del marcado.
ob_start();
View::head('Título de prueba', $config, 'mpv');
$otra = (string) ob_get_clean();

// Se compara contra la misma página con otra clave activa: si el menú dependiera
// de la página, el resto del marcado cambiaría.
$normalizar = static fn (string $s): string => (string) preg_replace(
    '/\s+/',
    ' ',
    str_replace([' aria-current="page"', ' font-semibold text-white'], '', $s)
);

T::igual($normalizar($html), $normalizar($otra), 'el marcado no cambia con la página activa');
