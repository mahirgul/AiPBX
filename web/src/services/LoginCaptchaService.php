<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/secret_box.php';

/**
 * The check on the sign-in and forgotten password pages: the sum, or Cloudflare Turnstile.
 *
 * When this server cannot reach Cloudflare, the session goes back to the sum for a while, so an
 * outage does not lock everyone out. The browser cannot choose that: only a failed call from here
 * sets it. A browser that cannot load Turnstile needs the setting turned off (docs/security.md).
 */
final class LoginCaptchaService
{
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const FALLBACK_SECONDS = 600;

    private const PROVIDER = 'login_captcha_provider';
    private const SITE_KEY = 'login_turnstile_site_key';
    private const SECRET = 'login_turnstile_secret';

    public static function siteKey(): string
    {
        return (string) getSystemSetting(self::SITE_KEY, '');
    }

    public static function isConfigured(): bool
    {
        return getSystemSetting(self::PROVIDER, 'math') === 'turnstile'
            && self::siteKey() !== ''
            && (string) getSystemSetting(self::SECRET, '') !== '';
    }

    public static function usesTurnstile(): bool
    {
        return self::isConfigured() && (int) ($_SESSION['captcha_turnstile_down'] ?? 0) < time();
    }

    public static function errorMessage(): string
    {
        return t(self::usesTurnstile() ? 'srv_login.err_turnstile' : 'srv_login.err_captcha');
    }

    /** Did the visitor pass? $sum is the right answer of the sum shown when Turnstile is not in use. */
    public static function passes(array $post, string $clientIp, int $sum): bool
    {
        if (!self::usesTurnstile()) {
            return array_key_exists('captcha_answer', $post) && (int) $post['captcha_answer'] === $sum;
        }
        $secret = SecretBox::decrypt((string) getSystemSetting(self::SECRET, ''));
        $answer = self::askCloudflare($secret, (string) ($post['cf-turnstile-response'] ?? ''), $clientIp);
        if ($answer === null) {
            $_SESSION['captcha_turnstile_down'] = time() + self::FALLBACK_SECONDS;
            return false;
        }
        return !empty($answer['success']);
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $provider = ($post['login_captcha_provider'] ?? '') === 'turnstile' ? 'turnstile' : 'math';
        $siteKey = trim((string) ($post['login_turnstile_site_key'] ?? ''));
        $newSecret = trim((string) ($post['login_turnstile_secret'] ?? ''));
        if ($siteKey !== '' && !preg_match('/^[0-9A-Za-z_-]{1,100}$/D', $siteKey)) {
            return ['success' => false, 'error' => t('brand_settings.err_turnstile_keys')];
        }

        $storedSecret = (string) getSystemSetting(self::SECRET, '');
        $secret = $newSecret !== '' ? $newSecret : SecretBox::decrypt($storedSecret);
        if ($provider === 'turnstile') {
            if ($siteKey === '' || $secret === '') {
                return ['success' => false, 'error' => t('brand_settings.err_turnstile_keys')];
            }
            // Refusing here is what keeps a wrong key from locking everyone out at the next sign-in.
            $answer = self::askCloudflare($secret, 'aipbx-settings-check', '');
            if ($answer === null) {
                return ['success' => false, 'error' => t('brand_settings.err_turnstile_unreachable')];
            }
            if (in_array('invalid-input-secret', (array) ($answer['error-codes'] ?? []), true)) {
                return ['success' => false, 'error' => t('brand_settings.err_turnstile_secret')];
            }
        }

        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([self::PROVIDER, $provider]);
        $stmt->execute([self::SITE_KEY, $siteKey]);
        if ($newSecret !== '') {
            $stmt->execute([self::SECRET, SecretBox::encrypt($newSecret)]);
        }
        writeAuditLog(null, 'brand_settings', 'login_captcha', "Sign-in check set to {$provider}", 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('srv_brand.saved')];
    }

    /** @return array<string, mixed>|null null when Cloudflare gave no usable answer */
    private static function askCloudflare(string $secret, string $token, string $clientIp): ?array
    {
        $fields = ['secret' => $secret, 'response' => $token];
        if ($clientIp !== '') {
            $fields['remoteip'] = $clientIp;
        }
        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || $status < 200 || $status >= 500) {
            return null;
        }
        $answer = json_decode($body, true);
        return is_array($answer) ? $answer : null;
    }
}
