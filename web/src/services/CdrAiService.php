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
            $st = getDB()->prepare('SELECT cdr_id, call_id, transcript, summary, engine, created_at FROM cdr_ai WHERE cdr_id = ?');
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
            ];
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
                $st = getDB()->prepare("SELECT cdr_id, transcript, summary, engine FROM cdr_ai WHERE cdr_id IN ({$in})");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(int) $r['cdr_id']] = [
                        'transcript' => (string) ($r['transcript'] ?? ''),
                        'summary' => (string) ($r['summary'] ?? ''),
                        'engine' => (string) ($r['engine'] ?? ''),
                    ];
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
    public static function forCdr(int $cdrId, string $lang = 'tr', bool $force = false, ?string $wavOverride = null): ?array
    {
        if (!$force) {
            $done = self::find($cdrId);
            if ($done !== null) {
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
        try {
            getDB()->prepare(
                'INSERT INTO cdr_ai (cdr_id, call_id, transcript, summary, engine)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   call_id = VALUES(call_id),
                   transcript = VALUES(transcript),
                   summary = VALUES(summary),
                   engine = VALUES(engine),
                   created_at = CURRENT_TIMESTAMP'
            )->execute([$cdrId, $callId, $text, mb_substr($summary, 0, 500), mb_substr($engine, 0, 100)]);
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
