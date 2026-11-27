<?php
/**
 * Which addresses an AI provider may be asked at: the check that keeps a server-side request away from the
 * machine's own services (metadata, link-local, loopback and local-network addresses unless the provider is a server
 * of the administrator's own).
 *
 * The lowest layer of the account code: it needs only the exception and the translation function.
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once __DIR__ . '/CloudAiException.php';

final class AiEndpointGuard
{
    private static function isBlockedIp(string $ip, bool $allowPrivate): bool
    {
        if ($ip === '') {
            return false;
        }
        $lower = strtolower($ip);
        if (str_starts_with($lower, '169.254.') || str_starts_with($lower, 'fe80:') || str_starts_with($lower, '0.') || $lower === '::') {
            return true;
        }
        if ($allowPrivate) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || $lower === '127.0.0.1' || $lower === '::1';
    }

    public static function assertSafeEndpoint(string $url, bool $allowPrivate): void
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '' || $host === 'metadata.google.internal') {
            throw new CloudAiException(t('ai_cloud.err_base_url'));
        }
        if (!$allowPrivate && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal'))) {
            throw new CloudAiException(t('ai_cloud.err_base_url'));
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isBlockedIp($host, $allowPrivate)) {
                throw new CloudAiException(t('ai_cloud.err_base_url'));
            }
            return;
        }
        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (self::isBlockedIp($ip, $allowPrivate)) {
                    throw new CloudAiException(t('ai_cloud.err_base_url'));
                }
            }
        }
    }

    /**
     * Address of an OpenAI-compatible chat API, or null: http(s), no
     * credentials or fragments in it, at most 200 characters.
     */
    public static function validBaseUrl(string $url, bool $allowPrivate = true): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || strlen($url) > 200 || !preg_match('~^https?://[^\s/?#@]+(/[^\s?#]*)?$~', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === 'metadata.google.internal' || (filter_var($host, FILTER_VALIDATE_IP) !== false && self::isBlockedIp($host, $allowPrivate))) {
            return null;
        }
        if (!$allowPrivate && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal'))) {
            return null;
        }
        return $url;
    }
}
