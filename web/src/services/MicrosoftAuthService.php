<?php
require_once __DIR__ . '/../core/PluginManager.php';
require_once __DIR__ . '/GoogleAuthService.php';
require_once __DIR__ . '/ForgotPasswordService.php';

/**
 * Microsoft Entra ID / Microsoft 365 OAuth 2.0 Sign-In Service.
 */
class MicrosoftAuthService
{
    public const SLUG = 'microsoft-auth';

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        return PluginManager::getAllSettings(self::SLUG);
    }

    public static function isEnabled(): bool
    {
        if (!PluginManager::isEnabled(self::SLUG)) {
            return false;
        }
        $s = self::settings();
        return (($s['enabled'] ?? '0') === '1') && trim((string) ($s['client_id'] ?? '')) !== '';
    }

    public static function getTenant(): string
    {
        $t = trim((string) (self::settings()['tenant_id'] ?? 'common'));
        return preg_match('/^[a-zA-Z0-9._-]+$/', $t) ? $t : 'common';
    }

    public static function getRedirectUri(): string
    {
        return rtrim(ForgotPasswordService::portalUrl(), '/') . '/auth/microsoft/callback';
    }

    public static function getAuthUrl(): string
    {
        $s = self::settings();
        $state = bin2hex(random_bytes(24));
        $_SESSION['microsoft_oauth_state'] = $state;
        $_SESSION['microsoft_oauth_state_at'] = time();

        $params = [
            'client_id'     => trim((string) ($s['client_id'] ?? '')),
            'response_type' => 'code',
            'redirect_uri'  => self::getRedirectUri(),
            'response_mode' => 'query',
            'scope'         => 'openid profile email User.Read',
            'state'         => $state,
            'prompt'        => 'select_account',
        ];
        return 'https://login.microsoftonline.com/' . rawurlencode(self::getTenant()) . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    public static function verifyState(string $state): bool
    {
        $expected = (string) ($_SESSION['microsoft_oauth_state'] ?? '');
        $at = (int) ($_SESSION['microsoft_oauth_state_at'] ?? 0);
        unset($_SESSION['microsoft_oauth_state'], $_SESSION['microsoft_oauth_state_at']);
        if ($state === '' || $expected === '' || (time() - $at) > 600) {
            return false;
        }
        return hash_equals($expected, $state);
    }

    /** @return array<string, mixed>|null */
    public static function exchangeCode(string $code): ?array
    {
        $s = self::settings();
        $url = 'https://login.microsoftonline.com/' . rawurlencode(self::getTenant()) . '/oauth2/v2.0/token';
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => trim((string) ($s['client_id'] ?? '')),
                'client_secret' => trim((string) ($s['client_secret'] ?? '')),
                'code'          => $code,
                'redirect_uri'  => self::getRedirectUri(),
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Extract user email and display name from id_token claims or Microsoft Graph.
     *
     * @param array<string, mixed> $tokenData
     * @return array{email: string, name: string}|null
     */
    public static function resolveProfile(array $tokenData): ?array
    {
        $email = '';
        $name = '';

        if (!empty($tokenData['id_token']) && is_string($tokenData['id_token'])) {
            $parts = explode('.', $tokenData['id_token']);
            if (count($parts) === 3) {
                $b64 = strtr($parts[1], '-_', '+/');
                $pad = strlen($b64) % 4;
                if ($pad > 0) {
                    $b64 .= str_repeat('=', 4 - $pad);
                }
                $claims = json_decode((string) base64_decode($b64), true);
                if (is_array($claims)) {
                    if (!self::isClaimsEmailVerified($claims)) {
                        return null;
                    }
                    $email = trim((string) ($claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? ''));
                    $name = trim((string) ($claims['name'] ?? ''));
                }
            }
        }

        if ($email === '' && !empty($tokenData['access_token'])) {
            $ch = curl_init('https://graph.microsoft.com/oidc/userinfo');
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . (string) $tokenData['access_token']],
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($raw) && $code >= 200 && $code < 300) {
                    $info = json_decode($raw, true);
                    if (is_array($info)) {
                        if (!self::isClaimsEmailVerified($info)) {
                            return null;
                        }
                        $email = trim((string) ($info['email'] ?? $info['preferred_username'] ?? ''));
                        if ($name === '') {
                            $name = trim((string) ($info['name'] ?? ''));
                        }
                    }
                }
            }
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return ['email' => strtolower($email), 'name' => $name !== '' ? $name : $email];
    }

    /**
     * Verify email ownership claims from Entra ID tokens, enforcing email_verified / xms_edov on multi-tenant endpoints.
     *
     * @param array<string, mixed> $claims
     */
    private static function isClaimsEmailVerified(array $claims): bool
    {
        $truthy = [true, 'true', 1, '1'];
        if (array_key_exists('email_verified', $claims) && !in_array($claims['email_verified'], $truthy, true)) {
            return false;
        }
        if (array_key_exists('xms_edov', $claims) && !in_array($claims['xms_edov'], $truthy, true)) {
            return false;
        }
        if (in_array(strtolower(self::getTenant()), ['common', 'consumers'], true)) {
            return in_array($claims['email_verified'] ?? null, $truthy, true)
                || in_array($claims['xms_edov'] ?? null, $truthy, true);
        }
        return true;
    }

    public static function isDomainAllowed(string $email): bool
    {
        $raw = trim((string) (self::settings()['allowed_domains'] ?? ''));
        if ($raw === '') {
            return true;
        }
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $allowed = array_filter(array_map(static fn($d) => strtolower(trim($d)), explode(',', $raw)));
        return in_array($domain, $allowed, true);
    }

    /** @return array<string, mixed>|null */
    public static function findOrProvisionUser(string $email, string $fullName): ?array
    {
        require_once __DIR__ . '/SsoOnlyService.php';
        if (SsoOnlyService::isActive()) {
            return null;
        }
        $user = GoogleAuthService::findUserByEmail($email);
        if ($user) {
            return $user;
        }
        $s = self::settings();
        if (($s['auto_provision'] ?? '0') !== '1') {
            return null;
        }
        $role = (string) ($s['default_role'] ?? 'user');
        if (!in_array($role, ['user', 'cc_agent', 'cc_manager'], true)) {
            $role = 'user';
        }
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower((string) strstr($email, '@', true) ?: 'msuser')) ?: 'msuser';
        $username = substr($base, 0, 32);
        $db = getDB();
        $chk = $db->prepare('SELECT 1 FROM sys_users WHERE username = ? LIMIT 1');
        $suffix = 0;
        $candidate = $username;
        while (true) {
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) {
                break;
            }
            $suffix++;
            $candidate = substr($username, 0, 26) . '_' . $suffix;
        }
        $passHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active, is_device) VALUES (?, ?, ?, ?, ?, 1, 0)');
        $stmt->execute([$candidate, $passHash, $fullName, $email, $role]);
        return GoogleAuthService::findUserByEmail($email);
    }
}
