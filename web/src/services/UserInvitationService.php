<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * User Invitation & Activation Mail Service
 * Yeni kullanıcı aktivasyonu ve şifre belirleme e-postalarını yönetir.
 */
class UserInvitationService
{
    /**
     * Bireysel kullanıcıya aktivasyon / şifre belirleme e-postası gönderir.
     *
     * @param int $userId sys_users tablosundaki kullanıcı ID'si
     * @param bool $isNew Yeni oluşturulan kullanıcı mı?
     * @return array{success: bool, message?: string, error?: string, token?: string}
     */
    public static function sendInvitationEmail(int $userId, bool $isNew = false): array
    {
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Geçersiz kullanıcı ID!'];
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, full_name, email, extension, is_active FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'Kullanıcı bulunamadı.'];
        }

        $email = trim($user['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => "Kullanıcının ({$user['username']}) kayıtlı geçerli bir e-posta adresi bulunmuyor."
            ];
        }

        if (empty($user['is_active'])) {
            return [
                'success' => false,
                'error' => "Kullanıcı hesabı ({$user['username']}) devre dışıdır."
            ];
        }

        // Güvenli 256-bit rastgele token üret (48 saat geçerli)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (86400 * 2)); // 48 saat

        // Veritabanına kaydet ve must_reset_password bayrağını etkinleştir
        $upd = $db->prepare('UPDATE sys_users SET reset_token = ?, reset_token_expires = ?, must_reset_password = 1 WHERE id = ?');
        $upd->execute([$token, $expires, $userId]);

        // Bağlantı URL'sini oluştur
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
        $resetUrl = $scheme . '://' . $host . '/reset-password?token=' . $token;

        // Posta ve Sistem Ayarları
        $fromAddress = preg_replace('/[\r\n]+/', '', getSystemSetting('mail_from_address', getSystemSetting('portal_email_from_address', 'no-reply@example.com')));
        $fromName = preg_replace('/[\r\n]+/', '', getSystemSetting('mail_from_name', getSystemSetting('portal_email_from_name', 'AI PBX')));
        $brandTitle = getSystemSetting('brand_title', 'AI PBX');
        $brandSub = getSystemSetting('brand_sub', 'Kurumsal İletişim Platformu');

        $subject = $isNew
            ? "[{$brandTitle}] Hesabınız Oluşturuldu - Giriş ve Şifre Belirleme Bağlantınız"
            : "[{$brandTitle}] Giriş ve Şifre Belirleme Bağlantınız";

        $displayName = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
        $extInfo = !empty($user['extension']) ? "<li><strong>Dahili Numaranız:</strong> {$user['extension']}</li>" : "";
        $extText = !empty($user['extension']) ? "- Dahili Numaranız: {$user['extension']}\n" : "";

        // HTML E-posta Gövdesi
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="tr">
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
    <div class="greeting">Merhaba {$displayName},</div>
    <p>{$brandTitle} iletişim santralinde hesabınız tanımlanmıştır. Sisteme güvenli bir şekilde giriş yapabilmek için lütfen aşağıdaki butona tıklayarak parolanızı belirleyin:</p>
    
    <div class="info-box">
      <strong>Hesap Bilgileriniz:</strong>
      <ul>
        <li><strong>Kullanıcı Adı:</strong> {$user['username']}</li>
        {$extInfo}
        <li><strong>E-Posta:</strong> {$email}</li>
      </ul>
    </div>

    <div class="btn-container">
      <a href="{$resetUrl}" class="btn" target="_blank">Şifrenizi Belirleyin ve Giriş Yapın</a>
    </div>

    <p style="font-size: 13px; color: #475569;">Buton çalışmıyorsa aşağıdaki bağlantıyı tarayıcınızın adres çubuğuna yapıştırabilirsiniz:<br>
    <a href="{$resetUrl}" style="color: #2563eb; word-break: break-all;">{$resetUrl}</a></p>

    <div class="qr-tip-box">
      <strong>📱 Mobil Uygulama ile Hızlı Giriş:</strong><br>
      Web portalına giriş yaptıktan sonra <strong>"Dahilim (My Phone)"</strong> ekranındaki <strong>QR Kodu</strong> Android ve iOS cep telefonunuzdaki AiPBX mobil uygulamasına okutarak tek dokunuşla şifresiz giriş yapabilirsiniz.
    </div>

    <div class="security-note">
      🔒 <strong>Güvenlik Notu:</strong> Bu şifre belirleme bağlantısı <strong>48 saat</strong> boyunca geçerlidir ve tek kullanımlıktır. Bu e-postayı siz talep etmediyseniz veya beklemiyorsanız sistem yöneticiniz ile iletişime geçiniz.
    </div>
  </div>
  <div class="email-footer">
    &copy; {$brandTitle} - Tüm Hakları Saklıdır. Bu otomatik bir bilgilendirme e-postasıdır.
  </div>
</div>
</body>
</html>
HTML;

        // Düz Metin Alternatifi
        $textBody = "Merhaba {$displayName},\n\n"
            . "{$brandTitle} hesabınız oluşturuldu / şifre belirleme talebiniz alındı.\n\n"
            . "Hesap Bilgileriniz:\n"
            . "- Kullanıcı Adı: {$user['username']}\n"
            . $extText
            . "- E-Posta: {$email}\n\n"
            . "Aşağıdaki bağlantıya tıklayarak şifrenizi belirleyebilirsiniz:\n"
            . "{$resetUrl}\n\n"
            . "Bu bağlantı 48 saat boyunca geçerlidir ve tek kullanımlıktır.\n\n"
            . "Mobil Uygulama: Web portalına giriş yaptıktan sonra 'Dahilim' ekranından QR kod üreterek mobil uygulamanıza tek dokunuşla giriş yapabilirsiniz.\n\n"
            . "İyi çalışmalar,\n{$brandTitle}";

        // E-Posta Başlıkları (MIME Multipart HTML + Plain Text)
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
            writeAuditLog(null, 'system_users', $userId, "Aktivasyon/Giriş maili gönderildi: {$user['username']} ({$email})", 'mail_sent', $_SESSION['user_id'] ?? null);
            return [
                'success' => true,
                'message' => "'{$user['username']}' kullanıcısına ({$email}) aktivasyon ve şifre belirleme maili başarıyla iletildi.",
                'token' => $token
            ];
        }

        $lastErr = error_get_last()['message'] ?? 'E-posta servisi yanıt vermedi';
        return [
            'success' => false,
            'error' => "E-posta gönderimi başarısız oldu ({$email}): {$lastErr}"
        ];
    }

    /**
     * Seçilen birden fazla kullanıcıya toplu aktivasyon / şifre belirleme maili gönderir.
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
                if (str_contains($res['error'] ?? '', 'geçerli bir e-posta adresi bulunmuyor')) {
                    $skippedCount++;
                } else {
                    $failedCount++;
                    $errors[] = $res['error'] ?? "ID {$userId} için hata";
                }
            }
        }

        $msgParts = [];
        if ($sentCount > 0) {
            $msgParts[] = "{$sentCount} kullanıcıya giriş ve şifre belirleme maili başarıyla gönderildi.";
        }
        if ($skippedCount > 0) {
            $msgParts[] = "{$skippedCount} kullanıcının e-posta adresi olmadığı için atlandı.";
        }
        if ($failedCount > 0) {
            $msgParts[] = "{$failedCount} kullanıcıya gönderimde hata oluştu (" . implode(', ', array_slice($errors, 0, 3)) . ").";
        }

        if (empty($msgParts)) {
            return [
                'success' => false,
                'sent_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'message' => 'Gönderim yapılacak kullanıcı seçilmedi.'
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
