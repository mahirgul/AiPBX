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

        // Save it to the database and set the must_reset_password flag
        $upd = $db->prepare('UPDATE sys_users SET reset_token = ?, reset_token_expires = ?, must_reset_password = 1 WHERE id = ?');
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

        // Mail and system settings
        $fromAddress = preg_replace('/[\r\n]+/', '', getSystemSetting('mail_from_address', getSystemSetting('portal_email_from_address', 'no-reply@example.com')));
        $fromName = preg_replace('/[\r\n]+/', '', getSystemSetting('mail_from_name', getSystemSetting('portal_email_from_name', 'AI PBX')));
        $brandTitle = getSystemSetting('brand_title', 'AI PBX');
        $brandSub = getSystemSetting('brand_sub', 'Business Communication Platform');

        $subject = $isNew
            ? sprintf(t('srv_invite.subject_new'), $brandTitle)
            : sprintf(t('srv_invite.subject'), $brandTitle);

        $displayName = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
        $lblExt = t('srv_invite.lbl_extension');
        $lblUser = t('srv_invite.lbl_username');
        $lblEmail = t('srv_invite.lbl_email');
        $extInfo = !empty($user['extension']) ? "<li><strong>{$lblExt}:</strong> {$user['extension']}</li>" : "";
        $extText = !empty($user['extension']) ? "- {$lblExt}: {$user['extension']}\n" : "";

        if ($mobileUrl !== '') {
            $txMobileTitle = t('srv_invite.mobile_title');
            $txMobileBody = t('srv_invite.mobile_body');
            $txMobileBtn = t('srv_invite.mobile_button');
            $txMobileNote = t('srv_invite.mobile_note');
            $mobileBlock = <<<HTML
<div class="qr-tip-box">
      <strong>{$txMobileTitle}</strong><br>
      {$txMobileBody}
      <div style="text-align: center; margin: 18px 0 6px 0;">
        <a href="{$mobileUrl}" class="btn" target="_blank">{$txMobileBtn}</a>
      </div>
      <span style="font-size: 12px;">{$txMobileNote}</span>
    </div>
HTML;
            $mobileText = str_replace('\n', "\n", sprintf(t('srv_invite.mobile_text'), $mobileUrl));
        } else {
            $mobileBlock = '';
            $mobileText = '';
        }

        $htmlLang = getUserLanguage();
        $txGreeting = sprintf(t('srv_invite.greeting'), $displayName);
        $txIntro = sprintf(t('srv_invite.intro'), $brandTitle);
        $txAccount = t('srv_invite.account_details');
        $txButton = t('srv_invite.button');
        $txFallback = t('srv_invite.fallback');
        $txSecurity = t('srv_invite.security');
        $txFooter = sprintf(t('srv_invite.footer'), $brandTitle);

        // HTML email body
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="{$htmlLang}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$subject}</title>
<style>
  body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
  .email-container { max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1); }
  .email-header { background: linear-gradient(135deg, #2563eb, #1d4ed8); padding: 32px 24px; text-align: center; color: #ffffff; }
  .email-header h1 { margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -0.5px; }
  .email-header p { margin: 6px 0 0; font-size: 14px; opacity: 0.9; }
  .email-body { padding: 32px 28px; line-height: 1.6; font-size: 15px; }
  .greeting { font-size: 17px; font-weight: 600; margin-bottom: 16px; }
  .info-box { background: #f8fafc; border-left: 4px solid #2563eb; padding: 14px 18px; margin: 20px 0; border-radius: 4px; font-size: 14px; }
  .info-box ul { margin: 8px 0 0; padding-left: 20px; }
  .info-box li { margin-bottom: 4px; }
  .btn-container { text-align: center; margin: 30px 0; }
  .btn { display: inline-block; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 15px; box-shadow: 0 4px 12px rgba(37,99,235,0.25); }
  .qr-tip-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin: 24px 0; font-size: 13.5px; color: #1e40af; }
  .security-note { font-size: 12px; color: #64748b; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 16px; }
  .email-footer { background: #f8fafc; text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="email-container">
  <div class="email-header">
    <h1>{$brandTitle}</h1>
    <p>{$brandSub}</p>
  </div>
  <div class="email-body">
    <div class="greeting">{$txGreeting}</div>
    <p>{$txIntro}</p>
    
    <div class="info-box">
      <strong>{$txAccount}</strong>
      <ul>
        <li><strong>{$lblUser}:</strong> {$user['username']}</li>
        {$extInfo}
        <li><strong>{$lblEmail}:</strong> {$email}</li>
      </ul>
    </div>

    <div class="btn-container">
      <a href="{$resetUrl}" class="btn" target="_blank">{$txButton}</a>
    </div>

    <p style="font-size: 13px; color: #475569;">{$txFallback}<br>
    <a href="{$resetUrl}" style="color: #2563eb; word-break: break-all;">{$resetUrl}</a></p>

    {$mobileBlock}

    <div class="security-note">
      {$txSecurity}
    </div>
  </div>
  <div class="email-footer">
    {$txFooter}
  </div>
</div>
</body>
</html>
HTML;

        // Plain-text alternative
        $textBody = str_replace('\n', "\n", sprintf(t('srv_invite.text_body'),
            $displayName, $brandTitle, $user['username'], $extText, $email, $resetUrl, $mobileText, $brandTitle));

        // Email headers (MIME multipart HTML + plain text)
        $boundary = '=_bnd_' . md5(uniqid((string)time(), true));
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>\r\n"
            . "Reply-To: {$fromAddress}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            . "X-Mailer: AiPBX-Invitation/1.0\r\n";

        $messageBody = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . "--{$boundary}--\r\n";

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $mailOk = @mail($email, $encodedSubject, $messageBody, $headers, '-f ' . $fromAddress);

        if ($mailOk) {
            writeAuditLog(null, 'system_users', $userId, "Activation/sign-in e-mail sent: {$user['username']} ({$email})", 'mail_sent', $_SESSION['user_id'] ?? null);
            return [
                'success' => true,
                'message' => sprintf(t('srv_invite.sent'), $user['username'], $email),
                'token' => $token
            ];
        }

        $lastErr = error_get_last()['message'] ?? t('srv_invite.err_no_response');
        return [
            'success' => false,
            'error' => sprintf(t('srv_invite.err_send'), $email, $lastErr)
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
