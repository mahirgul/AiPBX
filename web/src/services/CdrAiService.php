<?php
/**
 * CDR Call Recording AI: on-demand transcription, LLM summary, and search storage.
 *
 * Uses the configured STT engine (local Whisper/Vosk or cloud via VoicemailAiService)
 * and optional cloud LLM summary (CloudAiService::engine('llm', $lang)), storing
 * results once per cdr_id in the `cdr_ai` table.
 */

require_once dirname(__DIR__) . '/db_helper.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once __DIR__ . '/ai/CloudAiService.php';
require_once __DIR__ . '/VoicemailAiService.php';
require_once __DIR__ . '/PrivacyService.php';
require_once __DIR__ . '/RecordingStorage.php';

class CdrAiService
{
    private const LANG_NAMES = ['tr' => 'Turkish', 'de' => 'German', 'en' => 'English'];

    /** @return array{cdr_id: int, call_id: string, transcript: string, summary: string, engine: string, created_at: string}|null */
    public static function find(int $cdrId): ?array
    {
        if ($cdrId <= 0) {
            return null;
        }
        try {
            $st = getDB()->prepare('SELECT * FROM cdr_ai WHERE cdr_id = ?');
            $st->execute([$cdrId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            return [
                'cdr_id' => (int) $r['cdr_id'],
                'call_id' => (string) ($r['call_id'] ?? ''),
                'transcript' => (string) ($r['transcript'] ?? ''),
                'summary' => (string) ($r['summary'] ?? ''),
                'engine' => (string) ($r['engine'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
            ] + self::qaFields($r);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Batch-loads stored AI transcripts/summaries for a list of CDR IDs.
     * @param list<int> $cdrIds
     * @return array<int, array{transcript: string, summary: string, engine: string}>
     */
    public static function forCdrIds(array $cdrIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $cdrIds), fn($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $st = getDB()->prepare("SELECT * FROM cdr_ai WHERE cdr_id IN ({$in})");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(int) $r['cdr_id']] = [
                        'transcript' => (string) ($r['transcript'] ?? ''),
                        'summary' => (string) ($r['summary'] ?? ''),
                        'engine' => (string) ($r['engine'] ?? ''),
                    ] + self::qaFields($r);
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function resolveRecording(int $cdrId): ?array
    {
        if ($cdrId <= 0) {
            return null;
        }
        $st = getDB()->prepare("SELECT id, coalesce(nullif(linkedid, ''), call_id) AS call_id, recording_path, agent_extension, caller_num FROM cdrs WHERE id = ?");
        $st->execute([$cdrId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Loads a local or S3 recording as WAV bytes (converting MP3/GSM/OGG via ffmpeg or sox when needed). */
    public static function loadWavBytes(string $recordingPath): ?string
    {
        if ($recordingPath === '') {
            return null;
        }
        if (RecordingStorage::isRemote($recordingPath)) {
            $key = RecordingStorage::key($recordingPath);
            $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            $tmp = tempnam(sys_get_temp_dir(), 'cdrai_s3_');
            if ($tmp === false) {
                return null;
            }
            $target = $tmp . ($ext !== '' ? '.' . $ext : '');
            @unlink($tmp);
            try {
                if (!BucketFiles::download($key, $target)) {
                    return null;
                }
                return self::toWavBytes($target);
            } finally {
                @unlink($target);
            }
        }

        $file = realpath($recordingPath);
        $allowedDir = realpath(MONITOR_STORAGE_PATH);
        if (!$file || !$allowedDir || strpos($file, rtrim($allowedDir, '/') . '/') !== 0 || !is_file($file)) {
            return null;
        }
        return self::toWavBytes($file);
    }

    private static function toWavBytes(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 12);
        if (strlen($head) === 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            $sz = @filesize($path);
            if ($sz !== false && $sz > 44 && $sz <= 24 * 1024 * 1024) {
                $raw = @file_get_contents($path);
                return $raw !== false && $raw !== '' ? $raw : null;
            }
        }
        $tmpWav = tempnam(sys_get_temp_dir(), 'cdrai_wav_');
        if ($tmpWav === false) {
            return null;
        }
        $wavOut = $tmpWav . '.wav';
        @unlink($tmpWav);
        try {
            $ok = false;
            $ffmpeg = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
            if ($ffmpeg !== '') {
                $cmd = escapeshellarg($ffmpeg) . ' -y -v error -i ' . escapeshellarg($path) . ' -ar 16000 -ac 1 -f wav ' . escapeshellarg($wavOut) . ' 2>/dev/null';
                $out = [];
                $code = 1;
                @exec($cmd, $out, $code);
                $ok = $code === 0 && is_file($wavOut) && filesize($wavOut) > 44;
            }
            if (!$ok) {
                $sox = trim((string) @shell_exec('command -v sox 2>/dev/null'));
                if ($sox !== '') {
                    $cmd = escapeshellarg($sox) . ' ' . escapeshellarg($path) . ' -r 16000 -c 1 -b 16 -t wav ' . escapeshellarg($wavOut) . ' 2>/dev/null';
                    $out = [];
                    $code = 1;
                    @exec($cmd, $out, $code);
                    $ok = $code === 0 && is_file($wavOut) && filesize($wavOut) > 44;
                }
            }
            if (!$ok) {
                return null;
            }
            $raw = @file_get_contents($wavOut);
            return $raw !== false && $raw !== '' ? $raw : null;
        } finally {
            @unlink($wavOut);
        }
    }

    /**
     * Returns stored transcript/summary for $cdrId, or creates it from the call recording.
     * @return array{cdr_id: int, call_id: string, transcript: string, summary: string, engine: string, created_at: string}|null
     */
    public static function forCdr(int $cdrId, string $lang = 'tr', bool $force = false, ?string $wavOverride = null, string $source = 'manual'): ?array
    {
        if (!$force) {
            $done = self::find($cdrId);
            if ($done !== null && $done['transcript'] !== '') {     // '' = an automatic try that found nothing
                return $done;
            }
        }
        $lang = array_key_exists($lang, self::LANG_NAMES) ? $lang : 'tr';
        $cdr = self::resolveRecording($cdrId);
        if ($cdr === null && $wavOverride === null) {
            return null;
        }
        $wav = $wavOverride ?? self::loadWavBytes((string) ($cdr['recording_path'] ?? ''));
        if ($wav === null || $wav === '') {
            return null;
        }
        [$text, $engine] = VoicemailAiService::transcribe($wav, $lang);
        if ($text === '') {
            return null;
        }
        $text = PrivacyService::redactTranscriptIfEnabled($text);
        $summary = self::summarize($text, $lang);
        if ($summary !== '') {
            $summary = PrivacyService::redactTranscriptIfEnabled($summary);
        }
        $callId = mb_substr((string) ($cdr['call_id'] ?? ''), 0, 64);
        $qa = self::review($text, $lang);
        try {
            getDB()->prepare(
                'INSERT INTO cdr_ai (cdr_id, call_id, transcript, summary, engine, qa_score, qa_sentiment, qa_resolved, qa_notes, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   call_id = VALUES(call_id),
                   transcript = VALUES(transcript),
                   summary = VALUES(summary),
                   engine = VALUES(engine),
                   qa_score = VALUES(qa_score),
                   qa_sentiment = VALUES(qa_sentiment),
                   qa_resolved = VALUES(qa_resolved),
                   qa_notes = VALUES(qa_notes),
                   source = VALUES(source),
                   created_at = CURRENT_TIMESTAMP'
            )->execute([$cdrId, $callId, $text, mb_substr($summary, 0, 500), mb_substr($engine, 0, 100),
                $qa['qa_score'] ?? null, $qa['qa_sentiment'] ?? '', isset($qa['qa_resolved']) ? (int) $qa['qa_resolved'] : null,
                $qa['qa_notes'] ?? '', $source === 'auto' ? 'auto' : 'manual']);
        } catch (\Throwable $e) {
            error_log('cdr AI: not stored: ' . $e->getMessage());
        }
        return self::find($cdrId) ?? [
            'cdr_id' => $cdrId,
            'call_id' => $callId,
            'transcript' => $text,
            'summary' => $summary,
            'engine' => $engine,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** Short 1-2 sentence summary of a call transcript when a cloud LLM is configured. */
    public static function summarize(string $transcript, string $lang): string
    {
        $engine = CloudAiService::engine('llm', $lang);
        if (!str_starts_with($engine, 'cloud:')) {
            return '';
        }
        $langName = self::LANG_NAMES[$lang] ?? 'Turkish';
        $prompt = "This is the transcript of a recorded phone call at a company. It may contain speech recognition errors.\n\n"
            . 'Transcript: "' . str_replace('"', "'", mb_substr($transcript, 0, 8000)) . "\"\n\n"
            . "Write 1 or 2 concise sentences in {$langName} summarizing who spoke, the topic, and any concrete outcome, callback, or number. "
            . 'No introduction, no bullet points, no quotes. If nothing meaningful was said, return an empty line.';
        try {
            $s = CloudAiService::chat(substr($engine, 6), $prompt, 180, 25);
            return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? '', " \"'"), 0, 500);
        } catch (\Throwable $e) {
            error_log("cdr AI: summary ({$engine}) failed: " . $e->getMessage());
            return '';
        }
    }

    // ------------------------------------------------------------ quality review (Auto-QA)

    /** @return array{qa_score: ?int, qa_sentiment: string, qa_resolved: ?bool, qa_notes: string, source: string} */
    private static function qaFields(array $r): array
    {
        return [
            'qa_score' => isset($r['qa_score']) ? (int) $r['qa_score'] : null,
            'qa_sentiment' => (string) ($r['qa_sentiment'] ?? ''),
            'qa_resolved' => isset($r['qa_resolved']) ? (bool) $r['qa_resolved'] : null,
            'qa_notes' => (string) ($r['qa_notes'] ?? ''),
            'source' => (string) ($r['source'] ?? 'manual'),
        ];
    }

    /** @return array{criteria: string, auto: bool, daily_max: int} */
    public static function qaSettings(): array
    {
        return [
            'criteria' => (string) getSystemSetting('cdr_qa_criteria', ''),
            'auto' => getSystemSetting('cdr_qa_auto', '0') === '1',
            'daily_max' => max(1, min(1000, (int) getSystemSetting('cdr_qa_daily_max', '30'))),
        ];
    }

    public static function saveQaSettings(array $post): void
    {
        $criteria = trim((string) ($post['qa_criteria'] ?? ''));
        if (mb_strlen($criteria) > 3000) {
            throw new \InvalidArgumentException(sprintf(t('ai_apps.err_text'), 3000));
        }
        setSystemSetting('cdr_qa_criteria', $criteria);
        setSystemSetting('cdr_qa_auto', !empty($post['qa_auto']) && $criteria !== '' ? '1' : '0');
        setSystemSetting('cdr_qa_daily_max', (string) max(1, min(1000, (int) ($post['qa_daily_max'] ?? 30))));
        writeAuditLog('ai_cloud', 'cdr_qa', 'settings', 'Call quality review: ' . ($criteria === '' ? 'off'
            : (!empty($post['qa_auto']) ? 'automatic' : 'on request')), 'update', $_SESSION['user_id'] ?? null);
    }

    /**
     * The call against the administrator's criteria, by the cloud language model:
     * score 0-100, the caller's mood, resolved or not, a short note. Null without
     * criteria or a usable cloud model (not set up, locked, over its limit).
     *
     * @return array{qa_score: int, qa_sentiment: string, qa_resolved: ?bool, qa_notes: string}|null
     */
    public static function review(string $transcript, string $lang, ?callable $chat = null): ?array
    {
        $criteria = self::qaSettings()['criteria'];
        $engine = CloudAiService::engine('llm', $lang);
        if ($criteria === '' || trim($transcript) === '' || !str_starts_with($engine, 'cloud:')) {
            return null;
        }
        $langName = self::LANG_NAMES[$lang] ?? 'Turkish';
        $prompt = "You review a recorded phone call of a company. The transcript comes from speech recognition and may contain errors.\n\n"
            . "The company's criteria:\n" . $criteria . "\n\n"
            . 'Transcript: "' . str_replace('"', "'", mb_substr($transcript, 0, 8000)) . "\"\n\n"
            . 'Reply with JSON only: {"score": <0-100, how well the company\'s side met the criteria>, '
            . '"sentiment": "positive|neutral|negative" (the caller), "resolved": true|false|null, '
            . "\"notes\": \"<one sentence in {$langName}: the main reason for the score>\"}";
        try {
            $raw = $chat ? $chat($prompt) : CloudAiService::chat(substr($engine, 6), $prompt, 250, 25);
        } catch (\Throwable $e) {
            error_log("cdr AI: review ({$engine}) failed: " . $e->getMessage());
            return null;
        }
        $d = preg_match('/\{.*\}/s', (string) $raw, $m) ? json_decode($m[0], true) : null;
        if (!is_array($d) || !is_numeric($d['score'] ?? null)) {
            return null;
        }
        return [
            'qa_score' => max(0, min(100, (int) round((float) $d['score']))),
            'qa_sentiment' => in_array($d['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true) ? $d['sentiment'] : '',
            'qa_resolved' => is_bool($d['resolved'] ?? null) ? $d['resolved'] : null,
            'qa_notes' => PrivacyService::redactTranscriptIfEnabled(mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500)),
        ];
    }

    /**
     * The reviewed calls of each agent since a time (the call-center board): how many,
     * the average score, how many were resolved of those that say, how many callers
     * were unhappy. Best average first.
     *
     * @return list<array{extension: string, name: string, calls: int, avg: int, resolved_pct: ?int, negative: int}>
     */
    public static function qaByAgent(string $since, string $queue = ''): array
    {
        $sql = "SELECT c.agent_extension AS ext, MAX(c.agent_name) AS name, COUNT(*) AS n, AVG(a.qa_score) AS avg_score,
                       SUM(a.qa_resolved = 1) AS resolved, SUM(a.qa_resolved IS NOT NULL) AS judged, SUM(a.qa_sentiment = 'negative') AS negative
                FROM cdr_ai a JOIN cdrs c ON c.id = a.cdr_id
                WHERE a.qa_score IS NOT NULL AND c.start_time >= ? AND c.agent_extension != ''"
            . ($queue !== '' ? ' AND c.queue_name = ?' : '')
            . ' GROUP BY c.agent_extension ORDER BY avg_score DESC, n DESC LIMIT 100';
        try {
            $st = getDB()->prepare($sql);
            $st->execute($queue !== '' ? [$since, $queue] : [$since]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];          // before the migration
        }
        return array_map(fn($r) => [
            'extension' => (string) $r['ext'], 'name' => (string) ($r['name'] ?? ''), 'calls' => (int) $r['n'],
            'avg' => (int) round((float) $r['avg_score']),
            'resolved_pct' => (int) $r['judged'] > 0 ? (int) round(100 * (int) $r['resolved'] / (int) $r['judged']) : null,
            'negative' => (int) $r['negative'],
        ], $rows);
    }

    /**
     * Automatic review (bin/cdr_qa.php, every 10 minutes): recorded, answered calls of the
     * last day that have no transcript yet, newest first, until the daily maximum.
     *
     * @return int calls reviewed
     */
    public static function autoReview(int $perRun = 10): int
    {
        $s = self::qaSettings();
        if (!$s['auto'] || $s['criteria'] === '') {
            return 0;
        }
        $db = getDB();
        $done = (int) $db->query("SELECT COUNT(*) FROM cdr_ai WHERE source = 'auto' AND created_at >= CURDATE()")->fetchColumn();
        $left = min($perRun, $s['daily_max'] - $done);
        if ($left <= 0) {
            return 0;
        }
        $st = $db->prepare("SELECT c.id FROM cdrs c LEFT JOIN cdr_ai a ON a.cdr_id = c.id
            WHERE a.cdr_id IS NULL AND c.recording_path != '' AND c.billsec >= 10
              AND c.start_time >= NOW() - INTERVAL 1 DAY AND c.end_time <= NOW() - INTERVAL 2 MINUTE
            ORDER BY c.id DESC LIMIT " . (int) $left);
        $st->execute();
        $lang = strtolower(substr((string) getSystemSetting('default_lang', 'tr'), 0, 2));
        $lang = array_key_exists($lang, self::LANG_NAMES) ? $lang : 'tr';
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (self::forCdr((int) $id, $lang, false, null, 'auto') !== null) {
                $n++;
            } else {
                // Not transcribable (no audio, no engine): remembered so it is not tried every 10 minutes.
                $db->prepare("INSERT IGNORE INTO cdr_ai (cdr_id, transcript, source) VALUES (?, '', 'auto')")->execute([(int) $id]);
            }
        }
        return $n;
    }

    public static function deleteForCdr(int $cdrId, ?string $linkedId = null): void
    {
        if ($cdrId <= 0 && ($linkedId === null || $linkedId === '')) {
            return;
        }
        try {
            if ($linkedId !== null && $linkedId !== '') {
                getDB()->prepare('DELETE FROM cdr_ai WHERE cdr_id = ? OR call_id = ?')->execute([$cdrId, $linkedId]);
            } else {
                getDB()->prepare('DELETE FROM cdr_ai WHERE cdr_id = ?')->execute([$cdrId]);
            }
        } catch (\Throwable $e) {
            // Table may not exist yet before migration runs.
        }
    }
}
