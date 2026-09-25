<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Api.php';

/**
 * Utilidades del panel administrativo: respuestas JSON, guards de acceso,
 * estados/transiciones de solicitudes, paginación y etiquetas de UI.
 */

const DASHBOARD_ALLOWED_STATUSES = ['pendiente', 'en_revision', 'aprobado', 'denegado'];
const DASHBOARD_ALLOWED_TYPES = [
    'credito', 'afiliacion', 'pre-evaluacion', 'mpv',
    'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
    'prestamo-solidario', 'auxilio-fallecimiento',
];

const DASHBOARD_STATUS_TRANSITIONS = [
    'pendiente' => ['en_revision'],
    'en_revision' => ['aprobado', 'denegado'],
    'aprobado' => [],
    'denegado' => [],
];

/**
 * Guard para endpoints API (JSON). Devuelve el usuario o responde 401.
 *
 * @return array{id:int,username:string,role:string}
 */
function dashboard_guard_api(): array
{
    $user = dashboard_current_user();
    if ($user === null) {
        ApiResponse::error('No autorizado', 401);
    }
    return $user;
}

/**
 * Guard para páginas HTML. Redirige a login.php si no hay sesión.
 *
 * @return array{id:int,username:string,role:string}
 */
function dashboard_guard_page(): array
{
    $user = dashboard_current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

/**
 * Validación de transiciones de estado (server-side), igual que el dashboard Node.
 */
function dashboard_assert_transition(string $current, string $next): ?string
{
    $allowed = DASHBOARD_STATUS_TRANSITIONS[$current] ?? null;
    if ($allowed === null) {
        return "Estado actual \"{$current}\" no admite cambios.";
    }
    if (!in_array($next, $allowed, true)) {
        return "Transición inválida: no se puede cambiar de \"{$current}\" a \"{$next}\".";
    }
    return null;
}

function dashboard_status_label(string $status): string
{
    return match ($status) {
        'pendiente' => 'Pendiente',
        'en_revision' => 'En revisión',
        'aprobado' => 'Aprobado',
        'denegado' => 'Denegado',
        default => '—',
    };
}

function dashboard_status_badge_class(string $status): string
{
    return match ($status) {
        'aprobado' => 'bg-emerald-100 text-emerald-700',
        'denegado' => 'bg-red-100 text-red-700',
        'en_revision' => 'bg-blue-100 text-blue-700',
        default => 'bg-amber-100 text-amber-700',
    };
}

function dashboard_type_label(string $type): string
{
    return match ($type) {
        'credito' => 'Crédito',
        'afiliacion' => 'Afiliación',
        'pre-evaluacion' => 'Pre-evaluación',
        'mpv' => 'Mesa de Partes',
        'auxilio-retiro' => 'Auxilio por Retiro',
        'auxilio-invalidez' => 'Auxilio por Invalidez',
        'seguro-sepelio' => 'Seguro de Sepelio',
        'prestamo-solidario' => 'Préstamo Solidario',
        'auxilio-fallecimiento' => 'Auxilio por Fallecimiento',
        default => '—',
    };
}

/**
 * Guard de roles para endpoints API. Responde 403 si el usuario no tiene uno
 * de los roles permitidos.
 *
 * @param string[] $roles
 * @return array{id:int,username:string,role:string}
 */
function dashboard_require_roles(array $roles): array
{
    $user = dashboard_guard_api();
    if (!in_array($user['role'], $roles, true)) {
        ApiResponse::error('No tiene permisos para realizar esta acción', 403);
    }
    return $user;
}

/**
 * Opciones de estado permitidas para el <select> según el estado actual.
 *
 * @return array<int, array{value:string,label:string}>
 */
function dashboard_status_options(string $current): array
{
    $map = [
        'pendiente' => [
            ['value' => 'pendiente', 'label' => 'Pendiente'],
            ['value' => 'en_revision', 'label' => 'En revisión'],
        ],
        'en_revision' => [
            ['value' => 'en_revision', 'label' => 'En revisión'],
            ['value' => 'aprobado', 'label' => 'Aprobado'],
            ['value' => 'denegado', 'label' => 'Denegado'],
        ],
        'aprobado' => [],
        'denegado' => [],
    ];
    return $map[$current] ?? [];
}

function dashboard_is_image_name(string $name): bool
{
    return (bool) preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $name);
}

/**
 * Dirección IP del cliente (respeta proxies comunes de cPanel/Cloudflare).
 */
function dashboard_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return mb_substr($ip, 0, 45);
            }
        }
    }
    return '';
}

/**
 * Construye la URL (query string) del panel preservando los filtros actuales
 * y aplicando los overrides dados. Un override con valor null elimina la key.
 */
function dashboard_query_url(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query);
}