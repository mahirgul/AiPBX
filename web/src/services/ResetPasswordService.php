<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Reset password service
 */
class ResetPasswordService {
    /**
     * @return array{success:bool, error?:string}
     */
    public static function resetPassword(array $post, ?array $user): array
    {
        $csrf_token = $post['csrf_token'] ?? '';
        $new_password = (string)($post['new_password'] ?? '');
        $confirm_password = (string)($post['confirm_password'] ?? '');

        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        if (!$user) {
            return ['success' => false, 'error' => t('srv_resetpw.err_link')];
        }
        if (mb_strlen($new_password) < 8) {
            return ['success' => false, 'error' => t('srv_resetpw.err_length')];
        }
        if (strcasecmp($new_password, $user['username']) === 0) {
            return ['success' => false, 'error' => t('srv_resetpw.err_same_user')];
        }
        if ($new_password !== $confirm_password) {
            return ['success' => false, 'error' => t('srv_resetpw.err_mismatch')];
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $db = getDB();
        // A "forgot my password" reset also drops the sessions on the phones
        // (token_epoch). An invitation/first password setup
        // (must_reset_password=1) does not: the user may already have signed
        // in to the app with the link in the email. token_epoch must be set
        // FIRST — MariaDB applies SET from left to right, and
        // must_reset_password becomes 0 below.
        $upd = $db->prepare('UPDATE sys_users SET token_epoch = token_epoch + IF(must_reset_password = 1, 0, 1), password_hash = ?, must_reset_password = 0, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $upd->execute([$new_hash, $user['id']]);
        unset($_SESSION['pending_reset_user_id']);

        // A credential change is a security-relevant event but left no trace
        // anywhere (found in the 2026-08-31 audit — a reset done by the admin
        // is already logged in UserService; the self-service flow was the
        // missing one). user_id is NULL: there is no session, the token owner
        // does it themselves (entity_id is that user).
        writeAuditLog(null, 'system_users', $user['id'], "Password reset (self-service): " . ($user['username'] ?? '?'), 'password_reset', null);

        return ['success' => true];
    }
}
