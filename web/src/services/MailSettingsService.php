<?php
require_once __DIR__ . '/../file_helper.php';
require_once __DIR__ . '/../priv_helper.php';

class MailSettingsService
{
    public static function saveSettings(array $data): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $host = trim($data['mail_relay_host'] ?? '');
        $port = (int)($data['mail_smtp_port'] ?? 25);
        if ($port <= 0 || $port > 65535) {
            $port = 25;
        }
        $security = trim($data['mail_smtp_security'] ?? 'none');
        if (!in_array($security, ['none', 'tls', 'ssl'], true)) {
            $security = 'none';
        }
        $auth = trim($data['mail_smtp_auth'] ?? 'no');
        $auth = ($auth === 'yes') ? 'yes' : 'no';
        $user = trim($data['mail_smtp_user'] ?? '');
        $pass = trim($data['mail_smtp_pass'] ?? '');

        $from_address = trim($data['mail_from_address'] ?? 'no-reply@example.com');
        $from_name = trim($data['mail_from_name'] ?? 'AI PBX');
        $fax_from_address = trim($data['fax_email_from_address'] ?? 'fax@example.com');
        $fax_from_name = trim($data['fax_email_from_name'] ?? 'AI PBX Faks Sistemi');
        $sync_postfix = !empty($data['mail_sync_postfix']) ? 'yes' : 'no';

        $db = getDB();
        $settings = [
            'mail_relay_host' => $host,
            'mail_smtp_port' => (string)$port,
            'mail_smtp_security' => $security,
            'mail_smtp_auth' => $auth,
            'mail_smtp_user' => $user,
            'mail_from_address' => $from_address,
            'mail_from_name' => $from_name,
            'portal_email_from_address' => $from_address,
            'portal_email_from_name' => $from_name,
            'fax_email_from_address' => $fax_from_address,
            'fax_email_from_name' => $fax_from_name,
            'mail_sync_postfix' => $sync_postfix,
        ];

        // Only update password if not blank
        if ($pass !== '') {
            $settings['mail_smtp_pass'] = $pass;
        }

        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Postfix system sync
        $postfix_msg = '';
        if ($sync_postfix === 'yes') {
            $current_pass = $pass;
            if ($current_pass === '') {
                $current_pass = (string)getSystemSetting('mail_smtp_pass', '');
            }
            $postfix_res = self::syncPostfix($host, $port, $security, $auth, $user, $current_pass);
            if (!$postfix_res['success']) {
                $postfix_msg = ' (Uyarı: Postfix güncellenirken hata: ' . $postfix_res['error'] . ')';
            }
        }

        writeAuditLog(null, 'mail_settings', 'general', 'E-Posta & Mail Relay ayarları güncellendi', 'update', $_SESSION['user_id'] ?? null);

        return ['success' => true, 'message' => 'E-Posta ve Mail Relay ayarları başarıyla kaydedildi!' . $postfix_msg];
    }

    public static function syncPostfix(string $host, int $port, string $security, string $auth, string $user, string $pass): array
    {
        // Postfix ayarları root yetkisiyle PrivHelper (aipbx-priv `postfix` alt
        // komutları) üzerinden yazılıyor; her adım sabit bir postconf işlemi.
        if (empty($host)) {
            PrivHelper::run(['postfix', 'relay-clear']);
            PrivHelper::run(['service', 'reload', 'postfix']);
            return ['success' => true];
        }

        // Host sasl_passwd satırına ve relayhost değerine giriyor — boşluk,
        // satır sonu, köşeli parantez vb. her iki dosyanın söz dizimini bozar.
        if (!preg_match('/^[A-Za-z0-9.:_-]{1,253}$/', $host)) {
            return ['success' => false, 'error' => 'Geçersiz relay sunucu adı: ' . $host];
        }

        $relay_spec = '[' . $host . ']:' . $port;
        $steps = [
            ['postfix', 'relay', $host, (string) $port],
            ['postfix', 'tls', ($security === 'tls' || $security === 'ssl') ? 'may' : 'none'],
        ];

        if ($auth === 'yes' && !empty($user)) {
            // sasl_passwd satır tabanlı: satır sonu içeren bir değer dosyaya
            // yeni kayıt ekleyebilirdi.
            if (preg_match('/[\x00-\x1f\x7f]/', $user . $pass)) {
                return ['success' => false, 'error' => 'SMTP kullanıcı adı/parola kontrol karakteri içeremez.'];
            }
            $steps[] = ['postfix', 'sasl', 'on'];
            if (!empty($pass)) {
                $line = $relay_spec . ' ' . $user . ':' . $pass . "\n";
                FileHelper::writeFile('/etc/postfix/sasl_passwd', $line, null, null, 0660);
                $steps[] = ['postfix', 'postmap'];
            }
        } else {
            $steps[] = ['postfix', 'sasl', 'off'];
        }
        $steps[] = ['service', 'reload', 'postfix'];

        foreach ($steps as $step) {
            $res = PrivHelper::run($step);
            if (!$res['success']) {
                return ['success' => false, 'error' => $res['output']];
            }
        }

        return ['success' => true];
    }

    public static function sendTestEmail(string $toEmail): array
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Geçersiz alıcı e-posta adresi!'];
        }

        $fromAddress = getSystemSetting('mail_from_address', getSystemSetting('portal_email_from_address', 'no-reply@example.com'));
        $fromName = getSystemSetting('mail_from_name', getSystemSetting('portal_email_from_name', 'AI PBX'));

        $subject = 'AI PBX Test E-Postası (' . date('d.m.Y H:i:s') . ')';
        $body = "Sayın Yetkili,\n\n"
              . "Bu e-posta AI PBX Santral & Faks Portalı Mail Relay / SMTP ayarlarını doğrulamak amacıyla gönderilmiştir.\n\n"
              . "Detaylar:\n"
              . "- Tarih/Saat: " . date('d.m.Y H:i:s') . "\n"
              . "- Gönderici: " . $fromName . " <" . $fromAddress . ">\n"
              . "- Alıcı: " . $toEmail . "\n"
              . "- Sunucu: " . (gethostname() ?: 'voice') . "\n\n"
              . "Bu e-postayı aldıysanız, sisteminizin E-Posta / Relay ayarları başarıyla çalışmaktadır.\n\n"
              . "İyi çalışmalar,\nAI PBX İletişim Sistemi";

        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>\r\n"
                 . "Reply-To: {$fromAddress}\r\n"
                 . "X-Mailer: AiPBX-Mailer/1.0\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n";

        $mailOk = @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f ' . $fromAddress);

        if ($mailOk) {
            return ['success' => true, 'message' => "Test e-postası başarıyla gönderim kuyruğuna iletildi ({$toEmail}). Lütfen gelen kutunuzu kontrol ediniz."];
        }

        $lastError = error_get_last()['message'] ?? 'Bilinmeyen posta sistemi hatası';
        return ['success' => false, 'error' => 'E-posta gönderimi başarısız oldu: ' . $lastError];
    }
}
