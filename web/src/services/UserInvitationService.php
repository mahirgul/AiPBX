<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * User Invitation & Activation Mail Service
 * Handles the new-user activation and password-setup emails.
 */
class UserInvitationService
{
    /**
     * Sends the activation / password-setup email to one user.
     *
     * @param int $userId user ID in sys_users
     * @param bool $isNew is this a newly created user?
     * @return array{success: bool, message?: string, error?: string, token?: string, code?: string}
     */
    public static function sendInvitationEmail(int $userId, bool $isNew = false): array
    {
        if ($userId <= 0) {
            return ['success' => false, 'error' => t('srv_invite.err_invalid_id')];
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, full_name, email, extension, is_active FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => t('srv_invite.err_not_found')];
        }

        $email = trim($user['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => sprintf(t('srv_invite.err_no_email'), $user['username']),
                'code' => 'no_email'
            ];
        }

        if (empty($user['is_active'])) {
            return [
                'success' => false,
                'error' => sprintf(t('srv_invite.err_disabled'), $user['username'])
            ];
        }

        // Generate a secure 256-bit random token (valid for 48 hours)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (86400 * 2)); // 48 saat

        // The token is stored now (the link needs it); must_reset_password only
        // once the mail went out — set before, a failed send left the user
        // forced into a reset whose link never arrived.
        $upd = $db->prepare('UPDATE sys_users SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
        $upd->execute([$token, $expires, $userId]);

        // Build the link URL
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
        $resetUrl = $scheme . '://' . $host . '/reset-password?token=' . $token;

        // Direct sign-in link for the mobile app (7 days, single use).
        // On a phone it opens the app and signs in; on a computer it shows a QR.
        // A user without an extension gets no mobile sign-in (it needs a SIP account).
        $mobileUrl = '';
        if (!empty($user['extension'])) {
            require_once __DIR__ . '/QrLoginService.php';
            $mobileRes = QrLoginService::createEmailLink($userId);
            $mobileUrl = $mobileRes['url'] ?? '';
        }

        // Recipient's language; the text comes from the "invite"/"invite_new"
        // e-mail template (Admin → E-Mail → Templates).
        $lang = MailTemplateService::languageFor(null, $userId);
        $displayName = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
        $blocks = MailTemplateService::inLanguage($lang, fn() => [
            'account_details' => self::accountDetails($user['username'], (string) ($user['extension'] ?? ''), $email),
            'reset_button' => MailTemplateService::buttonBlock($resetUrl, t('srv_invite.button')),
            'mobile_section' => $mobileUrl !== '' ? self::mobileSection($mobileUrl) : ['html' => '', 'text' => ''],
        ]);
        $res = MailTemplateService::send($isNew ? 'invite_new' : 'invite', $email, $lang, [
            'name' => $displayName,
            'username' => $user['username'],
            'extension' => (string) ($user['extension'] ?? ''),
            'email' => $email,
            'reset_link' => $resetUrl,
        ], $blocks);
        $mailOk = $res['success'];

        if ($mailOk) {
            $db->prepare('UPDATE sys_users SET must_reset_password = 1 WHERE id = ?')->execute([$userId]);
            writeAuditLog(null, 'system_users', $userId, "Activation/sign-in e-mail sent: {$user['username']} ({$email})", 'mail_sent', $_SESSION['user_id'] ?? null);
            return [
                'success' => true,
                'message' => sprintf(t('srv_invite.sent'), $user['username'], $email),
                'token' => $token
            ];
        }

        return [
            'success' => false,
            'error' => sprintf(t('srv_invite.err_send'), $email, $res['error'] ?? t('srv_invite.err_no_response'))
        ];
    }

    /** The account box of the invitation ({account_details}). */
    public static function accountDetails(string $username, string $extension, string $email): array
    {
        return MailTemplateService::detailsBlock(t('srv_invite.account_details'), [
            t('srv_invite.lbl_username') => $username,
            t('srv_invite.lbl_extension') => $extension,
            t('srv_invite.lbl_email') => $email,
        ]);
    }

    /** The mobile app sign-in box of the invitation ({mobile_section}). */
    public static function mobileSection(string $mobileUrl): array
    {
        $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $btn = MailTemplateService::buttonBlock($mobileUrl, t('srv_invite.mobile_button'));
        return [
            'html' => '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;margin:22px 0;font-size:13.5px;color:#1e3a8a;">'
                // mobile_body/mobile_note are our own language strings with <strong>: used as they are.
                . '<strong>' . $e(t('srv_invite.mobile_title')) . '</strong><br>' . t('srv_invite.mobile_body')
                . $btn['html'] . '<span style="font-size:12px;">' . t('srv_invite.mobile_note') . '</span></div>',
            'text' => t('srv_invite.mobile_title') . "\n" . strip_tags(t('srv_invite.mobile_body')) . "\n" . $btn['text'] . "\n" . strip_tags(t('srv_invite.mobile_note')),
        ];
    }

    /**
     * Sends the activation / password-setup mail to several selected users at once.
     *
     * @param array<int|string> $userIds
     * @return array{success: bool, sent_count: int, skipped_count: int, failed_count: int, message: string}
     */
    public static function sendBulkInvitations(array $userIds): array
    {
        $sentCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($userIds as $rawId) {
            $userId = (int)$rawId;
            if ($userId <= 0) continue;

            $res = self::sendInvitationEmail($userId, false);
            if ($res['success']) {
                $sentCount++;
            } else {
                if (($res['code'] ?? '') === 'no_email') {
                    $skippedCount++;
                } else {
                    $failedCount++;
                    $errors[] = $res['error'] ?? sprintf(t('srv_invite.err_user'), $userId);
                }
            }
        }

        $msgParts = [];
        if ($sentCount > 0) {
            $msgParts[] = sprintf(t('srv_invite.bulk_sent'), $sentCount);
        }
        if ($skippedCount > 0) {
            $msgParts[] = sprintf(t('srv_invite.bulk_skipped'), $skippedCount);
        }
        if ($failedCount > 0) {
            $msgParts[] = sprintf(t('srv_invite.bulk_failed'), $failedCount, implode(', ', array_slice($errors, 0, 3)));
        }

        if (empty($msgParts)) {
            return [
                'success' => false,
                'sent_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'message' => t('srv_invite.bulk_none')
            ];
        }

        return [
            'success' => $sentCount > 0 || ($skippedCount > 0 && $failedCount === 0),
            'sent_count' => $sentCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'message' => implode(' ', $msgParts)
        ];
    }
}
