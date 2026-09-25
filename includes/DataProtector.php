<?php

declare(strict_types=1);

/**
 * Cifrado de datos personales en reposo (AES-256-GCM).
 *
 * Envoltura: `enc1:` + base64(nonce{12} + tag{16} + ciphertext).
 * - Clave desde APP_DATA_KEY (.env, hex de 32 bytes). Si no existe se deriva
 *   del JWT_SECRET con SHA-256 (compatible con todos los despliegues actuales;
 *   para producción conviene fijar APP_DATA_KEY).
 * - Legado a prueba: si el texto no tiene el prefijo `enc1:`, se devuelve tal
 *   cual (registros creados antes de habilitar el cifrado).
 */
final class DataProtector
{
    private const PREFIX = 'enc1:';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $config = require __DIR__ . '/config.php';
        $raw = (string) ($config['data']['key'] ?? '');
        if ($raw === '') {
            // Fallback documentado: derivar de JWT_SECRET.
            $raw = hash('sha256', (string) ($config['auth']['jwtSecret'] ?? ''));
            $raw = implode('', array_map('chr', array_slice(unpack('C*', $raw), 0, 32)));
        }
        return self::$key = $raw;
    }

    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $nonce = random_bytes(self::NONCE_LEN);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el dato.');
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /**
     * @return string|null Texto descifrado, o el valor original si es legado.
     */
    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $blob = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) <= self::NONCE_LEN + self::TAG_LEN) {
            return '';
        }
        $nonce = substr($blob, 0, self::NONCE_LEN);
        $tag = substr($blob, self::NONCE_LEN, self::TAG_LEN);
        $cipher = substr($blob, self::NONCE_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }
}