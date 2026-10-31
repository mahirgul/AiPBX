<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PluginManager.php';

/**
 * OidcAuthService (OpenID Connect / Enterprise SSO Authentication Service)
 *
 * Supports generic OIDC 1.0 Identity Providers:
 * - Keycloak
 * - Microsoft Entra ID (Azure AD / Office 365)
 * - Authentik & Authelia
 * - Okta
 * - Google Workspace
 * - Any standard OpenID Connect 1.0 provider
 *
 * Features:
 * - OIDC Discovery (.well-known/openid-configuration) with caching
 * - Cryptographically secure CSRF state & nonce verification
 * - Auto-provisioning (Just-In-Time user creation in sys_users)
 * - Email domain allowlisting
 * - Role mapping from OIDC claims
 * - Session login and mobile app deep linking
 */
class OidcAuthService
{
    private const PLUGIN_SLUG = 'oidc-auth';
    private const DISCOVERY_CACHE_TTL = 3600; // 1 hour cache for IdP endpoints

    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function setLastError(?string $error): void
    {
        self::$lastError = $error;
    }

    /**
     * Checks whether OIDC SSO authentication is enabled.
     */
    public static function isEnabled(): bool
    {
        $installed = PluginManager::installedMap();
        if (empty($installed[self::PLUGIN_SLUG]['enabled'])) {
            return false;
        }

        $enabled = self::getSetting('enabled', '0');
        if ($enabled !== '1') {
            return false;
        }

        $clientId = self::getSetting('client_id', '');
        $discoveryUrl = self::getSetting('discovery_url', '');

        return !empty($clientId) && !empty($discoveryUrl);
    }

    /**
     * Get a setting value for oidc-auth.
     */
    public static function getSetting(string $key, string $default = ''): string
    {
        return PluginManager::getSetting(self::PLUGIN_SLUG, $key, $default);
    }

    /**
     * Get all settings as an array.
     *
     * @return array<string, string>
     */
    public static function getSettings(): array
    {
        return PluginManager::getAllSettings(self::PLUGIN_SLUG, false);
    }

    /**
     * Public settings for the login page button.
     *
     * @return array{enabled: bool, provider_name: string, icon: string}
     */
    public static function getPublicSettings(): array
    {
        $name = self::getSetting('provider_name', '');
        if (empty($name)) {
            $name = function_exists('t') ? t('oidc.default_btn_label', 'Corporate SSO') : 'Corporate SSO';
        }

        return [
            'enabled' => self::isEnabled(),
            'provider_name' => $name,
            'icon' => 'fas fa-shield-alt',
        ];
    }

    /**
     * Computes the authorized redirect callback URI.
     */
    public static function getRedirectUri(): string
    {
        $custom = trim(self::getSetting('custom_redirect_uri', ''));
        if (!empty($custom)) {
            return $custom;
        }

        if (class_exists('ForgotPasswordService')) {
            require_once __DIR__ . '/ForgotPasswordService.php';
            return rtrim(ForgotPasswordService::portalUrl(), '/') . '/auth/oidc/callback';
        }

        $domain = function_exists('getSystemSetting') ? getSystemSetting('portal_domain', '127.0.0.1') : '127.0.0.1';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return "{$scheme}://{$domain}/auth/oidc/callback";
    }

    /**
     * Resolves OIDC provider endpoints via .well-known/openid-configuration.
     * Uses sys_plugin_kv for caching.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string, jwks_uri: string, end_session_endpoint?: string}|null
     */
    public static function getEndpoints(): ?array
    {
        $discoveryUrl = trim(self::getSetting('discovery_url', ''));
        if (empty($discoveryUrl)) {
            self::setLastError('Discovery URL is not configured');
            return null;
        }

        // Check cache in sys_plugin_kv
        $cached = PluginManager::kvGet(self::PLUGIN_SLUG, 'discovery_cache', 'endpoints');
        if (is_array($cached) && !empty($cached['data']) && !empty($cached['time'])) {
            if ((time() - (int)$cached['time']) < self::DISCOVERY_CACHE_TTL) {
                return $cached['data'];
            }
        }

        $endpoints = self::discover($discoveryUrl);
        if ($endpoints !== null) {
            PluginManager::kvSet(self::PLUGIN_SLUG, 'discovery_cache', 'endpoints', [
                'data' => $endpoints,
                'time' => time(),
            ]);
        }

        return $endpoints;
    }

    /**
     * Performs an HTTP GET request to fetch and parse the OIDC discovery document.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string, jwks_uri: string, end_session_endpoint?: string}|null
     */
    public static function discover(string $discoveryUrl): ?array
    {
        $url = trim($discoveryUrl);
        if (empty($url)) {
            self::setLastError('Empty discovery URL');
            return null;
        }

        // If user entered base issuer (e.g. https://auth.example.com/realms/master), append .well-known
        if (!str_contains($url, '.well-known/openid-configuration')) {
            $url = rtrim($url, '/') . '/.well-known/openid-configuration';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'AiPBX-OIDC-Client/1.0',
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($code !== 200 || empty($body)) {
            self::setLastError("HTTP {$code} from IdP" . ($err ? " ({$err})" : ''));
            return null;
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['authorization_endpoint'])) {
            self::setLastError('Malformed discovery document: missing authorization_endpoint');
            return null;
        }

        return [
            'authorization_endpoint' => (string)$data['authorization_endpoint'],
            'token_endpoint'         => (string)($data['token_endpoint'] ?? ''),
            'userinfo_endpoint'      => (string)($data['userinfo_endpoint'] ?? ''),
            'jwks_uri'               => (string)($data['jwks_uri'] ?? ''),
            'end_session_endpoint'   => isset($data['end_session_endpoint']) ? (string)$data['end_session_endpoint'] : '',
        ];
    }

    /**
     * Generates a cryptographically secure random state parameter for CSRF mitigation.
     */
    public static function generateState(bool $isMobile = false): string
    {
        $csrf = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $stateData = [
            'csrf'   => $csrf,
            'nonce'  => $nonce,
            'mobile' => $isMobile ? 1 : 0,
            'time'   => time(),
        ];

        $state = base64_encode(json_encode($stateData, JSON_THROW_ON_ERROR));

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $_SESSION['oidc_oauth_state'] = $state;
        $_SESSION['oidc_oauth_nonce'] = $nonce;

        return $state;
    }

    /**
     * Validates incoming OAuth state parameter against session state.
     *
     * @return array{valid: bool, mobile: bool, nonce: string}
     */
    public static function verifyState(?string $state): array
    {
        if (empty($state) || empty($_SESSION['oidc_oauth_state'])) {
            return ['valid' => false, 'mobile' => false, 'nonce' => ''];
        }

        if (!hash_equals((string)$_SESSION['oidc_oauth_state'], $state)) {
            return ['valid' => false, 'mobile' => false, 'nonce' => ''];
        }

        $nonce = (string)($_SESSION['oidc_oauth_nonce'] ?? '');
        unset($_SESSION['oidc_oauth_state'], $_SESSION['oidc_oauth_nonce']);

        $decoded = json_decode(base64_decode($state), true);
        if (!is_array($decoded) || empty($decoded['time']) || (time() - (int)$decoded['time']) > 600) {
            return ['valid' => false, 'mobile' => false, 'nonce' => ''];
        }

        return [
            'valid'  => true,
            'mobile' => !empty($decoded['mobile']),
            'nonce'  => (string)($decoded['nonce'] ?? $nonce),
        ];
    }

    /**
     * Builds the authorization redirect URL to send user to IdP.
     */
    public static function getAuthUrl(bool $isMobile = false): ?string
    {
        $endpoints = self::getEndpoints();
        if (!$endpoints || empty($endpoints['authorization_endpoint'])) {
            return null;
        }

        $clientId = self::getSetting('client_id', '');
        $redirectUri = self::getRedirectUri();
        $scopes = self::getSetting('scopes', 'openid email profile');
        $state = self::generateState($isMobile);
        $nonce = $_SESSION['oidc_oauth_nonce'] ?? bin2hex(random_bytes(16));

        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => $scopes,
            'state'         => $state,
            'nonce'         => $nonce,
            'prompt'        => 'select_account',
        ];

        return $endpoints['authorization_endpoint'] . '?' . http_build_query($params);
    }

    /**
     * Exchanges authorization code for OIDC access and ID tokens.
     *
     * @return array{access_token?: string, id_token?: string, token_type?: string, expires_in?: int}|null
     */
    public static function exchangeCode(string $code): ?array
    {
        $endpoints = self::getEndpoints();
        if (!$endpoints || empty($endpoints['token_endpoint'])) {
            self::setLastError('Missing token_endpoint');
            return null;
        }

        $clientId = self::getSetting('client_id', '');
        $clientSecret = self::getSetting('client_secret', '');
        $redirectUri = self::getRedirectUri();

        $postData = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
            'client_id'    => $clientId,
        ];

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: AiPBX-OIDC-Client/1.0',
        ];

        // Send client secret both via Basic Auth and post body for maximum IdP compatibility
        if (!empty($clientSecret)) {
            $postData['client_secret'] = $clientSecret;
            $headers[] = 'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}");
        }

        $ch = curl_init($endpoints['token_endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($httpCode !== 200 || empty($response)) {
            self::setLastError("Token exchange failed: HTTP {$httpCode}" . ($err ? " ({$err})" : ''));
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['access_token'])) {
            self::setLastError('Token endpoint returned no access_token');
            return null;
        }

        return $data;
    }

    /**
     * Parses payload claims from an unencrypted JWT (id_token).
     *
     * @return array<string, mixed>|null
     */
    public static function parseJwtClaims(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $payload = $parts[1];
        // Base64Url decode
        $remainder = strlen($payload) % 4;
        if ($remainder) {
            $payload .= str_repeat('=', 4 - $remainder);
        }
        $json = base64_decode(strtr($payload, '-_', '+/'));
        if (!$json) {
            return null;
        }

        $claims = json_decode($json, true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * Fetches user profile from userinfo endpoint and merges with id_token claims.
     *
     * @return array{email: string, full_name: string, preferred_username: string, sub: string, claims: array<string, mixed>}|null
     */
    public static function getUserInfo(string $accessToken, ?string $idToken = null): ?array
    {
        $claims = [];

        // 1. Parse claims from ID Token if available
        if (!empty($idToken)) {
            $idClaims = self::parseJwtClaims($idToken);
            if (is_array($idClaims)) {
                $claims = array_merge($claims, $idClaims);
            }
        }

        // 2. Query userinfo endpoint if available
        $endpoints = self::getEndpoints();
        if ($endpoints && !empty($endpoints['userinfo_endpoint'])) {
            $ch = curl_init($endpoints['userinfo_endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $accessToken,
                    'Accept: application/json',
                    'User-Agent: AiPBX-OIDC-Client/1.0',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code === 200 && !empty($body)) {
                $uiClaims = json_decode((string)$body, true);
                if (is_array($uiClaims)) {
                    $claims = array_merge($claims, $uiClaims);
                }
            }
        }

        // Resolve Email
        $email = trim((string)($claims['email'] ?? ($claims['upn'] ?? ($claims['preferred_username'] ?? ''))));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::setLastError('No valid email address found in OIDC claims');
            return null;
        }

        // Check email verification status
        // Microsoft Entra ID does not always set email_verified; verified_primary_email or directory account is standard.
        if (isset($claims['email_verified'])) {
            $v = $claims['email_verified'];
            $isVerified = ($v === true || $v === 'true' || $v === 1 || $v === '1');
            if (!$isVerified) {
                self::setLastError('Email is not verified by Identity Provider');
                return null;
            }
        }

        // Resolve Full Name
        $name = trim((string)($claims['name'] ?? ''));
        if (empty($name)) {
            $given = trim((string)($claims['given_name'] ?? ''));
            $family = trim((string)($claims['family_name'] ?? ''));
            $name = trim("{$given} {$family}");
        }
        if (empty($name)) {
            $name = explode('@', $email)[0];
        }

        // Resolve Preferred Username
        $username = trim((string)($claims['preferred_username'] ?? ($claims['nickname'] ?? explode('@', $email)[0])));

        return [
            'email'              => mb_strtolower($email),
            'full_name'          => $name,
            'preferred_username' => $username,
            'sub'                => (string)($claims['sub'] ?? ''),
            'claims'             => $claims,
        ];
    }

    /**
     * Validates whether user's email domain matches allowed_domains setting.
     */
    public static function isDomainAllowed(string $email): bool
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2 || empty($parts[0]) || empty($parts[1])) {
            return false;
        }

        $allowed = trim(self::getSetting('allowed_domains', ''));
        if (empty($allowed)) {
            return true;
        }

        $domain = mb_strtolower(trim($parts[1]));
        $list = array_filter(array_map('trim', explode(',', mb_strtolower($allowed))));
        return in_array($domain, $list, true);
    }

    /**
     * Resolves the user role based on IdP claims mapping or default setting.
     *
     * @param array<string, mixed> $claims
     */
    public static function resolveRoleFromClaims(array $claims): string
    {
        $claimName = trim(self::getSetting('role_claim_name', 'roles'));
        $adminMatch = trim(self::getSetting('admin_role_value', 'aipbx_admin'));
        $defaultRole = self::getSetting('default_role', 'user');

        $validRoles = ['user', 'admin', 'cc_manager', 'cc_agent', 'read_only_admin'];
        if (!in_array($defaultRole, $validRoles, true)) {
            $defaultRole = 'user';
        }

        if (!empty($claimName) && !empty($adminMatch)) {
            $userRoles = [];
            if (isset($claims[$claimName])) {
                $val = $claims[$claimName];
                $userRoles = is_array($val) ? $val : [$val];
            } elseif ($claimName === 'realm_access.roles' && isset($claims['realm_access']['roles'])) {
                $userRoles = (array)$claims['realm_access']['roles'];
            }

            foreach ($userRoles as $r) {
                if (strcasecmp((string)$r, $adminMatch) === 0) {
                    return 'admin';
                }
            }
        }

        return $defaultRole;
    }

    /**
     * Looks up user by email in sys_users.
     *
     * @return array<string, mixed>|null
     */
    public static function findUserByEmail(string $email): ?array
    {
        $cleanEmail = trim(mb_strtolower($email));
        if (empty($cleanEmail)) {
            return null;
        }

        $db = getDB();
        $stmt = $db->prepare('
            SELECT id, username, full_name, email, role, extension, extension_type,
                   sip_password, allowed_phone_mode, theme_preference, language_preference, is_active,
                   must_reset_password, two_factor_enabled
            FROM sys_users
            WHERE LOWER(TRIM(email)) = ?
            LIMIT 1
        ');
        $stmt->execute([$cleanEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Automatically provisions a new user record in sys_users (Just-In-Time).
     *
     * @param array{email: string, full_name: string, preferred_username: string, sub: string, claims: array<string, mixed>} $userInfo
     * @return array<string, mixed>|null
     */
    public static function autoProvisionUser(array $userInfo): ?array
    {
        $email = $userInfo['email'];
        $fullName = $userInfo['full_name'];
        $desiredUsername = $userInfo['preferred_username'];
        $role = self::resolveRoleFromClaims($userInfo['claims']);

        // Sanitize username: lowercase, letters, digits, dots, underscores, dashes
        $cleanUsername = preg_replace('/[^a-zA-Z0-9._-]/', '', $desiredUsername);
        $cleanUsername = mb_strtolower(trim((string)$cleanUsername));
        if (empty($cleanUsername)) {
            $cleanUsername = 'user_' . substr(md5($email), 0, 8);
        }

        $db = getDB();

        // Validate role exists in sys_roles to satisfy fk_users_role
        $chkRole = $db->prepare('SELECT role_key FROM sys_roles WHERE role_key = ? LIMIT 1');
        $chkRole->execute([$role]);
        if (!$chkRole->fetch()) {
            $role = 'user';
        }

        // Check for username collision and append counter if necessary
        $candidateUsername = $cleanUsername;
        $counter = 1;
        while (true) {
            $chk = $db->prepare('SELECT id FROM sys_users WHERE username = ? LIMIT 1');
            $chk->execute([$candidateUsername]);
            if (!$chk->fetch()) {
                break;
            }
            $candidateUsername = $cleanUsername . $counter;
            $counter++;
        }

        // Generate unguessable random password hash
        $randomSecret = bin2hex(random_bytes(32));
        $passHash = password_hash($randomSecret, PASSWORD_DEFAULT);

        $stmt = $db->prepare('
            INSERT INTO sys_users (username, full_name, email, role, password_hash, is_active, must_reset_password, created_at)
            VALUES (?, ?, ?, ?, ?, 1, 0, NOW())
        ');
        $success = $stmt->execute([$candidateUsername, $fullName, $email, $role, $passHash]);

        if (!$success) {
            self::setLastError('Database insert failed during auto-provisioning');
            return null;
        }

        $newUserId = (int)$db->lastInsertId();

        if (function_exists('writeAuditLog')) {
            writeAuditLog(
                'security',
                'sys_users',
                (string)$newUserId,
                "User auto-provisioned via OIDC SSO: {$candidateUsername} ({$email}) with role '{$role}'",
                'create',
                $newUserId
            );
        }

        return self::findUserByEmail($email);
    }

    /**
     * Starts a web session for the authenticated user and returns redirect path.
     *
     * @param array<string, mixed> $user
     */
    public static function loginUser(array $user, string $clientIp = '127.0.0.1'): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['extension'] = $user['extension'] ?? '';
        $_SESSION['last_activity'] = time();
        $_SESSION['theme'] = $user['theme_preference'] ?? 'light';
        $_SESSION['auth_method'] = 'oidc';

        $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$user['language_preference'] ?? '']))
            ? $user['language_preference']
            : (defined('DEFAULT_UI_LANGUAGE') ? DEFAULT_UI_LANGUAGE : 'en');

        unset($_SESSION['captcha_num1'], $_SESSION['captcha_num2']);

        // Log login attempt
        if (function_exists('logLoginAttempt')) {
            logLoginAttempt($clientIp, (string)$user['username'], 'SUCCESS');
        }

        // Record audit log
        if (function_exists('writeAuditLog')) {
            writeAuditLog(
                'security',
                'sys_users',
                (string)$user['id'],
                "User '{$user['username']}' signed in via OIDC SSO ({$user['email']}).",
                'oidc_login',
                (int)$user['id']
            );
        }

        if (function_exists('roleHomePath')) {
            return roleHomePath((string)$user['role']);
        }

        return '/dashboard';
    }
}
