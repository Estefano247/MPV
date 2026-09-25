<?php

declare(strict_types=1);

/**
 * Implementación pura de scrypt compatible con `crypto.scryptSync` de Node.js
 * (parámetros por defecto N=16384, r=8, p=1, dkLen=64).
 *
 * Se usa para verificar los hashes `salt:hash` que ya existen en la tabla
 * `users` (creados por el dashboard React original: src/server/password.ts y
 * scripts/seed.mjs). Un login tarda ~2-4 s en PHP puro; verifica antes de
 * usar la extensión `sodium` si está disponible (más rápida).
 */

if (!function_exists('scrypt_compute')) {
    /** Núcleo Salsa20/8 inline sobre un bloque de 64 bytes (16 palabras LE). */
    function scrypt_salsa20_8(string $block): string
    {
        $w = unpack('V16', $block);

        $x0  = $w[1];  $x1  = $w[2];  $x2  = $w[3];  $x3  = $w[4];
        $x4  = $w[5];  $x5  = $w[6];  $x6  = $w[7];  $x7  = $w[8];
        $x8  = $w[9];  $x9  = $w[10]; $x10 = $w[11]; $x11 = $w[12];
        $x12 = $w[13]; $x13 = $w[14]; $x14 = $w[15]; $x15 = $w[16];

        for ($i = 0; $i < 8; $i += 2) {
            // ---- Ronda de columnas ----
            $t = ($x0 + $x12) & 0xFFFFFFFF; $x4  ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x4 + $x0) & 0xFFFFFFFF;  $x8  ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x8 + $x4) & 0xFFFFFFFF;  $x12 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x12 + $x8) & 0xFFFFFFFF; $x0  ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            $t = ($x5 + $x1) & 0xFFFFFFFF;  $x9  ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x9 + $x5) & 0xFFFFFFFF;  $x13 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x13 + $x9) & 0xFFFFFFFF; $x1  ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x1 + $x13) & 0xFFFFFFFF; $x5  ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            $t = ($x10 + $x6) & 0xFFFFFFFF; $x14 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x14 + $x10) & 0xFFFFFFFF; $x2  ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x2 + $x14) & 0xFFFFFFFF;  $x6  ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x6 + $x2) & 0xFFFFFFFF;   $x10 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            $t = ($x15 + $x11) & 0xFFFFFFFF; $x3  ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x3 + $x15) & 0xFFFFFFFF;  $x7  ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x7 + $x3) & 0xFFFFFFFF;   $x11 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x11 + $x7) & 0xFFFFFFFF;  $x15 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            // ---- Ronda de filas ----
            $t = ($x0 + $x3) & 0xFFFFFFFF;  $x1  ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x1 + $x0) & 0xFFFFFFFF;  $x2  ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x2 + $x1) & 0xFFFFFFFF;  $x3  ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x3 + $x2) & 0xFFFFFFFF;  $x0  ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            $t = ($x5 + $x4) & 0xFFFFFFFF;  $x6  ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x6 + $x5) & 0xFFFFFFFF;  $x7  ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x7 + $x6) & 0xFFFFFFFF;  $x4  ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x4 + $x7) & 0xFFFFFFFF;  $x5  ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            $t = ($x10 + $x9) & 0xFFFFFFFF; $x11 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x11 + $x10) & 0xFFFFFFFF; $x8  ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x8 + $x11) & 0xFFFFFFFF; $x9  ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x9 + $x8) & 0xFFFFFFFF;  $x10 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;

            $t = ($x15 + $x14) & 0xFFFFFFFF; $x12 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
            $t = ($x12 + $x15) & 0xFFFFFFFF; $x13 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
            $t = ($x13 + $x12) & 0xFFFFFFFF; $x14 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
            $t = ($x14 + $x13) & 0xFFFFFFFF; $x15 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
        }

        return pack('V16',
            ($x0  + $w[1])  & 0xFFFFFFFF, ($x1  + $w[2])  & 0xFFFFFFFF,
            ($x2  + $w[3])  & 0xFFFFFFFF, ($x3  + $w[4])  & 0xFFFFFFFF,
            ($x4  + $w[5])  & 0xFFFFFFFF, ($x5  + $w[6])  & 0xFFFFFFFF,
            ($x6  + $w[7])  & 0xFFFFFFFF, ($x7  + $w[8])  & 0xFFFFFFFF,
            ($x8  + $w[9])  & 0xFFFFFFFF, ($x9  + $w[10]) & 0xFFFFFFFF,
            ($x10 + $w[11]) & 0xFFFFFFFF, ($x11 + $w[12]) & 0xFFFFFFFF,
            ($x12 + $w[13]) & 0xFFFFFFFF, ($x13 + $w[14]) & 0xFFFFFFFF,
            ($x14 + $w[15]) & 0xFFFFFFFF, ($x15 + $w[16]) & 0xFFFFFFFF
        );
    }

    /** BlockMix de scrypt: reorganiza bloques pares/impares. */
    function scrypt_block_mix(string $block, int $r): string
    {
        $blockSize = 64;

        $x = substr($block, $blockSize * (2 * $r - 1));
        $y = '';

        for ($i = 0; $i < 2 * $r; $i++) {
            $chunk = substr($block, $blockSize * $i, $blockSize);
            $x = scrypt_salsa20_8($x ^ $chunk);
            $y .= $x;
        }

        $out = '';
        for ($i = 0; $i < $r; $i++) {
            $out .= substr($y, $blockSize * (2 * $i), $blockSize);
        }
        for ($i = 0; $i < $r; $i++) {
            $out .= substr($y, $blockSize * (2 * $i + 1), $blockSize);
        }

        return $out;
    }

    /** scryptROMix sobre un bloque de 128*r bytes. */
    function scrypt_romix(string $block, int $n, int $r): string
    {
        $x = $block;
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            $v[] = $x;
            $x = scrypt_block_mix($x, $r);
        }

        for ($i = 0; $i < $n; $i++) {
            // Integerify (RFC 7914): interpretar el ÚLTIMO bloque de 64 bytes
            // (B[2r-1]) como entero little-endian, mod N (N es potencia de 2).
            $last = substr($x, 64 * (2 * $r - 1), 8);
            $j = (unpack('v', $last)[1]) & ($n - 1);
            $x = scrypt_block_mix($x ^ $v[$j], $r);
        }

        return $x;
    }

    function scrypt_compute(string $password, string $salt, int $n = 16384, int $r = 8, int $p = 1, int $dkLen = 64): string
    {
        $blockSize = 128 * $r;

        $b = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockSize, true);

        for ($i = 0; $i < $p; $i++) {
            $offset = $i * $blockSize;
            $block = substr($b, $offset, $blockSize);
            $block = scrypt_romix($block, $n, $r);
            $b = substr_replace($b, $block, $offset, $blockSize);
        }

        return hash_pbkdf2('sha256', $password, $b, 1, $dkLen, true);
    }

    /**
     * Verifica una contraseña contra un hash en formato Node `salt:hash` (hex).
     */
    function scrypt_verify_node(string $password, string $stored): bool
    {
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }
        [$salt, $expected] = $parts;
        $expectedBytes = hex2bin($expected);
        if ($expectedBytes === false) {
            return false;
        }
        $actual = scrypt_compute($password, $salt, 16384, 8, 1, strlen($expectedBytes));
        return hash_equals($actual, $expectedBytes);
    }
}