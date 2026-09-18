<?php

require_once __DIR__ . '/../repositories/MsTeamsRepository.php';

class MsTeamsService
{
    /**
     * Direct Routing ayarlarını doğrular ve kaydeder.
     */
    public static function saveDirectRoutingSettings(array $post): array
    {
        $enabled = !empty($post['teams_enabled']) ? '1' : '0';
        $domain = trim($post['teams_domain'] ?? '');
        $port = trim($post['teams_sip_port'] ?? '5061');
        $certPath = trim($post['teams_tls_cert_path'] ?? '');
        $keyPath = trim($post['teams_tls_key_path'] ?? '');
        $sbcName = trim($post['teams_sbc_name'] ?? '');

        if ($enabled === '1' && $domain === '') {
            return ['success' => false, 'error' => t('ms_teams.msg_err_domain_required')];
        }

        if ($domain !== '' && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}$/', $domain)) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_domain')];
        }

        $portInt = (int)$port;
        if ($portInt < 1 || $portInt > 65535) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_port')];
        }

        $settings = [
            'teams_enabled'       => $enabled,
            'teams_domain'        => $domain,
            'teams_sip_port'      => (string)$portInt,
            'teams_tls_cert_path' => $certPath,
            'teams_tls_key_path'  => $keyPath,
            'teams_sbc_name'      => $sbcName !== '' ? $sbcName : $domain,
        ];

        MsTeamsRepository::saveSettings($settings);

        return [
            'success' => true,
            'message' => t('ms_teams.msg_save_dr_success'),
        ];
    }

    /**
     * Webhook bildirim ayarlarını doğrular ve kaydeder.
     */
    public static function saveWebhookSettings(array $post): array
    {
        $enabled = !empty($post['teams_webhook_enabled']) ? '1' : '0';
        $webhookUrl = trim($post['teams_webhook_url'] ?? '');

        if ($enabled === '1' && $webhookUrl === '') {
            return ['success' => false, 'error' => t('ms_teams.msg_err_webhook_required')];
        }

        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_webhook_url')];
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, 'https://')) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_webhook_url')];
        }

        $settings = [
            'teams_webhook_enabled'     => $enabled,
            'teams_webhook_url'         => $webhookUrl,
            'teams_notify_missed_calls' => !empty($post['teams_notify_missed_calls']) ? '1' : '0',
            'teams_notify_voicemail'    => !empty($post['teams_notify_voicemail']) ? '1' : '0',
            'teams_notify_queue_alerts' => !empty($post['teams_notify_queue_alerts']) ? '1' : '0',
            'teams_notify_cdr_summary'  => !empty($post['teams_notify_cdr_summary']) ? '1' : '0',
            'teams_notify_fax'          => !empty($post['teams_notify_fax']) ? '1' : '0',
        ];

        MsTeamsRepository::saveSettings($settings);

        return [
            'success' => true,
            'message' => t('ms_teams.msg_save_webhook_success'),
        ];
    }

    /**
     * Microsoft Teams Webhook adresine test bildirimi gönderir.
     */
    public static function sendTestWebhook(string $webhookUrl): array
    {
        if ($webhookUrl === '' || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_webhook_url')];
        }

        $payload = [
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'summary'    => 'AiPBX - Microsoft Teams Entegrasyon Testi',
            'themeColor' => '6264A7',
            'title'      => 'AiPBX & Microsoft Teams Bağlantı Testi',
            'sections'   => [
                [
                    'activityTitle'    => 'Santral Bildirim Servisi Doğrulaması',
                    'activitySubtitle' => 'AiPBX Kurumsal İletişim Platformu',
                    'activityImage'    => 'https://raw.githubusercontent.com/mahirgul/AiPBX/main/docs/logo.png',
                    'facts'            => [
                        ['name' => 'Durum:', 'value' => '✅ Aktif & Çalışıyor (200 OK)'],
                        ['name' => 'Zaman:', 'value' => date('d.m.Y H:i:s')],
                        ['name' => 'Sunucu:', 'value' => gethostname() ?: 'AiPBX Gateway'],
                        ['name' => 'Bildirim Türü:', 'value' => 'Sistem Test Mesajı'],
                    ],
                    'text'             => 'Tebrikler! AiPBX ile Microsoft Teams kanalınız arasındaki Webhook bağlantısı başarıyla kuruldu. Cevapsız çağrılar, sesli mesajlar ve kuyruk uyarıları bu kanala iletilecektir.',
                ],
            ],
            'potentialAction' => [
                [
                    '@type'   => 'OpenURI',
                    'name'    => 'AiPBX Paneline Git',
                    'targets' => [
                        ['os' => 'default', 'uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ms-teams'],
                    ],
                ],
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_curl') . $curlError];
        }

        if ($httpCode === 200 || trim((string)$response) === '1') {
            return ['success' => true, 'message' => t('ms_teams.msg_webhook_test_success')];
        }

        return [
            'success' => false,
            'error'   => t('ms_teams.msg_err_teams_response') . "(HTTP {$httpCode}): " . htmlspecialchars((string)$response),
        ];
    }

    /**
     * TLS Sertifikasının dosya varlığını, geçerliliğini ve kalan gün sayısını kontrol eder.
     */
    public static function inspectTlsCert(string $certPath): array
    {
        if ($certPath === '' || !is_file($certPath)) {
            return [
                'exists'     => false,
                'message'    => t('ms_teams.cert_not_found'),
                'color'      => 'warning',
                'details'    => null,
            ];
        }

        $content = @file_get_contents($certPath);
        if (!$content) {
            return [
                'exists'     => false,
                'message'    => t('ms_teams.cert_unreadable'),
                'color'      => 'danger',
                'details'    => null,
            ];
        }

        $parsed = @openssl_x509_parse($content);
        if (!$parsed) {
            return [
                'exists'     => true,
                'message'    => t('ms_teams.cert_invalid'),
                'color'      => 'danger',
                'details'    => null,
            ];
        }

        $validToTimestamp = $parsed['validTo_time_t'] ?? 0;
        $now = time();
        $daysRemaining = (int)floor(($validToTimestamp - $now) / 86400);

        $cn = $parsed['subject']['CN'] ?? ($parsed['subject']['commonName'] ?? 'Bilinmiyor');
        $issuer = $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? 'Bilinmiyor');

        $sans = [];
        if (!empty($parsed['extensions']['subjectAltName'])) {
            $sans = array_map('trim', explode(',', $parsed['extensions']['subjectAltName']));
        }

        $isExpired = $daysRemaining <= 0;
        $isExpiringSoon = $daysRemaining > 0 && $daysRemaining <= 30;

        return [
            'exists'         => true,
            'cn'             => $cn,
            'issuer'         => $issuer,
            'valid_from'     => date('d.m.Y', $parsed['validFrom_time_t'] ?? 0),
            'valid_to'       => date('d.m.Y', $validToTimestamp),
            'days_remaining' => $daysRemaining,
            'sans'           => $sans,
            'is_expired'     => $isExpired,
            'color'          => $isExpired ? 'danger' : ($isExpiringSoon ? 'warning' : 'success'),
            'message'        => $isExpired
                ? "Sertifika süresi dolmuş! ({$daysRemaining} gün önce)"
                : ($isExpiringSoon ? "Sertifika yakında dolacak ({$daysRemaining} gün kaldı)" : "Sertifika geçerli ({$daysRemaining} gün kaldı)"),
        ];
    }

    /**
     * Microsoft 365 PowerShell yapılandırma scriptini dinamik üretir.
     */
    public static function generatePowerShellScript(array $settings, array $mappings): string
    {
        $domain = !empty($settings['teams_domain']) ? $settings['teams_domain'] : 'sbc.aipbx.com';
        $port = !empty($settings['teams_sip_port']) ? $settings['teams_sip_port'] : '5061';
        $pstnUsage = 'AiPBX-PSTN';
        $voiceRoute = 'AiPBX-DirectRoute';
        $voicePolicy = 'AiPBX-VoicePolicy';

        $output = [];
        $output[] = "# ==============================================================================";
        $output[] = "# AiPBX - Microsoft Teams Direct Routing Yapilandirma Scripti";
        $output[] = "# Olusturuldu: " . date('Y-m-d H:i:s');
        $output[] = "# SBC FQDN: {$domain} (Port: {$port})";
        $output[] = "# ==============================================================================";
        $output[] = "";
        $output[] = "# 1. Microsoft Teams PowerShell Modulunu Kurun ve Baglanin (Gerekiyorsa)";
        $output[] = "if (-not (Get-Module -ListAvailable -Name MicrosoftTeams)) {";
        $output[] = "    Write-Host 'MicrosoftTeams modulu yukleniyor...' -ForegroundColor Cyan";
        $output[] = "    Install-Module -Name MicrosoftTeams -Scope CurrentUser -Force -AllowClobber";
        $output[] = "}";
        $output[] = "Write-Host 'Microsoft 365 hesabina baglaniliyor...' -ForegroundColor Cyan";
        $output[] = "Connect-MicrosoftTeams";
        $output[] = "";
        $output[] = "# 2. AiPBX SBC Gateway Tanimlama";
        $output[] = "Write-Host 'SBC PSTN Gateway tanimlaniyor: {$domain}:{$port}' -ForegroundColor Cyan";
        $output[] = "\$existingSbc = Get-CsOnlinePSTNGateway -Identity '{$domain}' -ErrorAction SilentlyContinue";
        $output[] = "if (\$null -eq \$existingSbc) {";
        $output[] = "    New-CsOnlinePSTNGateway -Fqdn '{$domain}' `";
        $output[] = "        -SipSignalingPort {$port} `";
        $output[] = "        -MaxConcurrentSessions 100 `";
        $output[] = "        -Enabled \$true `";
        $output[] = "        -ForwardCallHistory \$false `";
        $output[] = "        -ForwardPai \$true";
        $output[] = "} else {";
        $output[] = "    Set-CsOnlinePSTNGateway -Identity '{$domain}' `";
        $output[] = "        -SipSignalingPort {$port} `";
        $output[] = "        -Enabled \$true";
        $output[] = "}";
        $output[] = "";
        $output[] = "# 3. PSTN Kullanimi ve Ses Yonlendirme Politikalari";
        $output[] = "Write-Host 'Ses Yonlendirme Politikasi olusturuluyor...' -ForegroundColor Cyan";
        $output[] = "Set-CsOnlinePstnUsage -Identity Global -Usage @{Add='{$pstnUsage}'} -ErrorAction SilentlyContinue";
        $output[] = "";
        $output[] = "\$existingRoute = Get-CsOnlineVoiceRoute -Identity '{$voiceRoute}' -ErrorAction SilentlyContinue";
        $output[] = "if (\$null -eq \$existingRoute) {";
        $output[] = "    New-CsOnlineVoiceRoute -Identity '{$voiceRoute}' `";
        $output[] = "        -Priority 1 `";
        $output[] = "        -NumberPattern '.*' `";
        $output[] = "        -OnlinePstnGatewayList '{$domain}' `";
        $output[] = "        -OnlinePstnUsages '{$pstnUsage}'";
        $output[] = "}";
        $output[] = "";
        $output[] = "\$existingPolicy = Get-CsOnlineVoiceRoutingPolicy -Identity '{$voicePolicy}' -ErrorAction SilentlyContinue";
        $output[] = "if (\$null -eq \$existingPolicy) {";
        $output[] = "    New-CsOnlineVoiceRoutingPolicy -Identity '{$voicePolicy}' `";
        $output[] = "        -OnlinePstnUsages '{$pstnUsage}' `";
        $output[] = "        -Description 'AiPBX Direct Routing Santral Politikasi'";
        $output[] = "}";
        $output[] = "";
        $output[] = "# 4. Kullanici ve Dahili Eslestirmeleri";

        if (empty($mappings)) {
            $output[] = "# Henuz web arayuzunde kullanici eslestirmesi yapilmamis.";
            $output[] = "# Ornek Kullanici Atama Komutu:";
            $output[] = "# Grant-CsOnlineVoiceRoutingPolicy -Identity 'kullanici@alanadiniz.com' -PolicyName '{$voicePolicy}'";
            $output[] = "# Set-CsPhoneNumberAssignment -Identity 'kullanici@alanadiniz.com' -PhoneNumber '+90212XXXXXXX' -PhoneNumberType DirectRouting";
        } else {
            foreach ($mappings as $m) {
                if (empty($m['direct_routing_enabled'])) continue;
                $upn = $m['teams_upn'];
                $ext = $m['extension'];
                $phone = !empty($m['phone_number']) ? $m['phone_number'] : "+{$ext}";

                $output[] = "Write-Host 'Kullanici ataniyor: {$upn} (Dahili: {$ext})' -ForegroundColor Green";
                $output[] = "Grant-CsOnlineVoiceRoutingPolicy -Identity '{$upn}' -PolicyName '{$voicePolicy}'";
                $output[] = "Set-CsPhoneNumberAssignment -Identity '{$upn}' -PhoneNumber '{$phone}' -PhoneNumberType DirectRouting";
            }
        }

        $output[] = "";
        $output[] = "# 5. Durum Kontrolu";
        $output[] = "Write-Host '--- SBC Gateway Durumu ---' -ForegroundColor Yellow";
        $output[] = "Get-CsOnlinePSTNGateway -Identity '{$domain}' | Format-List Fqdn, SipSignalingPort, Enabled, Status";
        $output[] = "Write-Host 'Kurulum tamamlandi! AiPBX ile Teams Direct Routing baglantiniz hazir.' -ForegroundColor Green";

        return implode("\n", $output);
    }
}
