<?php

declare(strict_types=1);

/**
 * Escape seguro para HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normaliza un número de teléfono (9 dígitos).
 */
function normalizarTelefono(mixed $value): ?string
{
    $tel = preg_replace('/\D/', '', (string) $value);
    return preg_match('/^\d{9}$/', $tel) ? $tel : null;
}

/**
 * Formatea una fecha a dd/mm/yyyy de forma segura.
 */
function formatFecha(?string $fecha): string
{
    if (!$fecha) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($fecha);
        if ((int) $dt->format('Y') < 1900) {
            return '—';
        }
        return $dt->format('d/m/Y');
    } catch (Throwable) {
        return '—';
    }
}

/**
 * Formatea un número monetario (S/).
 */
function formatMonto(mixed $valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    if (!is_numeric($valor)) {
        return e((string) $valor);
    }
    return 'S/ ' . number_format((float) $valor, 2, '.', ',');
}
