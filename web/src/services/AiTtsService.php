<?php
require_once __DIR__ . '/../asterisk_sync.php';   // writeAuditLog, markPendingSync
require_once __DIR__ . '/../secret_box.php';
require_once __DIR__ . '/tts/TtsProviders.php';
require_once __DIR__ . '/tts/LocalEmaTts.php';
require_once __DIR__ . '/SoundService.php';

/**
 * AI → Cloud TTS: provider settings, voices, synthesis, history, and saving
 * a result as an Asterisk announcement (Sounds & Announcements).
 *
 * Credentials are stored in sys_settings as ai_tts.<provider>.<field>;
 * secret fields encrypted with SecretBox and never sent back to the page.
 * Generated MP3s live in AUDIO_DIR as <history id>.mp3.
 */
class AiTtsService
{
    public const AUDIO_DIR = '/var/lib/aipbx/tts';
    public const MAX_TEXT = 20000;
    /** @var list<class-string<TtsProvider>> */
    public const PROVIDERS = [LocalEmaTts::class, GoogleTts::class, PollyTts::class, AzureTts::class, ElevenLabsTts::class, OpenAiTts::class];

    public static function audioDir(): string
    {
        return (string) portalEnv('AI_TTS_DIR', self::AUDIO_DIR);
    }

    /** Created only when something is written (by the web server), never on a read. */
    private static function writableDir(): string
    {
        $dir = self::audioDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new TtsException(t('ai_tts.err_store'));
        }
        return $dir;
    }

    /** @return class-string<TtsProvider> */
    public static function providerClass(string $id): string
    {
        foreach (self::PROVIDERS as $cls) {
            if ($cls::id() === $id) {
                return $cls;
            }
        }
        throw new TtsException('unknown provider: ' . $id);
    }

    /** Decrypted settings of a provider (server side only). */
    public static function config(string $id): array
    {
        $cls = self::providerClass($id);
        $cfg = [];
        foreach ($cls::fields() as $f) {
            $raw = (string) getSystemSetting("ai_tts.{$id}.{$f['key']}", '');
            $cfg[$f['key']] = $f['secret'] ? SecretBox::decrypt($raw) : ($raw !== '' ? $raw : ($f['default'] ?? ''));
        }
        return $cfg;
    }

    public static function client(string $id): TtsProvider
    {
        $cls = self::providerClass($id);
        $client = new $cls(self::config($id));
        if (!$client->configured()) {
            throw new TtsException(sprintf(t('ai_tts.err_not_configured'), $cls::title()));
        }
        return $client;
    }

    /**
     * What the settings page shows: secrets only masked.
     * @return list<array<string, mixed>>
     */
    public static function providersForPage(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $cls) {
            $cfg = self::config($cls::id());
            $fields = [];
            foreach ($cls::fields() as $f) {
                $v = $cfg[$f['key']] ?? '';
                // A service account key is shown by its account, not by its last characters.
                $masked = !empty($f['json']) ? (string) (json_decode($v, true)['client_email'] ?? '') : SecretBox::mask($v);
                $fields[] = $f + ['value' => $f['secret'] ? '' : $v, 'masked' => $f['secret'] ? $masked : ''];
            }
            $out[] = ['id' => $cls::id(), 'title' => $cls::title(), 'local' => $cls::local(), 'configured' => (new $cls($cfg))->configured(), 'fields' => $fields, 'max_chars' => $cls::maxChars()];
        }
        return $out;
    }

    /** @return list<string> ids of providers with credentials */
    public static function configuredProviders(): array
    {
        return array_values(array_map(fn($p) => $p['id'], array_filter(self::providersForPage(), fn($p) => $p['configured'])));
    }

    /**
     * Saves one provider's settings. Empty secret fields keep the stored
     * value (the page never has it); "clear" removes the provider.
     */
    public static function saveProvider(string $id, array $post): void
    {
        $cls = self::providerClass($id);
        $db = getDB();
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach ($cls::fields() as $f) {
            $key = "ai_tts.{$id}.{$f['key']}";
            if (!empty($post['clear'])) {
                $del->execute([$key]);
                continue;
            }
            $v = trim((string) ($post[$f['key']] ?? ''));
            if ($v !== '' && !empty($f['json']) && $cls === GoogleTts::class) {
                $v = GoogleTts::validateServiceAccount($v);
            }
            if ($f['secret']) {
                if ($v !== '') {
                    $set->execute([$key, SecretBox::encrypt($v)]);
                }
            } elseif ($v === '') {
                $del->execute([$key]);
            } else {
                $set->execute([$key, mb_substr($v, 0, 200)]);
            }
        }
        self::forgetVoices($id);
        writeAuditLog(null, 'ai_tts', $id, $cls::title() . (!empty($post['clear']) ? ' (credentials removed)' : ' (settings saved)'), 'update', $_SESSION['user_id'] ?? null);
    }

    private static function voiceCacheFile(string $id): string
    {
        return self::audioDir() . '/.voices-' . $id . '.json';
    }

    private static function forgetVoices(string $id): void
    {
        @unlink(self::voiceCacheFile($id));
    }

    /**
     * Voices of a provider (cached for 12 hours: the lists are long and rarely change).
     * @return list<array{id: string, name: string, language: string, gender: string}>
     */
    public static function voices(string $id, bool $refresh = false): array
    {
        $file = self::voiceCacheFile($id);
        if (!$refresh && is_readable($file) && filemtime($file) > time() - 43200) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $voices = self::client($id)->voices();
        usort($voices, fn($a, $b) => [$a['language'], $a['name']] <=> [$b['language'], $b['name']]);
        if (is_dir(self::audioDir()) || @mkdir(self::audioDir(), 0750, true)) {
            @file_put_contents($file, json_encode($voices, JSON_UNESCAPED_UNICODE));
        }
        return $voices;
    }

    /**
     * Splits text into pieces of at most $max characters, at sentence ends
     * where possible, then at spaces.
     * @return list<string>
     */
    public static function chunks(string $text, int $max): array
    {
        $text = trim(preg_replace("/[ \t]+/u", ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text === '' ? [] : [$text];
        }
        $sentences = preg_split('/(?<=[.!?…;:])\s+|\n+/u', $text) ?: [$text];
        $out = [];
        $cur = '';
        foreach ($sentences as $s) {
            $s = trim($s);
            while (mb_strlen($s) > $max) {
                // One sentence longer than the limit: cut at the last space.
                $cut = mb_strrpos(mb_substr($s, 0, $max), ' ') ?: $max;
                $piece = trim(mb_substr($s, 0, $cut));
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
                $out[] = $piece;
                $s = trim(mb_substr($s, $cut));
            }
            if ($s === '') {
                continue;
            }
            if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($s) > $max) {
                $out[] = $cur;
                $cur = $s;
            } else {
                $cur = $cur === '' ? $s : $cur . ' ' . $s;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out;
    }

    /** @return array<string, mixed> the history row */
    public static function synthesize(string $provider, string $voice, string $language, string $text, float $speed): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new TtsException(t('ai_tts.err_empty'));
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new TtsException(sprintf(t('ai_tts.err_too_long'), self::MAX_TEXT));
        }
        if ($voice === '' || mb_strlen($voice) > 128 || !preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$|^\*$/', $language)) {
            throw new TtsException(t('ai_tts.err_voice'));
        }
        $speed = max(0.5, min(2.0, round($speed, 2)));
        $client = self::client($provider);
        $cls = self::providerClass($provider);

        $audio = '';
        foreach (self::chunks($text, $cls::maxChars()) as $piece) {
            // MP3 frames can simply follow each other: players read one stream.
            $audio .= $client->synthesize($piece, $voice, $language === '*' ? '' : $language, $speed);
        }

        $db = getDB();
        $db->prepare('INSERT INTO ai_tts_history (provider, voice, language, speed, text, chars, bytes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$provider, $voice, $language, $speed, $text, mb_strlen($text), strlen($audio), $_SESSION['user_id'] ?? null]);
        $id = (int) $db->lastInsertId();
        $path = self::writableDir() . '/' . $id . '.mp3';
        if (file_put_contents($path, $audio) === false) {
            $db->prepare('DELETE FROM ai_tts_history WHERE id = ?')->execute([$id]);
            throw new TtsException(t('ai_tts.err_store'));
        }
        $ms = self::durationMs($path);
        if ($ms !== null) {
            $db->prepare('UPDATE ai_tts_history SET duration_ms = ? WHERE id = ?')->execute([$ms, $id]);
        }
        writeAuditLog(null, 'ai_tts', (string) $id, $cls::title() . ' · ' . $voice . ' · ' . mb_strlen($text) . ' chars', 'create', $_SESSION['user_id'] ?? null);
        return self::get($id);
    }

    private static function durationMs(string $path): ?int
    {
        $out = trim((string) @shell_exec('soxi -D ' . escapeshellarg($path) . ' 2>/dev/null'));
        return is_numeric($out) ? (int) round((float) $out * 1000) : null;
    }

    public static function audioPath(int $id): string
    {
        return self::audioDir() . '/' . $id . '.mp3';
    }

    public static function get(int $id): ?array
    {
        $stmt = getDB()->prepare('SELECT h.*, u.full_name AS created_by_name FROM ai_tts_history h LEFT JOIN sys_users u ON u.id = h.created_by WHERE h.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function history(int $limit = 50): array
    {
        $stmt = getDB()->query('SELECT h.*, u.full_name AS created_by_name FROM ai_tts_history h LEFT JOIN sys_users u ON u.id = h.created_by ORDER BY h.id DESC LIMIT ' . max(1, min(500, $limit)));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function delete(int $id): void
    {
        getDB()->prepare('DELETE FROM ai_tts_history WHERE id = ?')->execute([$id]);
        @unlink(self::audioPath($id));
    }

    /**
     * Saves a result as an announcement: 8 kHz WAV in the custom sounds
     * folder plus a Sounds & Announcements entry, like an upload there.
     */
    public static function saveAsAnnouncement(int $id, string $soundName, string $title): string
    {
        $row = self::get($id);
        $src = self::audioPath($id);
        if (!$row || !is_file($src)) {
            throw new TtsException(t('ai_tts.err_not_found'));
        }
        $soundName = preg_replace('/[^a-zA-Z0-9_-]/', '', $soundName) ?? '';
        if ($soundName === '' || strlen($soundName) > 60) {
            throw new TtsException(t('ai_tts.err_sound_name'));
        }
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 100) : $soundName;
        $target = SOUNDS_CUSTOM_DIR . '/' . $soundName . '.wav';
        SoundService::convertToAsteriskWav($src, $target);
        @chown($target, 'asterisk');
        @chgrp($target, 'asterisk');
        @chmod($target, 0664);

        $db = getDB();
        $db->prepare('INSERT INTO pbx_announcements (title, audio_file, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE title = VALUES(title)')
            ->execute([$title, 'custom/' . $soundName]);
        $db->prepare('UPDATE ai_tts_history SET announcement = ? WHERE id = ?')->execute(['custom/' . $soundName, $id]);

        // Same as an upload in Sounds & Announcements: destinations read the table.
        $uid = $_SESSION['user_id'] ?? null;
        foreach (['inbound_dialplan', 'ivrs', 'time_conditions'] as $domain) {
            markPendingSync($domain, 'announcement', $soundName, "Announcement: {$title}", 'create', $uid);
        }
        writeAuditLog(null, 'ai_tts', (string) $id, "Saved as announcement custom/{$soundName}", 'create', $uid);
        return 'custom/' . $soundName;
    }
}
