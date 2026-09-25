<?php

declare(strict_types=1);

require_once __DIR__ . '/S3Service.php';

/**
 * Almacenamiento de los adjuntos de las solicitudes.
 *
 * La interfaz existe porque la verificación de tamaño no puede depender de una
 * llamada de red: SolicitudService la recibe inyectada y en pruebas se le pasa
 * un doble. S3Storage delega en S3Service, que es quien habla con AWS.
 */
interface StorageInterface
{
    /**
     * Tamaño real del objeto en bytes, o null si no existe.
     */
    public function objectSize(string $key): ?int;

    /**
     * Borra el objeto. No debe fallar si ya no está.
     */
    public function delete(string $key): void;
}

final class S3Storage implements StorageInterface
{
    public function objectSize(string $key): ?int
    {
        return S3Service::getObjectSize($key);
    }

    public function delete(string $key): void
    {
        S3Service::deleteObject($key);
    }
}
