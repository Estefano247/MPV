<?php

declare(strict_types=1);

/**
 * Un solo header y un solo footer para todas las vistas públicas.
 *
 * Antes cada página repetía su propio <header>/<footer>, y como cada copia se
 * editó por separado terminaron divergiendo: Subtítulos distintos, anchos
 * distintos (max-w-5xl vs max-w-6xl), y conjuntos de enlaces distintos según la
 * página. Eso hace que la barra cambie al navegar, que es justo lo que un
 * cabecera institucional no debe hacer.
 *
 * Acá vive la única copia. Las páginas piden View::head()/header()/footer() y no
 * tocan el marcado. Lo único que varía por página es el título del documento y
 * qué enlace del menú queda marcado como actual (aria-current), que es
 * información, no diseño.
 */
final class View
{
    /**
     * Enlaces del menú. El mismo conjunto en todas las vistas: si una página
     * necesita un acceso nuevo, se agrega acá y aparece en todas.
     *
     * @var list<array{clave: string, texto: string, ruta: string}>
     */
    private const MENU = [
        ['clave' => 'mpv', 'texto' => 'Mesa de Partes', 'ruta' => 'mpv/'],
        ['clave' => 'portal', 'texto' => 'Portal del Asociado', 'ruta' => 'login.php'],
        ['clave' => 'seguimiento', 'texto' => 'Seguimiento', 'ruta' => 'seguimiento.php'],
        ['clave' => 'calculadora', 'texto' => 'Calculadora', 'ruta' => 'index.php'],
    ];

    /**
     * CSP único. Es la unión de las directivas más estrictas que ya usaba cada
     * página: form-action y frame-src solo hacían falta en algunas vistas, pero
     * dejarlos fuera hacía que dos vistas tuvieran políticas distintas.
     *
     * frame-src lleva blob: (previsualización del PDF en la MPV) y el bucket de
     * S3 (archivos enviados en el Portal): quitar cualquiera de los dos rompe una
     * vista en silencio, porque el fallo se ve dentro de un iframe.
     */
    private const CSP = "default-src 'self'; "
        . "script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; "
        . "style-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; "
        . "img-src 'self' data: blob: https:; "
        . "frame-src blob: https://*.s3.sa-east-1.amazonaws.com; "
        . "connect-src 'self' https://*.s3.sa-east-1.amazonaws.com; "
        . "form-action 'self'";

    /**
     * Deduce el prefijo web de la aplicación a partir de la ruta del script.
     *
     * $scriptName es la ruta URL del script en ejecución (SCRIPT_NAME) y
     * $relativo es, con barras, qué subdirectorio de la aplicación ocupa
     * ('', '/mpv', ...). Ese dato sale del disco, no de la URL: son las dos
     * piezas que hacen falta, porque la URL por sí sola no distingue entre
     * "/mpv/index.php" de una app en la raíz y una app montada en /mpv/.
     *
     * Así el mismo marcado del menú sirve desde la raíz y desde mpv/ sin
     * duplicarlo con "mpv/" y "../", y tampoco rompe si la aplicación se
     * publica dentro de un subdirectorio.
     */
    public static function derivarBase(string $scriptName, string $relativo = ''): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $relativo = '/' . trim(str_replace('\\', '/', $relativo), '/');

        // El script está en la raíz de la aplicación: el base es su directorio.
        if ($relativo === '/') {
            return $dir;
        }

        // Se le quita al directorio URL el mismo sufijo que ocupa en disco.
        if (str_ends_with($dir, $relativo)) {
            return substr($dir, 0, strlen($dir) - strlen($relativo));
        }

        // No se reconoce el sufijo: se asume que el directorio es el base.
        return $dir;
    }

    /**
     * Subdirectorio de la aplicación donde vive el script actual, en disco.
     */
    private static function relativoEnDisco(): string
    {
        $scriptFs = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($scriptFs === '') {
            return '';
        }

        $appRaiz = dirname(__DIR__);
        $dirFs = dirname($scriptFs);

        // Se comparan normalizados: en Windows la ruta puede venir en otra caja.
        $pos = stripos($dirFs, $appRaiz);
        if ($pos !== 0) {
            return '';
        }

        return substr($dirFs, strlen($appRaiz));
    }

    /**
     * Base web de la aplicación, sin barra final ('' en la raíz).
     */
    public static function base(): string
    {
        static $base = null;

        if ($base !== null) {
            return $base;
        }

        $configurado = solicitudes_env('APP_BASE_PATH', null);
        if (is_string($configurado) && trim($configurado) !== '') {
            return $base = '/' . trim($configurado, '/');
        }

        return $base = self::derivarBase(
            (string) ($_SERVER['SCRIPT_NAME'] ?? '/'),
            self::relativoEnDisco()
        );
    }

    /**
     * URL absoluta dentro de la aplicación.
     */
    public static function url(string $ruta = ''): string
    {
        $base = self::base();

        if ($ruta === '') {
            return $base === '' ? '/' : $base . '/';
        }

        return $base . '/' . ltrim($ruta, '/');
    }

    /**
     * Abre el documento y emite el header institucional.
     *
     * @param array{mpv: array<string, string>} $config
     */
    public static function head(string $titulo, array $config, string $actual = ''): void
    {
        $mpv = $config['mpv'] ?? [];
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo) ?></title>
    <meta http-equiv="Content-Security-Policy" content="<?= e(self::CSP) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <header class="bg-blue-950 text-white">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <a href="<?= e(self::url()) ?>" class="flex items-center gap-2">
                <span class="font-bold text-lg">AMSP</span>
                <span class="text-blue-200 text-sm hidden sm:inline"><?= e((string) ($mpv['apex'] ?? 'AMSP')) ?></span>
            </a>
            <nav class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <?php foreach (self::MENU as $item):
                    $activo = $actual === $item['clave'];
                    $aria = $activo ? ' aria-current="page"' : '';
                    ?>
                    <a href="<?= e(self::url($item['ruta'])) ?>" class="text-blue-200 hover:text-white transition-colors<?= $activo ? ' font-semibold text-white' : '' ?>"<?= $aria ?>><?= e($item['texto']) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
        <?php
    }

    /**
     * Cierra el contenedor de main y emite el footer institucional.
     *
     * @param array{mpv: array<string, string>} $config
     */
    public static function footer(array $config): void
    {
        $mpv = $config['mpv'] ?? [];
        ?>
        </div>
    </main>

    <footer class="bg-blue-950 text-gray-300">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 text-center text-xs">
            <p><?= e((string) ($mpv['direccion'] ?? '')) ?></p>
            <p class="mt-1"><?= e((string) ($mpv['horario'] ?? '')) ?> &middot; <?= e((string) ($mpv['telefono'] ?? '')) ?> &middot; <?= e((string) ($mpv['correo'] ?? '')) ?></p>
            <p class="mt-1 text-gray-400">&copy; <?= date('Y') ?> <?= e((string) ($mpv['apex'] ?? '')) ?></p>
        </div>
    </footer>
        <?php
    }

    /**
     * Cierra el documento. Se llama después de los scripts propios de la página.
     */
    public static function fin(): void
    {
        echo "\n</body>\n</html>\n";
    }
}
