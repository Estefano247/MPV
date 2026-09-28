<?php

declare(strict_types=1);

require_once __DIR__ . '/PdfBuilder.php';
require_once __DIR__ . '/DataProtector.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ObservacionRepository.php';
require_once __DIR__ . '/SubmissionRepositoryInterface.php';

/**
 * El Acuse de Recibo: huella de verificación (HMAC-SHA256) y documento que se
 * entrega al_presentante (HTML-ready + PDF descargable).
 *
 * Los datos de los expedientes son de SubmissionRepository; los correlativos,
 * de CorrelativoRepository; las áreas, de AreaRepository. Aquí no queda SQL.
 */
final class AcuseService
{
    public const ESTADOS = [
        'pendiente'   => 'Pendiente',
        'en_revision' => 'En revisión',
        'observado'   => 'Con observación',
        'aprobado'    => 'Aprobado',
        'denegado'    => 'Denegado',
    ];

    public static function config(): array
    {
        return require __DIR__ . '/config.php';
    }

    public static function hash(string $submissionId, string $nroCargo): string
    {
        $secret = (string) (self::config()['auth']['jwtSecret'] ?? 'mpv-secret');
        return hash_hmac('sha256', "{$submissionId}|{$nroCargo}", $secret);
    }

    public static function verificarHash(string $probed, ?string $stored): bool
    {
        return $stored !== null && $stored !== '' && hash_equals($stored, $probed);
    }

    /**
     * Datos completos de un acuse, con el asunto descifrado y el conteo de adjuntos.
     *
     * @return array<string,mixed>|null
     */
    public static function datosAcuse(SubmissionRepositoryInterface $repo, string $submissionId): ?array
    {
        $row = $repo->porId($submissionId);
        if ($row === null) {
            return null;
        }

        return [
            'submission_id' => (string) $row['submission_id'],
            'tipo' => (string) $row['type'],
            'nombre' => (string) $row['name'],
            'email' => (string) $row['email'],
            'dni' => (string) $row['dni'],
            'telefono' => (string) $row['telefono'],
            'descripcion' => DataProtector::decrypt((string) $row['description']),
            'nro_cargo' => (string) $row['nro_cargo'],
            'nro_expediente' => (string) $row['nro_expediente'],
            'acuse_hash' => (string) $row['acuse_hash'],
            'acuse_at' => (string) $row['acuse_at'],
            'status' => (string) $row['status'],
            'area_actual' => (string) $row['area_actual'],
            'archivos' => $repo->contarArchivos($submissionId),
        ];
    }

    /**
     * Datos del acuse de una subsanación.
     *
     * Reúne en una sola estructura lo que muestran el acuse en HTML y el PDF: los
     * datos de la subsanación, los números del expediente que se subsana y la
     * observación a la que responde.
     *
     * @return array<string,mixed>|null
     */
    public static function datosAcuseSubsanacion(
        ObservacionRepositoryInterface $observaciones,
        SubmissionRepositoryInterface $submissions,
        string $subsanacionId
    ): ?array {
        $row = $observaciones->subsanacionPorId($subsanacionId);
        if ($row === null) {
            return null;
        }

        return [
            'es_subsanacion' => true,
            'subsanacion_id' => (string) $row['id'],
            'submission_id' => (string) $row['submission_id'],
            'nombre' => (string) $row['nombre'],
            'email' => (string) $row['email'],
            'dni' => (string) $row['dni'],
            'telefono' => (string) $row['telefono'],
            'descripcion' => (string) $row['descripcion'],
            'nro_cargo' => (string) $row['nro_cargo'],
            'nro_cargo_expediente' => (string) ($row['nro_cargo_expediente'] ?? ''),
            'nro_expediente' => (string) $row['nro_expediente'],
            'acuse_hash' => (string) $row['acuse_hash'],
            'acuse_at' => (string) $row['created_at'],
            'status' => (string) $row['status'],
            'area_actual' => (string) ($row['area_actual'] ?? ''),
            'archivos' => $submissions->contarArchivosDeSubsanacion((string) $row['id']),
            'observacion' => (string) ($row['observacion_detalle'] ?? ''),
            'observacion_limite' => (string) ($row['observacion_fecha_limite'] ?? ''),
        ];
    }

    /** Construye el PDF del acuse para descarga. */
    public static function generarAcusePdf(array $a): string
    {
        $cfg = self::config()['mpv'];

        $fields = [
            ['label' => 'N' . 'o de cargo', 'value' => $a['nro_cargo']],
            ['label' => 'N' . 'o de expediente', 'value' => $a['nro_expediente']],
            ['label' => 'Fecha y hora de recepción', 'value' => self::formatearFecha((string) $a['acuse_at'])],
            ['label' => 'Remitente', 'value' => (string) $a['nombre'], 'wrap' => true],
            ['label' => 'D.N.I.', 'value' => (string) $a['dni']],
            ['label' => 'Correo electrónico', 'value' => (string) $a['email'], 'wrap' => true],
            ['label' => 'Teléfono', 'value' => (string) $a['telefono']],
            ['label' => 'Tipo de trámite', 'value' => self::labelTipo((string) $a['tipo'])],
            ['label' => 'Asunto', 'value' => (string) $a['descripcion'], 'wrap' => true],
            ['label' => 'Archivos adjuntos', 'value' => (string) $a['archivos']],
            ['label' => 'Área responsable', 'value' => (string) ($a['area_actual'] !== '' ? $a['area_actual'] : 'Mesa de Partes Virtual')],
            ['label' => 'Código de verificación', 'value' => substr((string) $a['acuse_hash'], 0, 20)],
        ];

        $legal = 'El presente acuse acredita la recepción de la solicitud y sus documentos adjuntos en la Mesa de '
            . 'Partes Virtual de la ' . (string) $cfg['apex'] . '. Su contenido se genera de manera automática '
            . 'al momento de la presentación; por ello, conserve una copia y utilice el código de verificación '
            . 'para validar su autenticidad en la plataforma web.';

        return self::documento('ACUSE DE RECIBO', 'Recibo emitido automáticamente por la plataforma web', $fields, $legal);
    }

    /**
     * Acuse de la subsanación.
     *
     * Misma maqueta que el acuse del alta, con los datos propios de la
     * subsanación y, además, el expediente que se subsana y la observación a la
     * que responde: es lo que permite identificar de un vistazo a qué
     * requerimiento pertenece lo entregado.
     */
    public static function generarAcuseSubsanacionPdf(array $a): string
    {
        $cfg = self::config()['mpv'];

        $limite = (string) ($a['observacion_limite'] ?? '');
        $observacion = (string) ($a['observacion'] ?? '');

        $fields = [
            ['label' => 'N' . 'o de cargo (subsanación)', 'value' => $a['nro_cargo']],
            ['label' => 'Expediente que se subsana', 'value' => (string) ($a['nro_cargo_expediente'] !== '' ? $a['nro_cargo_expediente'] : $a['nro_expediente'])],
            ['label' => 'N' . 'o de expediente', 'value' => $a['nro_expediente']],
            ['label' => 'Fecha y hora de recepción', 'value' => self::formatearFecha((string) $a['acuse_at'])],
            ['label' => 'Remitente', 'value' => (string) $a['nombre'], 'wrap' => true],
            ['label' => 'D.N.I.', 'value' => (string) $a['dni']],
            ['label' => 'Correo electrónico', 'value' => (string) $a['email'], 'wrap' => true],
            ['label' => 'Teléfono', 'value' => (string) $a['telefono']],
            ['label' => 'Observación subsanada', 'value' => $observacion !== '' ? $observacion : '—', 'wrap' => true],
            ['label' => 'Plazo de la observación', 'value' => $limite !== '' ? self::formatearFecha($limite) : '—'],
            ['label' => 'Descripción de la subsanación', 'value' => (string) $a['descripcion'], 'wrap' => true],
            ['label' => 'Archivos adjuntos', 'value' => (string) $a['archivos']],
            ['label' => 'Área responsable', 'value' => (string) ($a['area_actual'] !== '' ? $a['area_actual'] : 'Mesa de Partes Virtual')],
            ['label' => 'Código de verificação', 'value' => substr((string) $a['acuse_hash'], 0, 20)],
        ];

        $legal = 'El presente acuse acredita la recepción de la subsanación y sus documentos adjuntos en la Mesa de '
            . 'Partes Virtual de la ' . (string) $cfg['apex'] . '. La subsanación será verificada por el área '
            . 'responsable del expediente y su aceptación o rechazo consta en el mismo. Conserve una copia y '
            . 'utilice el código de verificación para validar su autenticidad en la plataforma web.';

        return self::documento(
            'ACUSE DE SUBSANACIÓN',
            'Subsanación de observación registrada automáticamente por la plataforma web',
            $fields,
            $legal
        );
    }

    /**
     * Maqueta común de los acuses: encabezado institucional, recuadro de datos y
     * pie. Solo cambian el título, los campos y el texto legal, para que el acuse
     * de la subsanación no sea una copia que se desincronice del original.
     *
     * @param array<int, array{label:string,value:string,wrap?:bool}> $fields
     */
    private static function documento(string $titulo, string $subtitulo, array $fields, string $legal): string
    {
        $cfg = self::config()['mpv'];
        $p = new PdfBuilder();

        $y = 36.0;
        $m = 42.0;
        $w = PdfBuilder::PAGE_W - 2 * $m;

        // Encabezado institucional
        $p->text($m, $y, strtoupper((string) $cfg['titulo']), true, 13);
        $y += 16;
        $p->text($m, $y, strtoupper((string) $cfg['apex']), true, 9);
        $y += 12;
        $p->text($m, $y, 'Directiva de creación: ' . (string) $cfg['numero'], false, 9);
        $y += 12;

        // Título del documento dentro de un recuadro
        $p->text($m + $w / 2 - $p->textWidth($titulo, 14) / 2, $y, $titulo, true, 14);
        $y += 20;
        $p->text($m + $w / 2 - $p->textWidth($subtitulo, 9) / 2, $y, $subtitulo, false, 9);
        $y += 14;

        // Caja de datos
        $top = $y;
        $rowH = 17.0;

        foreach ($fields as $f) {
            $p->rect($m, $top, $m + $w, $top + $rowH);
            $p->text($m + 6, $top + 5, $f['label'], true, 8.5);
            if (!empty($f['wrap'])) {
                $size = 8.5;
                $lines = $p->wrap((string) $f['value'], $w - 120, $size);
                $maxLines = 3;
                $lines = array_slice($lines, 0, $maxLines);
                $t = $top + 20;
                foreach ($lines as $line) {
                    $p->text($m + 112, $t, $line, false, $size);
                    $t += 11;
                }
            } else {
                $p->text($m + 112, $top + 5, (string) $f['value'], false, 8.5);
            }
            $top += $rowH;
        }

        $y = $top + 16;

        // Pie legal
        $lineH = 10.5;
        foreach ($p->wrap($legal, $w, 8.5) as $line) {
            $p->text($m, $y, $line, false, 8.5);
            $y += $lineH;
        }
        $y += 8;

        // Responsable + pie
        $p->text($m, $y, 'Órgano responsable: ' . (string) $cfg['responsable'], false, 8.5);
        $y += 12;
        $p->text($m, $y, 'Firma responsable: ' . (string) $cfg['responsableNombre'], false, 8.5);
        $y += 22;
        $p->line($m + 40, $y, $m + 160, $y);
        $y += 10;
        $p->text($m + 40, $y, 'Firma y sello', false, 8);

        $p->text($m + $w / 2 - $p->textWidth('Plataforma web oficial de la Mesa de Partes Virtual', 7.5) / 2, PdfBuilder::PAGE_H - 26, 'Plataforma web oficial de la Mesa de Partes Virtual', false, 7.5);

        return $p->save();
    }

    public static function labelTipo(string $tipo): string
    {
        return match ($tipo) {
            'afiliacion' => 'Solicitud de Afiliación',
            'pre-evaluacion' => 'Pre-evaluación crediticia',
            'credito' => 'Solicitud de Crédito',
            'mpv' => 'Trámite / Documento (Mesa de Partes)',
            'auxilio-retiro' => 'Auxilio por Retiro',
            'auxilio-invalidez' => 'Auxilio por Invalidez',
            'seguro-sepelio' => 'Seguro de Sepelio Familiar',
            'prestamo-solidario' => 'Préstamo Solidario',
            'auxilio-fallecimiento' => 'Auxilio por Fallecimiento',
            default => ucfirst((string) $tipo),
        };
    }

    public static function formatearFecha(string $fecha): string
    {
        try {
            $dt = new DateTimeImmutable($fecha);
            return $dt->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'America/Lima'))->format('d/m/Y H:i:s');
        } catch (Throwable) {
            return e($fecha);
        }
    }
}