<?php

declare(strict_types=1);

/**
 * Identificadores de expediente.
 *
 * Vive aparte para que el repositorio, la capa HTTP y los casos de uso
 * compartan la misma validación de formato en lugar de repetir el patrón.
 */
final class Uuid
{
    /** UUID v4 en minúsculas. */
    public static function v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
