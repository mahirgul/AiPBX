<?php
declare(strict_types=1);

/**
 * "Sign in only through the identity provider". Local passwords, passkeys and Google stop working
 * for sign-in, and the sign-in page goes straight to the provider.
 *
 * Two things keep an organisation from locking itself out. It can only be turned on from a session
 * that really signed in through that provider. And if the provider is switched off or its plugin
 * disabled, local sign-in comes back by itself. If the provider itself is down, an administrator on
 * the server runs bin/aipbx_sso_recovery.php: a one-time link that allows an administrator's
 * password, and only that, for ten minutes in that browser.
 */
final class SsoOnlyService
{
    private const ENABLED = 'auth_sso_only';
    private const PROVIDER = 'auth_sso_provider';
    private const RECOVERY = 'auth_sso_recovery';
    private const LINK_SECONDS = 900;
    private const SESSION_SECONDS = 600;

    public static function provider(): string
    {
        return getSystemSetting(self::PROVIDER, 'saml') === 'oidc' ? 'oidc' : 'saml';
    }

    public static function providerEnabled(string $provider): bool
    {
        // Loaded here so that no entry point can skip the block because a class was not loaded yet.
        require_once dirname(__DIR__) . '/core/PluginManager.php';
        require_once __DIR__ . '/' . ($provider === 'oidc' ? 'OidcAuthService.php' : 'SamlAuthService.php');
        return $provider === 'oidc' ? OidcAuthService::isEnabled() : SamlAuthService::isEnabled();
    }

    public static function isActive(): bool
    {
        return getSystemSetting(self::ENABLED, '0') === '1' && self::providerEnabled(self::provider());
    }

    public static function loginUrl(): string
    {
        return self::provider() === 'oidc' ? '/auth/oidc' : '/auth/saml';
    }

    public static function inRecovery(): bool
    {
        return (int) ($_SESSION['sso_recovery_until'] ?? 0) >= time();
    }

    /** The password form goes through this. Passkeys and Google are off the whole time, recovery included. */
    public static function localSignInBlocked(): bool
    {
        return self::isActive() && !self::inRecovery();
    }

    /** During recovery the password works for administrators only. */
    public static function roleMayUsePassword(string $role): bool
    {
        return !self::isActive() || $role === 'admin';
    }

    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $provider = ($post['auth_sso_provider'] ?? '') === 'oidc' ? 'oidc' : 'saml';
        $on = !empty($post['auth_sso_only']);
        if ($on && !self::providerEnabled($provider)) {
            return ['success' => false, 'error' => t('sso_only.err_not_enabled')];
        }
        if ($on && ($_SESSION['auth_method'] ?? '') !== $provider) {
            return ['success' => false, 'error' => t('sso_only.err_sign_in_first')];
        }
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([self::ENABLED, $on ? '1' : '0']);
        $stmt->execute([self::PROVIDER, $provider]);
        writeAuditLog(null, 'auth_sso_only', 'general', $on ? "Sign-in only through {$provider}" : 'Local sign-in allowed', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('sso_only.saved')];
    }

    /** A new one-time recovery token, replacing any earlier one. Only the server's command line calls this. */
    public static function createRecoveryToken(): string
    {
        $token = bin2hex(random_bytes(32));
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::RECOVERY, hash('sha256', $token) . ':' . (time() + self::LINK_SECONDS)]);
        writeAuditLog(null, 'auth_sso_only', 'recovery', 'Recovery link created on the server', 'update', null);
        return $token;
    }

    /** Spends a recovery token and opens recovery in this session. */
    public static function useRecoveryToken(string $token): bool
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        $stmt->execute([self::RECOVERY]);
        [$hash, $until] = array_pad(explode(':', (string) $stmt->fetchColumn(), 2), 2, '0');
        if ($token === '' || $hash === '' || (int) $until < time() || !hash_equals($hash, hash('sha256', $token))) {
            return false;
        }
        // Deleting first and counting the rows makes a second use of the same link fail.
        $delete = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ? AND setting_value = ?');
        $delete->execute([self::RECOVERY, "{$hash}:{$until}"]);
        if ($delete->rowCount() !== 1) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['sso_recovery_until'] = time() + self::SESSION_SECONDS;
        writeAuditLog(null, 'auth_sso_only', 'recovery', 'Recovery link used from ' . ($_SERVER['REMOTE_ADDR'] ?? ''), 'update', null);
        return true;
    }
}
