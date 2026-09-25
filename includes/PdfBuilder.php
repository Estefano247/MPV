<?php

declare(strict_types=1);

/**
 * Generador PDF mínimo y autocontenido (sin dependencias externas).
 *
 * Usa las fuentes base Type1 Courier (WinAnsi) que todo visor de PDF
 * conoce, por lo que no es necesario incrustar fuentes. Pensado para el
 * Acuse de Recibo de la Mesa de Partes Virtual (una sola página).
 */
final class PdfBuilder
{
    public const PAGE_W = 595.28;  // A4
    public const PAGE_H = 841.89;

    private string $fonts = '';
    private float $fontSize = 10;

    /** @var string[] */
    private array $ops = [];

    public function setFontSize(float $size): void
    {
        $this->fontSize = $size;
    }

    /**
     * Texto en una posición (y = desde arriba). Si $bold es true usa Courier-Bold.
     */
    public function text(float $x, float $y, string $text, bool $bold = false, ?float $size = null): void
    {
        $size = $size ?? $this->fontSize;
        $font = $bold ? '/F2' : '/F1';
        $escaped = self::escape(self::toLatin1($text));
        $pdfY = self::PAGE_H - $y;
        $this->ops[] = "BT {$font} {$size} Tf {$x} {$pdfY} Td ({$escaped}) Tj ET";
    }

    /**
     * Línea horizontal. (x1,y1)-(x2,y2), y desde arriba.
     */
    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $Y1 = self::PAGE_H - $y1;
        $Y2 = self::PAGE_H - $y2;
        $this->ops[] = sprintf('%s %s m %s %s l S', self::n($x1), self::n($Y1), self::n($x2), self::n($Y2));
    }

    /**
     * Rectángulo (contorno). x1,y1 superior izquierdo; x2,y2 inferior derecho.
     */
    public function rect(float $x1, float $y1, float $x2, float $y2): void
    {
        $Y1 = self::PAGE_H - $y1;
        $Y2 = self::PAGE_H - $y2;
        $this->ops[] = sprintf('%s %s %s %s re S', self::n($x1), self::n($Y2), self::n($x2 - $x1), self::n($y1 - $y2));
    }

    /**
     * Ancho aproximado de un texto (Courier: 0.6 * tamaño por carácter).
     */
    public function textWidth(string $text, float $size): float
    {
        return strlen($text) * 0.6 * $size;
    }

    /**
     * Divide el texto en líneas que no superen $maxWidth.
     *
     * @return string[]
     */
    public function wrap(string $text, float $maxWidth, float $size): array
    {
        $words = preg_split('/\s+/u', trim($text));
        if ($words === false || $words === []) {
            return [''];
        }
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($candidate) * 0.6 * $size <= $maxWidth || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * @return string El PDF completo.
     */
    public function save(): string
    {
        $stream = $this->fonts . implode("\n", $this->ops) . "\n";

        $objects = [];
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";
        $objects[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_W . ' ' . self::PAGE_H
            . "] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>\nendobj";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>\nendobj";
        $objects[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>\nendobj";
        $objects[6] = "6 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream\nendobj";

        $offset = 0;
        $offsets = [];
        $pdf = "%PDF-1.4\n";
        for ($i = 1; $i <= 6; $i++) {
            $offsets[$i] = $offset;
            $pdf .= $objects[$i] . "\n";
            $offset = strlen($pdf);
        }

        $xrefStart = $offset;
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }

    /** Convierte UTF-8 a ISO-8859-1 (WinAnsi) con transliteración. */
    public static function toLatin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        return $converted === false ? $text : $converted;
    }

    public static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    public function __construct()
    {
        $this->fonts = '';
    }
}