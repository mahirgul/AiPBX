<?php

require_once __DIR__ . '/../repositories/MsTeamsRepository.php';
require_once __DIR__ . '/ForgotPasswordService.php';

/**
 * Integration → Microsoft Teams: Direct Routing settings (SBC address, TLS
 * certificate check), the call notification webhook, and a PowerShell script for the
 * Microsoft 365 side generated from the user mappings.
 */
class MsTeamsService
{
    /**
     * Validates that a webhook URL is a valid HTTPS URL whose host resolves exclusively
     * to public IP addresses (rejects loopback, private, link-local, and reserved ranges).
     *
     * @param array<int, string>|null $curlResolve Populated with host:port:ip for CURLOPT_RESOLVE
     */
    public static function validatePublicWebhookUrl(string $webhookUrl, ?array &$curlResolve = null, bool $requireDnsResolution = true): bool
    {
        $curlResolve = [];
        if ($webhookUrl === '' || !str_starts_with($webhookUrl, 'https://') || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($webhookUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = trim((string)($parts['host'] ?? ''), '[]');
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $lowerHost = strtolower($host);
        if ($lowerHost === 'localhost' || str_ends_with($lowerHost, '.localhost') || str_ends_with($lowerHost, '.local') || str_ends_with($lowerHost, '.internal')) {
            return false;
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port < 1) {
            return false;
        }

        $isPublicIp = static function (string $ip): bool {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
            if (str_starts_with($ip, '169.254.') || str_starts_with($ip, '127.') || $ip === '::1') {
                return false;
            }
            $lowerIp = strtolower($ip);
            if (str_starts_with($lowerIp, 'fe80:') || str_starts_with($lowerIp, 'fc') || str_starts_with($lowerIp, 'fd')) {
                return false;
            }
            return true;
        };

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $isPublicIp($host);
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}$/', $host)) {
            return false;
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!empty($rec['ip']) && is_string($rec['ip'])) {
                    $ips[] = $rec['ip'];
                } elseif (!empty($rec['ipv6']) && is_string($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            $v4s = @gethostbynamel($host);
            if (is_array($v4s)) {
                $ips = $v4s;
            }
        }
        if (empty($ips)) {
            return !$requireDnsResolution;
        }
        foreach ($ips as $ip) {
            if (!$isPublicIp($ip)) {
                return false;
            }
        }
        $curlResolve = ["{$host}:{$port}:" . $ips[0]];
        return true;
    }

    /**
     * Validates and saves the Direct Routing settings.
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
        writeAuditLog('ms_teams', 'settings', 0, 'MS Teams: direct routing ' . ($enabled === '1' ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);

        return [
            'success' => true,
            'message' => t('ms_teams.msg_save_dr_success'),
        ];
    }

    /**
     * Validates and saves the webhook notification settings.
     */
    public static function saveWebhookSettings(array $post): array
    {
        $enabled = !empty($post['teams_webhook_enabled']) ? '1' : '0';
        $webhookUrl = trim($post['teams_webhook_url'] ?? '');

        if ($enabled === '1' && $webhookUrl === '') {
            return ['success' => false, 'error' => t('ms_teams.msg_err_webhook_required')];
        }

        $dummyResolve = [];
        if ($webhookUrl !== '' && !self::validatePublicWebhookUrl($webhookUrl, $dummyResolve, false)) {
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
        writeAuditLog('ms_teams', 'settings', 0, 'MS Teams: notifications ' . ($enabled === '1' ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);

        return [
            'success' => true,
            'message' => t('ms_teams.msg_save_webhook_success'),
        ];
    }

    /**
     * Sends a test notification to the Microsoft Teams webhook address.
     */
    public static function sendTestWebhook(string $webhookUrl): array
    {
        $curlResolve = [];
        if (!self::validatePublicWebhookUrl($webhookUrl, $curlResolve)) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_webhook_url')];
        }

        $payload = [
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'summary'    => t('srv_teams.test_summary'),
            'themeColor' => '6264A7',
            'title'      => t('srv_teams.test_title'),
            'sections'   => [
                [
                    'activityTitle'    => t('srv_teams.test_activity'),
                    'activitySubtitle' => t('srv_teams.test_subtitle'),
                    'activityImage'    => 'https://raw.githubusercontent.com/mahirgul/AiPBX/main/docs/logo.png',
                    'facts'            => [
                        ['name' => t('srv_teams.f_status'), 'value' => t('srv_teams.f_status_ok')],
                        ['name' => t('srv_teams.f_time'), 'value' => date('d.m.Y H:i:s')],
                        ['name' => t('srv_teams.f_server'), 'value' => gethostname() ?: 'AiPBX Gateway'],
                        ['name' => t('srv_teams.f_type'), 'value' => t('srv_teams.f_type_test')],
                    ],
                    'text'             => t('srv_teams.test_text'),
                ],
            ],
            'potentialAction' => [
                [
                    '@type'   => 'OpenURI',
                    'name'    => t('ms_teams.title'),
                    'targets' => [
                        ['os' => 'default', 'uri' => ForgotPasswordService::portalUrl() . '/ms-teams'],
                    ],
                ],
            ],
        ];

        $jsonPayload = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (!empty($curlResolve)) {
            curl_setopt($ch, CURLOPT_RESOLVE, $curlResolve);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);

        if ($curlErrno !== 0) {
            return ['success' => false, 'error' => rtrim(t('ms_teams.msg_err_curl'), ': ')];
        }

        if ($httpCode === 200 || trim((string)$response) === '1') {
            return ['success' => true, 'message' => t('ms_teams.msg_webhook_test_success')];
        }

        return [
            'success' => false,
            'error'   => rtrim(t('ms_teams.msg_err_teams_response'), ': ') . " (HTTP {$httpCode})",
        ];
    }

    /**
     * Checks that the TLS certificate file exists, is valid, and how many days are left.
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

        $cn = $parsed['subject']['CN'] ?? ($parsed['subject']['commonName'] ?? t('ms_teams.cert_unknown'));
        $issuer = $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? t('ms_teams.cert_unknown'));

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
                ? sprintf(t('srv_teams.cert_expired'), $daysRemaining)
                : ($isExpiringSoon ? sprintf(t('srv_teams.cert_soon'), $daysRemaining) : sprintf(t('srv_teams.cert_ok'), $daysRemaining)),
        ];
    }

    /**
     * Generates the Microsoft 365 PowerShell configuration script dynamically.
     */
    public static function generatePowerShellScript(array $settings, array $mappings): string
    {
        $psSingleQuote = static fn(string $val): string => str_replace("'", "''", preg_replace('/[\x00-\x1F\x7F]+/', '', $val) ?? '');
        $psComment = static fn(string $val): string => trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $val) ?? '');

        $rawDomain = !empty($settings['teams_domain']) ? (string)$settings['teams_domain'] : 'sbc.aipbx.com';
        $domain = $psSingleQuote(preg_replace('/[^a-zA-Z0-9.\-]/', '', $rawDomain) ?: 'sbc.aipbx.com');
        $portInt = !empty($settings['teams_sip_port']) ? (int)$settings['teams_sip_port'] : 5061;
        $port = (string)(($portInt >= 1 && $portInt <= 65535) ? $portInt : 5061);
        $pstnUsage = 'AiPBX-PSTN';
        $voiceRoute = 'AiPBX-DirectRoute';
        $voicePolicy = 'AiPBX-VoicePolicy';

        $output = [];
        $output[] = "# ==============================================================================";
        $output[] = "# AiPBX - Microsoft Teams Direct Routing configuration script";
        $output[] = "# Generated: " . date('Y-m-d H:i:s');
        $output[] = "# SBC FQDN: {$domain} (Port: {$port})";
        $output[] = "# ==============================================================================";
        $output[] = "";
        $output[] = "# 1. Install and connect the Microsoft Teams PowerShell module (if needed)";
        $output[] = "if (-not (Get-Module -ListAvailable -Name MicrosoftTeams)) {";
        $output[] = "    Write-Host 'Installing the MicrosoftTeams module...' -ForegroundColor Cyan";
        $output[] = "    Install-Module -Name MicrosoftTeams -Scope CurrentUser -Force -AllowClobber";
        $output[] = "}";
        $output[] = "Write-Host 'Connecting to Microsoft 365...' -ForegroundColor Cyan";
        $output[] = "Connect-MicrosoftTeams";
        $output[] = "";
        $output[] = "# 2. Define AiPBX SBC gateway";
        $output[] = "Write-Host 'Defining SBC PSTN gateway: {$domain}:{$port}' -ForegroundColor Cyan";
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
        $output[] = "# 3. PSTN usage and voice routing policies";
        $output[] = "Write-Host 'Creating the voice routing policy...' -ForegroundColor Cyan";
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
        $output[] = "        -Description 'AiPBX Direct Routing PBX Policy'";
        $output[] = "}";
        $output[] = "";
        $output[] = "# 4. User and extension mappings";

        if (empty($mappings)) {
            $output[] = "# No user mapping has been set up in the portal yet.";
            $output[] = "# Example user assignment command:";
            $output[] = "# Grant-CsOnlineVoiceRoutingPolicy -Identity 'user@yourdomain.com' -PolicyName '{$voicePolicy}'";
            $output[] = "# Set-CsPhoneNumberAssignment -Identity 'user@yourdomain.com' -PhoneNumber '+90212XXXXXXX' -PhoneNumberType DirectRouting";
        } else {
            foreach ($mappings as $m) {
                if (empty($m['direct_routing_enabled'])) continue;
                $upn = $psSingleQuote((string)($m['teams_upn'] ?? ''));
                $ext = $psSingleQuote(preg_replace('/[^0-9]/', '', (string)($m['extension'] ?? '')) ?? '');
                $rawPhone = !empty($m['phone_number']) ? (string)$m['phone_number'] : "+{$ext}";
                $phone = $psSingleQuote(preg_replace('/[^0-9+]/', '', $rawPhone) ?? '');
                $displayName = $psComment((string)($m['display_name'] ?? $m['full_name'] ?? ''));
                if ($displayName !== '') {
                    $output[] = "# {$displayName} (Extension: {$ext})";
                }

                $output[] = "Write-Host 'Assigning user: {$upn} (extension: {$ext})' -ForegroundColor Green";
                $output[] = "Grant-CsOnlineVoiceRoutingPolicy -Identity '{$upn}' -PolicyName '{$voicePolicy}'";
                $output[] = "Set-CsPhoneNumberAssignment -Identity '{$upn}' -PhoneNumber '{$phone}' -PhoneNumberType DirectRouting";
            }
        }

        $output[] = "";
        $output[] = "# 5. Status check";
        $output[] = "Write-Host '--- SBC Gateway Status ---' -ForegroundColor Yellow";
        $output[] = "Get-CsOnlinePSTNGateway -Identity '{$domain}' | Format-List Fqdn, SipSignalingPort, Enabled, Status";
        $output[] = "Write-Host 'Done! Your AiPBX - Teams Direct Routing connection is ready.' -ForegroundColor Green";

        return implode("\n", $output);
    }
}
