<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/auth.php';

/**
 * Bitácora de auditoría del panel administrativo (tabla `audit_log`).
 *
 * Nunca lanza excepciones: si falla el registro, no se rompe la operación
 * principal (cambio de estado, borrado, login, etc.).
 *
 * @param string       $action     login | login_failed | logout | status_change | delete_submission | view_files | derivar
 * @param array        $details    Detalles legibles (from/to, name, reason, ...)
 * @param string|null  $entityType submissions | users
 * @param string|null  $entityId   UUID de la entidad
 */
function dashboard_log_audit(
    string $action,
    array $details = [],
    ?string $entityType = null,
    ?string $entityId = null
): void {
    try {
        $user = dashboard_current_user();
        $db = Database::getConnection();
        $db->prepare(
            'INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
             VALUES (:user_id, :username, :action, :entity_type, :entity_id, :details::jsonb, :ip, :ua, NOW())'
        )->execute([
            ':user_id' => $user['id'] ?? null,
            ':username' => $user['username'] ?? null,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ':ip' => dashboard_client_ip(),
            ':ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[AUDIT] No se pudo registrar la acción "' . $action . '": ' . $e->getMessage());
    }
}