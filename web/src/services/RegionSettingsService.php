<?php
require_once __DIR__ . '/../priv_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * PBX Settings → Language & region: one time zone for the whole system, and the country
 * whose ring and busy tones callers hear. Nothing here changes by itself on an update:
 * only an administrator's choice does, because a new zone moves working hours.
 */
class RegionSettingsService
{
    /** Asterisk's own list of tones, and Turkey, which AiPBX adds. */
    public const TONES = [
        'au' => 'Australia', 'at' => 'Austria', 'be' => 'Belgium', 'br' => 'Brazil', 'bg' => 'Bulgaria',
        'cl' => 'Chile', 'cn' => 'China', 'cz' => 'Czech Republic', 'dk' => 'Denmark', 'ee' => 'Estonia',
        'fi' => 'Finland', 'fr' => 'France', 'de' => 'Germany', 'gr' => 'Greece', 'hu' => 'Hungary',
        'in' => 'India', 'id' => 'Indonesia', 'il' => 'Israel', 'it' => 'Italy', 'jp' => 'Japan',
        'lt' => 'Lithuania', 'my' => 'Malaysia', 'mx' => 'Mexico', 'nl' => 'Netherlands', 'nz' => 'New Zealand',
        'no' => 'Norway', 'ph' => 'Philippines', 'pl' => 'Poland', 'pt' => 'Portugal', 'ru' => 'Russian Federation',
        'sg' => 'Singapore', 'za' => 'South Africa', 'es' => 'Spain', 'se' => 'Sweden', 'ch' => 'Switzerland',
        'tw' => 'Taiwan', 'th' => 'Thailand', 'tr' => 'Turkey', 'uk' => 'United Kingdom',
        'us' => 'United States / North America', 've' => 'Venezuela / South America',
    ];

    public static function systemTimezone(): string
    {
        $link = @readlink('/etc/localtime');
        if (is_string($link) && preg_match('~zoneinfo/(.+)$~', $link, $m) === 1) {
            return $m[1];
        }
        return trim((string) @file_get_contents('/etc/timezone')) ?: 'UTC';
    }

    public static function portalTimezone(): string
    {
        return (string) portalEnv('TIMEZONE', date_default_timezone_get());
    }

    public static function toneCountry(): string
    {
        $res = PrivHelper::run(['tones', 'show']);
        return $res['success'] ? trim($res['output']) : '';
    }

    public static function validTimezone(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public static function validTone(string $country): bool
    {
        return isset(self::TONES[$country]);
    }

    public static function save(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $zone = trim((string) ($post['system_timezone'] ?? ''));
        $tone = trim((string) ($post['tone_country'] ?? ''));
        if (!self::validTimezone($zone)) {
            return ['success' => false, 'error' => t('region.err_timezone')];
        }
        if ($tone !== '' && !self::validTone($tone)) {
            return ['success' => false, 'error' => t('region.err_tone')];
        }
        $uid = $_SESSION['user_id'] ?? null;

        if ($tone !== '' && $tone !== self::toneCountry()) {
            $res = PrivHelper::run(['tones', 'set', $tone]);
            if (!$res['success']) {
                return ['success' => false, 'error' => $res['output']];
            }
            writeAuditLog('settings', 'system', 'tones', "Ring and busy tones: {$tone}", 'update', $uid);
        }

        $zoneChanged = $zone !== self::systemTimezone() || $zone !== self::portalTimezone();
        if (!$zoneChanged) {
            return ['success' => true, 'message' => t('region.saved')];
        }
        $calls = (int) AsteriskHelper::activeCallCount();
        if ($calls > 0 && empty($post['end_calls'])) {
            return ['success' => false, 'error' => sprintf(t('region.err_calls'), $calls)];
        }
        $res = PrivHelper::run(['timezone', $zone]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output']];
        }
        writeAuditLog('settings', 'system', 'timezone', "Time zone: {$zone}", 'update', $uid);
        return ['success' => true, 'message' => t('region.timezone_saved')];
    }

}
