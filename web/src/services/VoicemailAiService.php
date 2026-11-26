<?php
/**
 * Voicemail as text: the transcript of a new message and a one-sentence
 * summary, for the e-mail, the push notification and the voicemail lists.
 *
 * Configured under AI -> Voicemail AI (/ai-voicemail) using AiBindingService
 * (`local_default` / `cloud_default` / `explicit` / `off`, never silently
 * falling back from local to cloud).
 *
 * Made once per message (table voicemail_ai, key mailbox + origtime). Nothing
 * here may stop a notification: every failure just leaves the text out.
 */

require_once __DIR__ . '/LocalAiService.php';
require_once __DIR__ . '/ai/CloudAiService.php';
require_once __DIR__ . '/ai/AiBindingService.php';
require_once __DIR__ . '/ai/AiGateway.php';
require_once __DIR__ . '/ai/AiJobService.php';

final class VoicemailAiService
{
    public const SPOOL = '/var/spool/asterisk/voicemail/default';

    public static function pluginEnabled(): bool
    {
        if (!class_exists('PluginManager')) {
            return true;
        }
        $installed = PluginManager::installedMap();
        if (isset($installed['ai-voicemail'])) {
            return !empty($installed['ai-voicemail']['enabled']);
        }
        return true;
    }

    public static function transcribeOn(): bool
    {
        return self::pluginEnabled() && getSystemSetting('ai_vm_transcribe', '0') === '1';
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
        writeAuditLog('ai_voicemail', 'voicemail', 0, 'Voicemail AI settings', 'update', $_SESSION['user_id'] ?? null);
    }

    /** The model Voicemail AI uses for a task ('stt' or 'chat'), null when the administrator left it off. */
    public static function resolveEngine(string $task): ?string
    {
        return AiBindingService::resolve('ai_voicemail', $task);
    }

    private static function isScopeEnabled(string $scope): bool
    {
        if (!class_exists('PluginManager')) {
            return true;
        }
        $slug = $scope === 'local' ? 'ailocal' : 'aicloud';
        $installed = PluginManager::installedMap();
        if (isset($installed[$slug])) {
            return !empty($installed[$slug]['enabled']);
        }
        if (isset($installed['ai'])) {
            return !empty($installed['ai']['enabled']);
        }
        return true;
    }

    /**
     * Language the caller most likely spoke: the PBX's prompt language (what
     * callers hear), else the mailbox owner's portal language; one of the AI languages.
     */
    public static function languageOf(string $mailbox): string
    {
        $lang = '';
        try {
            $st = getDB()->prepare("SELECT language_preference FROM sys_users WHERE extension = ? AND extension_type = 'sip' ORDER BY id LIMIT 1");
            $st->execute([$mailbox]);
            $lang = substr((string) ($st->fetchColumn() ?: ''), 0, 2);
        } catch (\Throwable $e) {
            $lang = '';
        }
        $all = CloudAiService::languages();
        if (!isset($all[$lang])) {
            $lang = substr((string) getSystemSetting('system_default_language', ''), 0, 2);
        }
        return CloudAiService::language($lang);
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
     * Enqueues a durable voicemail transcription job idempotently via AiJobService.
     *
     * @param array<string, mixed> $payload
     */
    public static function enqueue(int $voicemailId, array $payload = []): int
    {
        $lang = (string) ($payload['lang'] ?? (!empty($payload['mailbox']) ? self::languageOf((string) $payload['mailbox']) : 'default'));
        $bindingRef = self::resolveEngine('stt');
        $mailbox = preg_replace('/[^0-9]/', '', (string) ($payload['mailbox'] ?? ''));
        $origtime = (int) ($payload['origtime'] ?? 0);
        $key = $mailbox !== '' && $origtime > 0 ? "vm:{$mailbox}:{$origtime}" : "vm:archive:{$voicemailId}";
        return AiJobService::enqueue(
            'ai_voicemail',
            'transcribe',
            'voicemail',
            $voicemailId > 0 ? (string) $voicemailId : "{$mailbox}:{$origtime}",
            $key,
            $payload,
            $bindingRef
        );
    }

    /**
     * Processes a claimed `ai_jobs` row for `ai_voicemail`.
     * Verifies the voicemail still exists before writing to `voicemail_ai`.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function processJob(array $job): array
    {
        if (!self::pluginEnabled()) {
            throw new AiGatewayException('feature_disabled');
        }
        if (!self::transcribeOn()) {
            return ['skipped' => 'transcribe_disabled'];
        }

        $voicemailId = (int) ($job['entity_id'] ?? 0);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $mailbox = preg_replace('/[^0-9]/', '', (string) ($payload['mailbox'] ?? ''));
        $origtime = (int) ($payload['origtime'] ?? 0);
        $wavPath = (string) ($payload['wav_path'] ?? '');
        $txtPath = (string) ($payload['txt_path'] ?? '');
        $objectKey = '';

        if ($voicemailId > 0 && ($mailbox === '' || $origtime <= 0)) {
            try {
                $st = getDB()->prepare('SELECT mailbox, origtime, object_key FROM voicemail_archive WHERE id = ? LIMIT 1');
                $st->execute([$voicemailId]);
                $arch = $st->fetch(PDO::FETCH_ASSOC);
                if ($arch) {
                    $mailbox = preg_replace('/[^0-9]/', '', (string) $arch['mailbox']);
                    $origtime = (int) $arch['origtime'];
                    $objectKey = (string) ($arch['object_key'] ?? '');
                }
            } catch (\Throwable $e) {
            }
        }

        if ($txtPath !== '' && $origtime <= 0 && is_file($txtPath)) {
            $origtime = self::origtime($txtPath);
        }
        if ($txtPath !== '' && (!is_file($txtPath) || self::origtime($txtPath) !== $origtime)) {
            // Asterisk may renumber files when messages move between folders.
            $txtPath = self::spoolMessage($mailbox, $origtime);
            $wavPath = '';
        } elseif ($txtPath === '' && $wavPath === '') {
            $txtPath = self::spoolMessage($mailbox, $origtime);
        }

        if ($mailbox === '' || $origtime <= 0 || !self::voicemailExists($mailbox, $origtime, $voicemailId, $txtPath)) {
            return ['skipped' => 'voicemail_deleted'];
        }

        $existing = self::find($mailbox, $origtime);
        if ($existing !== null) {
            return [
                'mailbox' => $mailbox,
                'origtime' => $origtime,
                'transcript' => (string) $existing['transcript'],
                'summary' => (string) $existing['summary'],
                'cached' => true,
            ];
        }

        if ($wavPath === '' && $txtPath !== '') {
            $base = substr($txtPath, 0, -4);
            $wavPath = is_file($base . '.wav') ? $base . '.wav' : $base . '.WAV';
        }
        $wav = '';
        if ($wavPath !== '' && is_file($wavPath)) {
            $wav = (string) @file_get_contents($wavPath);
            if ($txtPath !== '' && self::origtime($txtPath) !== $origtime) {
                $wav = '';
            }
        } elseif (!empty($payload['wav_base64']) && is_string($payload['wav_base64'])) {
            $decoded = base64_decode($payload['wav_base64'], true);
            $wav = is_string($decoded) ? $decoded : '';
        }
        if ($wav === '' && $objectKey === '') {
            $st = getDB()->prepare('SELECT object_key FROM voicemail_archive WHERE mailbox = ? AND origtime = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$mailbox, $origtime]);
            $objectKey = (string) $st->fetchColumn();
        }
        if ($wav === '' && $objectKey !== '') {
            require_once __DIR__ . '/BucketFiles.php';
            $tmp = tempnam(sys_get_temp_dir(), 'aipbx-vm-job-');
            if ($tmp !== false) {
                try {
                    if (BucketFiles::download($objectKey, $tmp)) {
                        $wav = (string) @file_get_contents($tmp);
                    }
                } finally {
                    @unlink($tmp);
                }
            }
        }

        if ($wav === '') {
            throw new \RuntimeException('Voicemail audio unavailable');
        }

        $lang = (string) ($payload['lang'] ?? self::languageOf($mailbox));
        [$text, $engine] = self::transcribe($wav, $lang);
        if ($text === '') {
            throw new \RuntimeException('Voicemail transcription produced empty result');
        }

        require_once __DIR__ . '/PrivacyService.php';
        $text = PrivacyService::redactTranscriptIfEnabled($text);
        $summary = self::summaryOn() ? PrivacyService::redactTranscriptIfEnabled(self::summarize($text, $lang)) : '';

        // Re-verify existence right before persisting so late jobs never write for deleted voicemails.
        /** @phpstan-ignore booleanNot.alwaysFalse */
        if (!self::voicemailExists($mailbox, $origtime, $voicemailId, $txtPath)) {
            return ['skipped' => 'voicemail_deleted'];
        }

        getDB()->prepare('INSERT IGNORE INTO voicemail_ai (mailbox, origtime, transcript, summary, engine) VALUES (?, ?, ?, ?, ?)')
            ->execute([$mailbox, $origtime, $text, mb_substr($summary, 0, 500), $engine]);

        return [
            'mailbox' => $mailbox,
            'origtime' => $origtime,
            'transcript' => $text,
            'summary' => $summary,
            'engine' => $engine,
        ];
    }

    /**
     * Verifies that the voicemail still exists (either in `voicemail_archive` or on the spool)
     * before writing late AI results to `voicemail_ai`.
     */
    public static function voicemailExists(string $mailbox, int $origtime, int $voicemailId = 0, string $txtPath = ''): bool
    {
        if ($txtPath !== '' && is_file($txtPath) && ($origtime <= 0 || self::origtime($txtPath) === $origtime)) {
            return true;
        }
        if ($voicemailId > 0 && ($mailbox === '' || $origtime <= 0)) {
            try {
                $st = getDB()->prepare('SELECT 1 FROM voicemail_archive WHERE id = ? LIMIT 1');
                $st->execute([$voicemailId]);
                if ($st->fetchColumn() !== false) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
        }
        $cleanBox = preg_replace('/[^0-9]/', '', $mailbox);
        if ($cleanBox !== '' && $origtime > 0) {
            try {
                $st = getDB()->prepare('SELECT 1 FROM voicemail_archive WHERE mailbox = ? AND origtime = ? LIMIT 1');
                $st->execute([$cleanBox, $origtime]);
                if ($st->fetchColumn() !== false) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
            return self::spoolMessage($cleanBox, $origtime) !== '';
        }
        return false;
    }

    private static function spoolMessage(string $mailbox, int $origtime): string
    {
        if ($mailbox === '' || $origtime <= 0) {
            return '';
        }
        foreach (glob(sprintf('%s/%s/{INBOX,Old,Urgent}/msg*.txt', self::SPOOL, $mailbox), GLOB_BRACE) ?: [] as $txt) {
            if (self::origtime($txt) === $origtime) {
                return $txt;
            }
        }
        return '';
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
    public static function forNewest(string $mailbox, int $jobId = 0): ?array
    {
        if (!self::transcribeOn()) {
            return null;
        }
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        if ($user === 'www-data' || $user === 'root') {
            if ($jobId > 0) {
                return self::runNotificationJob($mailbox, $jobId);
            }
            $files = self::newestMessage($mailbox);
            return $files !== null ? self::forMessage($mailbox, $files['wav'], $files['txt']) : null;
        }
        $ch = curl_init('https://127.0.0.1/api/voicemail_ai.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['mailbox' => $mailbox, 'job_id' => $jobId]),
            CURLOPT_HTTPHEADER => ['X-AiPBX-Internal: ' . self::internalToken()],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 3,
            // The portal's own certificate is for its name, not 127.0.0.1.
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $d = json_decode((string) curl_exec($ch), true);
        if (!is_array($d) || !isset($d['transcript'])) {
            return null;
        }
        return ['transcript' => (string) $d['transcript'], 'summary' => (string) ($d['summary'] ?? '')];
    }

    private static function runNotificationJob(string $mailbox, int $jobId): ?array
    {
        $job = AiJobService::find($jobId);
        if (($job['feature'] ?? '') !== 'ai_voicemail' || ($job['payload']['mailbox'] ?? '') !== $mailbox) {
            return null;
        }
        $origtime = (int) ($job['payload']['origtime'] ?? 0);
        $claim = AiJobService::claimById($jobId, 'vm-notify:' . getmypid(), 180);
        if ($claim !== null) {
            $leaseOwner = (string) $claim['lease_owner'];
            try {
                $result = self::processJob($claim);
                AiJobService::complete($jobId, $result, $leaseOwner);
            } catch (\Throwable $e) {
                AiJobService::fail($jobId, $e->getMessage(), true, $leaseOwner);
            }
        }
        return $origtime > 0 ? self::find($mailbox, $origtime) : null;
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

        if (!self::voicemailExists($mailbox, $origtime, 0, $txtPath)) {
            return null;
        }
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
        $engine = self::resolveEngine('stt') ?? '';
        if ($engine === '') {
            return ['', ''];
        }
        try {
            if (str_starts_with($engine, 'cloud:')) {
                $providerId = substr($engine, 6);
                if (!self::isScopeEnabled('cloud') || getSystemSetting('privacy_block_cloud_ai', '0') === '1') {
                    return ['', ''];
                }
                if (!AiJobService::checkAndReserveQuota($providerId, 'stt', 1)) {
                    return ['', ''];
                }
                try {
                    return [trim(CloudAiService::stt($providerId, $wav, $lang)['text']), $engine];
                } catch (\Throwable $e) {
                    AiJobService::releaseReservedQuota($providerId, 'stt_seconds');
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            error_log("voicemail AI: speech to text ({$engine}) failed: " . $e->getMessage());
        }
        return ['', ''];
    }

    /** One sentence: who and what they want, with numbers and times; '' when not possible. */
    public static function summarize(string $transcript, string $lang): string
    {
        $engine = self::resolveEngine('chat');
        if ($engine === null || $engine === '') {
            return '';
        }
        $prompt = 'This is the transcript of a voicemail left on a company telephone. It may contain speech '
            . "recognition errors.\n\nTranscript: \"" . str_replace('"', "'", mb_substr($transcript, 0, 4000)) . "\"\n\n"
            . 'Write ONE short sentence in ' . CloudAiService::languageName($lang) . ' saying who called (if they said it) and '
            . 'what they want, keeping names, numbers, dates and times. No introduction, no quotes. '
            . 'If nothing meaningful was said, answer with an empty line.';
        try {
            if (str_starts_with($engine, 'cloud:')) {
                $providerId = substr($engine, 6);
                if (!self::isScopeEnabled('cloud') || getSystemSetting('privacy_block_cloud_ai', '0') === '1') {
                    return '';
                }
                if (!AiJobService::checkAndReserveQuota($providerId, 'llm', 1)) {
                    return '';
                }
                try {
                    $s = CloudAiService::chat($providerId, $prompt, 120, 20);
                } finally {
                    AiJobService::releaseReservedQuota($providerId, 'llm_tokens');
                }
                return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? '', " \"'"), 0, 300);
            }
        } catch (\Throwable $e) {
            error_log("voicemail AI: summary ({$engine}) failed: " . $e->getMessage());
        }
        return '';
    }
}
