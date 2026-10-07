<?php
/**
 * Permanent audit record — no row is ever deleted or updated (2026-08-24).
 * Called by markPendingSync() (when a record changes) and applyPendingSync()
 * (when Apply is pressed, per domain). $user_id may be null (e.g. an action
 * started from the CLI) — the username is denormalized from the current DB
 * snapshot and stored, so the record stays readable even if the user is
 * deleted later.
 */
function writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id = null) {
    try {
        $db = getDB();
        $username = null;
        if ($user_id) {
            $username = $db->prepare("SELECT full_name FROM sys_users WHERE id = ?");
            $username->execute([$user_id]);
            $username = $username->fetchColumn() ?: null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare(
            "INSERT INTO sys_audit_log (domain, entity_type, entity_id, entity_label, action, user_id, username, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id, $username, $ip]);
    } catch (\Exception $e) {
        // Failing to write the audit record must NEVER block the actual
        // operation (saving/applying settings) — it is silently skipped.
    }
}

