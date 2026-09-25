<?php

declare(strict_types=1);

/**
 * Autenticación del panel administrativo (admin/).
 *
 * Sesión sin sesión PHP: cookie httpOnly `dashboard_token` con un JWT HS256,
 * compatible con el token que generaba el dashboard React (jose) para poder
 * compartir la misma cookie/BD.
 */

require_once __DIR__ . '/config.php';

function dashboard_config(): array
{
    return require __DIR__ . '/config.php';
}

function dashboard_is_secure_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function dashboard_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function dashboard_b64url_decode(string $data): string|false
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data, true);
}

/**
 * @param array{id:int,username:string,role:string} $user
 */
function dashboard_create_token(array $user): string
{
    $config = dashboard_config();
    $now = time();
    $exp = $now + ((int) $config['auth']['sessionDurationHours']) * 3600;

    $header = dashboard_b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = dashboard_b64url_encode((string) json_encode([
        'sub' => (string) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'iat' => $now,
        'exp' => $exp,
    ]));

    $signingInput = "{$header}.{$payload}";
    $signature = dashboard_b64url_encode(hash_hmac('sha256', $signingInput, (string) $config['auth']['jwtSecret'], true));

    return "{$signingInput}.{$signature}";
}

/**
 * @return array{id:int,username:string,role:string}|null
 */
function dashboard_verify_token(?string $token): ?array
{
    if ($token === null || $token === '') {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $payload, $signature] = $parts;

    $config = dashboard_config();
    $expected = dashboard_b64url_encode(
        hash_hmac('sha256', "{$header}.{$payload}", (string) $config['auth']['jwtSecret'], true)
    );
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $claims = json_decode((string) dashboard_b64url_decode($payload), true);
    if (!is_array($claims) || empty($claims['sub']) || empty($claims['exp'])) {
        return null;
    }
    if ((int) $claims['exp'] < time()) {
        return null;
    }

    return [
        'id' => (int) $claims['sub'],
        'username' => (string) ($claims['username'] ?? ''),
        'role' => (string) ($claims['role'] ?? 'admin'),
    ];
}

/**
 * @return array{id:int,username:string,role:string}|null Usuario de la cookie, si hay.
 */
function dashboard_current_user(): ?array
{
    $config = dashboard_config();
    $token = $_COOKIE[$config['auth']['cookieName']] ?? null;
    return dashboard_verify_token($token);
}

function dashboard_set_session_cookie(string $token): void
{
    $config = dashboard_config();
    $hours = (int) $config['auth']['sessionDurationHours'];
    setcookie($config['auth']['cookieName'], $token, [
        'expires' => time() + $hours * 3600,
        'path' => '/',
        'secure' => dashboard_is_secure_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function dashboard_clear_session_cookie(): void
{
    $config = dashboard_config();
    setcookie($config['auth']['cookieName'], '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => dashboard_is_secure_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$config['auth']['cookieName']]);
}