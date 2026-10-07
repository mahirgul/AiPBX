<?php
require_once __DIR__ . '/../priv_helper.php';

/**
 * Sounds page → "Asterisk sound packs" tab.
 *
 * Installs Asterisk's official prompt / music-on-hold packages from
 * downloads.asterisk.org, and AiPBX's own Turkish prompts (core tr, GitHub
 * release sounds-tr-<version>; install.sh installs them the same way). The work is done as root by
 * /usr/local/sbin/aipbx-sounds (conf/sbin/aipbx-sounds), started through
 * aipbx-priv in its own systemd unit because a download can take minutes;
 * the portal only reads the state files it writes.
 */
class SoundPackService
{
    public const DB_FILE = '/var/lib/aipbx/sound-packs.json';
    public const STATUS_FILE = '/var/lib/aipbx/sound-packs-status.json';
    public const LOG_FILE = '/var/log/aipbx/sound-packs.log';

    /** Formats offered (g729 / siren need codec modules most installs lack). */
    public const FORMATS = ['wav', 'ulaw', 'alaw', 'gsm', 'g722', 'sln16'];

    /** What downloads.asterisk.org publishes, per kind → language code → name. */
    public const CATALOG = [
        'core' => [
            'tr' => 'Türkçe (AiPBX)',
            'en' => 'English (US)', 'en_AU' => 'English (Australia)', 'en_GB' => 'English (UK)',
            'en_NZ' => 'English (New Zealand)', 'es' => 'Español', 'fr' => 'Français',
            'it' => 'Italiano', 'ja' => '日本語', 'ru' => 'Русский', 'sv' => 'Svenska',
        ],
        'extra' => [
            'en' => 'English (US)', 'en_GB' => 'English (UK)', 'fr' => 'Français',
        ],
        'moh' => [
            'opsound' => 'Opsound',
        ],
    ];

    /** @return array<string, array> installed packs keyed "kind-lang-format" */
    public static function installed(): array
    {
        $db = self::readJson(self::DB_FILE) ?? [];
        ksort($db);
        return $db;
    }

    public static function status(): ?array
    {
        return self::readJson(self::STATUS_FILE);
    }

    public static function isRunning(): bool
    {
        return (self::status()['state'] ?? '') === 'running';
    }

    public static function logTail(int $lines = 40): string
    {
        if (!is_readable(self::LOG_FILE)) {
            return '';
        }
        $all = preg_split('/\r?\n/', rtrim((string) file_get_contents(self::LOG_FILE, false, null, max(0, filesize(self::LOG_FILE) - 32 * 1024))));
        return implode("\n", array_slice($all, -$lines));
    }

    /** Starts an install / remove in the background. */
    public static function start(string $action, string $kind, string $lang, string $format): array
    {
        if (!in_array($action, ['install', 'remove'], true)
            || !isset(self::CATALOG[$kind][$lang])
            || !in_array($format, self::FORMATS, true)) {
            return ['success' => false, 'error' => t('sound_packs.invalid')];
        }
        if (self::isRunning()) {
            return ['success' => false, 'error' => t('sound_packs.already_running')];
        }
        $res = PrivHelper::run(['sounds', $action, $kind, $lang, $format]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output'] ?: t('sound_packs.start_failed')];
        }
        writeAuditLog(null, 'sound_pack', "{$kind}-{$lang}-{$format}", "Asterisk sound pack {$kind}-{$lang}-{$format}", $action === 'install' ? 'create' : 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true];
    }

    private static function readJson(string $file): ?array
    {
        if (!is_readable($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
}
