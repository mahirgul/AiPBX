<?php
/**
 * Force Password Reset (Zorunlu Şifre Sıfırlama - Mail Gönderimi) Service
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
            return ['sent' => false, 'error' => 'Güvenlik doğrulaması (CSRF) başarısız! Lütfen sayfayı yenileyip tekrar deneyin.'];
        }
        if (empty($user['email'])) {
            return ['sent' => false, 'error' => 'Bu hesapta kayıtlı bir e-posta adresi yok. Sıfırlama için sistem yöneticisiyle iletişime geçin.'];
        }

        $db = getDB();

        // Yakın zamanda gönderilmiş, hâlâ geçerli bir token varsa yeniden gönderme (spam engeli)
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

        $fromAddress = preg_replace('/[\r\n]+/', '', getSystemSetting('portal_email_from_address', 'no-reply@example.com'));
        $fromName = preg_replace('/[\r\n]+/', '', getSystemSetting('portal_email_from_name', 'AI PBX Portalı'));
        $brandTitle = getSystemSetting('brand_title', 'AI PBX');

        $subject = 'Şifre Sıfırlama Bağlantınız';
        $body = "Merhaba " . $user['username'] . ",\r\n\r\n"
            . $brandTitle . " hesabınız için şifre sıfırlama talebi alındı.\r\n"
            . "Aşağıdaki bağlantıya tıklayarak yeni bir şifre belirleyebilirsiniz. Bağlantı 30 dakika içinde geçerliliğini yitirecektir:\r\n\r\n"
            . $resetUrl . "\r\n\r\n"
            . "Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz.\r\n";

        $headers = "From: " . $fromName . " <" . $fromAddress . ">\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        $mailOk = @mail($user['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f ' . $fromAddress);

        if ($mailOk) {
            return ['sent' => true];
        }
        return ['sent' => false, 'error' => 'E-posta gönderilemedi. Lütfen daha sonra tekrar deneyin veya sistem yöneticisiyle iletişime geçin.'];
    }
}
