<?php

declare(strict_types=1);

/**
 * Entrada inválida de una operación de negocio.
 *
 * Transporta la lista completa de errores para que el endpoint la devuelva
 * tal cual (el cliente los muestra juntos) en lugar de perder todos menos el
 * primero.
 */
final class ValidationException extends RuntimeException
{
    /** @var string[] */
    private array $errores;

    /**
     * @param string[] $errores
     */
    public function __construct(array $errores, string $mensaje = 'Error de validación')
    {
        parent::__construct($mensaje);
        $this->errores = array_values($errores);
    }

    /**
     * @return string[]
     */
    public function errores(): array
    {
        return $this->errores;
    }
}
