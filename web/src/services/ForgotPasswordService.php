<?php
require_once __DIR__ . '/MailTemplateService.php';

/**
 * "Forgot your password?" on the sign-in page (#13): a user asks for the
 * password reset e-mail with their username or e-mail address.
 *
 * - The answer never tells whether an account exists (same message, same
 *   path), so the form cannot be used to find usernames.
 * - Limited per IP (sys_login_logs, status RESET_REQ: 5 an hour) and per
 *   account (one mail in 10 minutes).
 * - The link uses the installation's own domain (PORTAL_DOMAIN), never the
 *   request's Host header: an attacker could otherwise make the victim receive
 *   a reset link pointing at another server.
 */
class ForgotPasswordService
{
    const MAX_PER_IP_HOUR = 5;
    const TOKEN_MINUTES = 30;

    /** @return array{success: bool, error?: string} success = show the neutral "if the account exists" message */
    public static function request(string $identifier, string $ip): array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 120) {
            return ['success' => false, 'error' => t('forgot.err_identifier')];
        }

        $db = getDB();
        $st = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE ip_address = ? AND status = 'RESET_REQ' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() >= self::MAX_PER_IP_HOUR) {
            return ['success' => false, 'error' => t('forgot.err_too_many')];
        }
        logLoginAttempt($ip, $identifier, 'RESET_REQ');

        $st = $db->prepare("SELECT id, username, full_name, email FROM sys_users
                            WHERE (username = ? OR (email = ? AND email <> '')) AND is_active = 1
                            ORDER BY (username = ?) DESC, id ASC LIMIT 1");
        $st->execute([$identifier, $identifier, $identifier]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$user || !filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => true];
        }
        // One mail per account every 10 minutes (mail flooding), whichever of
        // username / e-mail was typed. The request just logged counts as one.
        $st = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE status = 'RESET_REQ' AND username IN (?, ?) AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $st->execute([$user['username'], $user['email']]);
        if ((int) $st->fetchColumn() > 1) {
            return ['success' => true];
        }

        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE sys_users SET reset_token = ?, reset_token_expires = ? WHERE id = ?')
            ->execute([$token, date('Y-m-d H:i:s', time() + self::TOKEN_MINUTES * 60), $user['id']]);

        $url = self::portalUrl() . '/reset-password?token=' . $token;
        $lang = MailTemplateService::languageFor(null, (int) $user['id']);
        $res = MailTemplateService::send('password_reset', (string) $user['email'], $lang, [
            'name' => (string) (($user['full_name'] ?? '') ?: $user['username']),
            'username' => (string) $user['username'],
            'reset_link' => $url,
        ], [
            'reset_button' => MailTemplateService::inLanguage($lang, fn() => MailTemplateService::buttonBlock($url, t('mail_templates.btn_reset'))),
        ]);
        writeAuditLog(null, 'system_users', $user['id'],
            'Password reset requested on the sign-in page: ' . $user['username'] . ($res['success'] ? '' : ' (mail failed: ' . ($res['error'] ?? '') . ')'),
            'password_reset_request', null);
        if (!$res['success']) {
            // Not sent: let the user ask again instead of waiting for the token to expire.
            $db->prepare('UPDATE sys_users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ?')->execute([$user['id']]);
        }
        return ['success' => true];
    }

    /** https://<the installation's domain>, independent of the request. */
    public static function portalUrl(): string
    {
        $domain = trim((string) portalEnv('PORTAL_DOMAIN', ''));
        if ($domain === '') {
            $domain = trim((string) getSystemSetting('pjsip_external_domain', ''));
        }
        if ($domain === '' || !preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $domain)) {
            $domain = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['SERVER_ADDR'] ?? 'localhost'));
        }
        return 'https://' . $domain;
    }
}
