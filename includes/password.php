<?php

declare(strict_types=1);

require_once __DIR__ . '/scrypt.php';

/**
 * Verificación de contraseñas del panel administrativo con dos formatos:
 *
 * - `salt:hash` (hex de 64 bytes)  -> scrypt de Node.js (usuarios existentes).
 * - `$2y$...` / `$argon2...`       -> password_hash/password_verify (nuevos,
 *                                     creados por admin/setup.php).
 */
function dashboard_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function dashboard_verify_password(string $password, string $stored): bool
{
    if ($stored === '') {
        return false;
    }
    if (str_contains($stored, ':')) {
        return scrypt_verify_node($password, $stored);
    }
    return password_verify($password, $stored);
}