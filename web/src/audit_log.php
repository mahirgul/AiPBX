<?php
/**
 * Permanent audit record — no row is ever deleted or updated (2026-08-24).
 * Called by markPendingSync() (when a record changes) and applyPendingSync()
 * (when Apply is pressed, per domain). $user_id may be null (e.g. an action
 * started from the CLI) — the username is denormalized from the current DB
 * snapshot and stored, so the record stays readable even if the user is
 * deleted later.
 */
/**
 * The area (domain) of records written without one, by what they are about.
 * Older rows keep their empty domain (the log is never changed); the Audit page
 * reads them through this map too, so its area filter finds them.
 */
const AUDIT_DOMAIN_BY_TYPE = [
    'firewall' => 'security', 'fail2ban' => 'security', 'auth_sso_only' => 'security',
    'user_account' => 'users', 'system_users' => 'users', 'role' => 'users', 'user_settings' => 'users',
    'system' => 'system', 'system_service' => 'system', 'brand_settings' => 'system', 'mobile_settings' => 'system',
    'mail_settings' => 'system', 'mail_templates' => 'system', 'sound_pack' => 'system',
    'fax_cover' => 'fax', 'fax_inbox' => 'fax', 'fax_mail_settings' => 'fax', 'fax_sent' => 'fax',
    'cdr' => 'reports', 'report' => 'reports',
    'ai_tts' => 'ai_cloud', 'phonebook' => 'phones',
];

function writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id = null) {
    $domain = $domain ?? (AUDIT_DOMAIN_BY_TYPE[(string) $entity_type] ?? null);
    try {
        $db = getDB();
        $username = null;
        if (!empty($GLOBALS['AIPBX_AUDIT_ACTOR'])) {
            $username = (string)$GLOBALS['AIPBX_AUDIT_ACTOR'];
        } elseif ($user_id) {
            $username = $db->prepare("SELECT full_name FROM sys_users WHERE id = ?");
            $username->execute([$user_id]);
            $username = $username->fetchColumn() ?: null;
        }
        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? null);
        $stmt = $db->prepare(
            "INSERT INTO sys_audit_log (domain, entity_type, entity_id, entity_label, action, user_id, username, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id, $username, $ip]);
    } catch (\Exception $e) {
        // Failing to write the audit record must NEVER block the actual operation
        // (saving/applying settings), but a security event must leave some trace.
        error_log('AUDIT LOG WRITE FAILED: ' . json_encode([
            'entity_type' => $entity_type, 'entity_id' => $entity_id, 'label' => $entity_label,
            'action' => $action, 'user_id' => $user_id, 'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE));
    }
}

