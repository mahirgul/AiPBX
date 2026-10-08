<?php
/**
 * Force password reset (sends the mail) service
 */
class ForceResetService {
    /**
     * @return array{sent:bool, error?:string}
     */
    public static function requestResetEmail(array $post, array $user): array
    {
        $user_id = (int)$user['id'];
        $csrf_token = $post['csrf_token'] ?? '';

        if (!verifyCSRFToken($csrf_token)) {
            return ['sent' => false, 'error' => t('common.invalid_csrf')];
        }
        if (empty($user['email'])) {
            return ['sent' => false, 'error' => t('srv_reset.err_no_email')];
        }

        $db = getDB();

        // Do not resend while a recently sent, still valid token exists (spam guard)
        $existing = $db->prepare('SELECT reset_token_expires FROM sys_users WHERE id = ? AND reset_token_expires > NOW()');
        $existing->execute([$user_id]);
        $stillValid = $existing->fetchColumn();

        if ($stillValid) {
            return ['sent' => true];
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 1800); // 30 dakika
        $upd = $db->prepare('UPDATE sys_users SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
        $upd->execute([$token, $expires, $user_id]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = $scheme . '://' . $host . '/reset-password?token=' . $token;

        $lang = MailTemplateService::languageFor(null, $user_id);
        $res = MailTemplateService::send('password_reset', $user['email'], $lang, [
            'name' => (string) (($user['full_name'] ?? '') ?: $user['username']),
            'username' => (string) $user['username'],
            'reset_link' => $resetUrl,
        ], [
            'reset_button' => MailTemplateService::inLanguage($lang, fn() => MailTemplateService::buttonBlock($resetUrl, t('mail_templates.btn_reset'))),
        ]);
        $mailOk = $res['success'];

        if ($mailOk) {
            return ['sent' => true];
        }
        return ['sent' => false, 'error' => t('srv_reset.err_send')];
    }
}
