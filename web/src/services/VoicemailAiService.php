<?php
/**
 * Voicemail as text: the transcript of a new message and a one-sentence
 * summary, for the e-mail, the push notification and the voicemail lists.
 *
 * Switched on under AI -> Cloud services -> Voicemail. Speech to text is the
 * engine chosen there for the mailbox owner's language (a small local model
 * or a cloud provider); the summary needs a cloud language model ("Language
 * model" engine) — large models are not run on the PBX itself.
 *
 * Made once per message (table voicemail_ai, key mailbox + origtime): the
 * voicemail e-mail (bin/voicemail_mail.php) usually comes first, the push
 * (bin/voicemail_notify.php) then finds the result. Nothing here may stop a
 * notification: every failure just leaves the text out.
 */

require_once __DIR__ . '/LocalAiService.php';
require_once __DIR__ . '/ai/CloudAiService.php';

final class VoicemailAiService
{
    public const SPOOL = '/var/spool/asterisk/voicemail/default';
    private const LANG_NAMES = ['tr' => 'Turkish', 'de' => 'German', 'en' => 'English'];

    public static function transcribeOn(): bool
    {
        return getSystemSetting('ai_vm_transcribe', '0') === '1';
    }

    public static function summaryOn(): bool
    {
        return self::transcribeOn() && getSystemSetting('ai_vm_summary', '0') === '1';
    }

    public static function save(array $post): void
    {
        $db = getDB();
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $set->execute(['ai_vm_transcribe', !empty($post['ai_vm_transcribe']) ? '1' : '0']);
        $set->execute(['ai_vm_summary', !empty($post['ai_vm_summary']) ? '1' : '0']);
        writeAuditLog('ai_cloud', 'voicemail', 0, 'Voicemail transcription', 'update', $_SESSION['user_id'] ?? null);
    }

    /**
     * Language the caller most likely spoke: the PBX's prompt language (what
     * callers hear), else the mailbox owner's portal language; tr/de/en.
     */
    public static function languageOf(string $mailbox): string
    {
        $lang = substr((string) getSystemSetting('system_default_language', ''), 0, 2);
        if (!isset(self::LANG_NAMES[$lang])) {
            try {
                $st = getDB()->prepare("SELECT language_preference FROM sys_users WHERE extension = ? AND extension_type = 'sip' ORDER BY id LIMIT 1");
                $st->execute([$mailbox]);
                $lang = (string) ($st->fetchColumn() ?: '');
            } catch (\Throwable $e) {
                $lang = '';
            }
        }
        return isset(self::LANG_NAMES[$lang]) ? $lang : 'tr';
    }

    /**
     * Spool files (PCM .wav and .txt) of the newest message in INBOX — the one
     * Asterisk has just saved when it sends the e-mail or the notification.
     */
    public static function newestMessage(string $mailbox): ?array
    {
        $best = null;
        $newest = 0;
        foreach (glob(sprintf('%s/%s/INBOX/msg*.txt', self::SPOOL, preg_replace('/[^0-9]/', '', $mailbox))) ?: [] as $txt) {
            $m = (int) @filemtime($txt);
            $wav = substr($txt, 0, -4) . '.wav';
            if ($m >= $newest && is_file($wav)) {
                $newest = $m;
                $best = ['wav' => $wav, 'txt' => $txt];
            }
        }
        return $best;
    }

    public static function origtime(string $txtPath): int
    {
        $info = @parse_ini_string((string) @file_get_contents($txtPath), false, INI_SCANNER_RAW) ?: [];
        return (int) ($info['origtime'] ?? 0);
    }

    /**
     * Transcript and summary of the newest INBOX message of $mailbox.
     *
     * The voicemail scripts run as the asterisk user, which may read neither
     * the local AI service's key nor the key of the stored cloud credentials
     * (www-data only). Then the work is handed to the portal on this server
     * (api/voicemail_ai.php, www-data), signed with a key both can derive from
     * /etc/ai-pbx.env, and its answer is used.
     */
    public static function forNewest(string $mailbox): ?array
    {
        if (!self::transcribeOn()) {
            return null;
        }
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        if ($user === 'www-data' || $user === 'root') {
            $files = self::newestMessage($mailbox);
            return $files !== null ? self::forMessage($mailbox, $files['wav'], $files['txt']) : null;
        }
        $ch = curl_init('https://127.0.0.1/api/voicemail_ai.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['mailbox' => $mailbox]),
            CURLOPT_HTTPHEADER => ['X-AiPBX-Internal: ' . self::internalToken()],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 3,
            // The portal's own certificate is for its name, not 127.0.0.1.
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $d = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (!is_array($d) || !isset($d['transcript'])) {
            return null;
        }
        return ['transcript' => (string) $d['transcript'], 'summary' => (string) ($d['summary'] ?? '')];
    }

    /** Signature of the internal request (same secret as the mobile tokens). */
    public static function internalToken(): string
    {
        require_once __DIR__ . '/../../api/mobile/auth_helper.php';
        return hash_hmac('sha256', 'internal:voicemail-ai', getMobileTokenSecret());
    }

    /** The stored result of a message, or null. */
    public static function find(string $mailbox, int $origtime): ?array
    {
        $st = getDB()->prepare('SELECT transcript, summary FROM voicemail_ai WHERE mailbox = ? AND origtime = ?');
        $st->execute([$mailbox, $origtime]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Transcript and summary of a message (made and stored on the first call).
     * @return array{transcript: string, summary: string}|null null: switched off or not possible
     */
    public static function forMessage(string $mailbox, string $wavPath, string $txtPath): ?array
    {
        if (!self::transcribeOn()) {
            return null;
        }
        $origtime = self::origtime($txtPath);
        if ($origtime <= 0) {
            return null;
        }
        $done = self::find($mailbox, $origtime);
        if ($done !== null) {
            return ['transcript' => (string) $done['transcript'], 'summary' => (string) $done['summary']];
        }
        $lang = self::languageOf($mailbox);
        $wav = (string) @file_get_contents($wavPath);
        [$text, $engine] = self::transcribe($wav, $lang);
        if ($text === '') {
            return null;
        }
        require_once __DIR__ . '/PrivacyService.php';
        $text = PrivacyService::redactTranscriptIfEnabled($text);
        $summary = self::summaryOn() ? PrivacyService::redactTranscriptIfEnabled(self::summarize($text, $lang)) : '';
        try {
            getDB()->prepare('INSERT IGNORE INTO voicemail_ai (mailbox, origtime, transcript, summary, engine) VALUES (?, ?, ?, ?, ?)')
                ->execute([$mailbox, $origtime, $text, mb_substr($summary, 0, 500), $engine]);
        } catch (\Throwable $e) {
            error_log('voicemail AI: not stored: ' . $e->getMessage());
        }
        return ['transcript' => $text, 'summary' => $summary];
    }

    /** @return array{0: string, 1: string} text and the engine that made it ('' when none) */
    public static function transcribe(string $wav, string $lang): array
    {
        $engine = CloudAiService::engine('stt', $lang);
        try {
            if (str_starts_with($engine, 'local:')) {
                return [trim(LocalAiService::stt(substr($engine, 6), $wav)['text']), $engine];
            }
            if (str_starts_with($engine, 'cloud:')) {
                return [trim(CloudAiService::stt(substr($engine, 6), $wav, $lang)['text']), $engine];
            }
        } catch (\Throwable $e) {
            error_log("voicemail AI: speech to text ({$engine}) failed: " . $e->getMessage());
        }
        return ['', ''];
    }

    /** One sentence: who and what they want, with numbers and times; '' when not possible. */
    public static function summarize(string $transcript, string $lang): string
    {
        $engine = CloudAiService::engine('llm', $lang);
        if (!str_starts_with($engine, 'cloud:')) {
            return '';
        }
        $prompt = 'This is the transcript of a voicemail left on a company telephone. It may contain speech '
            . "recognition errors.\n\nTranscript: \"" . str_replace('"', "'", mb_substr($transcript, 0, 4000)) . "\"\n\n"
            . 'Write ONE short sentence in ' . self::LANG_NAMES[$lang] . ' saying who called (if they said it) and '
            . 'what they want, keeping names, numbers, dates and times. No introduction, no quotes. '
            . 'If nothing meaningful was said, answer with an empty line.';
        try {
            $s = CloudAiService::chat(substr($engine, 6), $prompt, 120, 20);
            return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? '', " \"'"), 0, 300);
        } catch (\Throwable $e) {
            error_log("voicemail AI: summary ({$engine}) failed: " . $e->getMessage());
            return '';
        }
    }
}
