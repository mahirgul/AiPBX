<?php

declare(strict_types=1);

require_once __DIR__ . '/../MailTemplateService.php';
require_once __DIR__ . '/../../db_helper.php';

/**
 * Unified AI Reminders & Wake-Up Calls (ai_reminders + Asterisk Originate).
 */
final class ReminderService
{
    public const KINDS = ['reminder', 'wakeup'];
    public const RECURRENCES = ['none', 'daily', 'weekdays'];

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function create(array $data, ?int $actorUserId = null): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', trim((string) ($data['extension'] ?? '')));
        if ($ext === '') {
            return ['ok' => false, 'error' => t('reminders.err_extension')];
        }
        $st = getDB()->prepare('SELECT id, extension, full_name FROM sys_users WHERE is_active = 1 AND extension = ? LIMIT 1');
        $st->execute([$ext]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['ok' => false, 'error' => t('reminders.err_extension')];
        }

        $kind = (string) ($data['kind'] ?? 'reminder');
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'reminder';
        $recurrence = (string) ($data['recurrence'] ?? 'none');
        $recurrence = in_array($recurrence, self::RECURRENCES, true) ? $recurrence : 'none';
        $rawTime = trim((string) ($data['remind_at'] ?? ''));
        $ts = strtotime(str_replace('T', ' ', $rawTime));
        if ($rawTime === '' || $ts === false) {
            return ['ok' => false, 'error' => t('reminders.err_time')];
        }
        $remindAt = date('Y-m-d H:i:s', $ts);

        $msg = trim((string) ($data['message'] ?? ''));
        if ($msg === '') {
            $msg = $kind === 'wakeup' ? t('reminders.default_wakeup_msg') : '';
        }
        if ($msg === '') {
            return ['ok' => false, 'error' => t('reminders.err_message')];
        }
        $msg = mb_substr($msg, 0, 500);

        $ins = getDB()->prepare(
            'INSERT INTO ai_reminders (user_id, extension, kind, message, remind_at, recurrence, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([(int) $user['id'], $ext, $kind, $msg, $remindAt, $recurrence, 'pending', $actorUserId]);
        return ['ok' => true, 'id' => (int) getDB()->lastInsertId()];
    }

    /** @return list<array<string, mixed>> */
    public static function listForUser(?int $userId, bool $isAdmin, string $extension = ''): array
    {
        $db = getDB();
        if ($isAdmin) {
            return $db->query(
                'SELECT r.*, u.full_name FROM ai_reminders r LEFT JOIN sys_users u ON u.id = r.user_id ORDER BY r.remind_at DESC, r.id DESC LIMIT 200'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $st = $db->prepare(
            'SELECT r.*, u.full_name FROM ai_reminders r LEFT JOIN sys_users u ON u.id = r.user_id
             WHERE r.user_id = ? OR (r.extension = ? AND ? <> "")
             ORDER BY r.remind_at DESC, r.id DESC LIMIT 100'
        );
        $st->execute([$userId ?? 0, $extension, $extension]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function cancel(int $id, ?int $userId, bool $isAdmin): bool
    {
        $sql = $isAdmin
            ? "UPDATE ai_reminders SET status = 'cancelled' WHERE id = ? AND status = 'pending'"
            : "UPDATE ai_reminders SET status = 'cancelled' WHERE id = ? AND status = 'pending' AND user_id = ?";
        $st = getDB()->prepare($sql);
        $st->execute($isAdmin ? [$id] : [$id, $userId ?? 0]);
        return $st->rowCount() > 0;
    }

    public static function snooze(int $id, int $minutes, ?int $userId, bool $isAdmin): bool
    {
        $minutes = max(1, min(1440, $minutes));
        $nextAt = date('Y-m-d H:i:s', time() + $minutes * 60);
        $sql = $isAdmin
            ? "UPDATE ai_reminders SET remind_at = ?, status = 'pending', attempts = 0 WHERE id = ? AND status IN ('pending', 'failed')"
            : "UPDATE ai_reminders SET remind_at = ?, status = 'pending', attempts = 0 WHERE id = ? AND status IN ('pending', 'failed') AND user_id = ?";
        $st = getDB()->prepare($sql);
        $st->execute($isAdmin ? [$nextAt, $id] : [$nextAt, $id, $userId ?? 0]);
        return $st->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public static function dueReminders(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $st = getDB()->prepare(
            "SELECT * FROM ai_reminders WHERE status = 'pending' AND remind_at <= ? AND attempts < max_attempts ORDER BY remind_at ASC LIMIT {$limit}"
        );
        $st->execute([date('Y-m-d H:i:s')]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Dispatches one due reminder via Asterisk Originate (or injected test callback).
     *
     * @param array<string, mixed> $rem
     * @param callable(array<string, mixed>): bool|null $originateFn
     */
    public static function dispatchOne(array $rem, ?callable $originateFn = null): bool
    {
        $id = (int) $rem['id'];
        $db = getDB();
        $lock = 'aipbx:reminder:' . $id;
        if ((int) DBHelper::fetchColumn('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return false;
        }
        try {
            $claim = $db->prepare("UPDATE ai_reminders SET attempts = attempts + 1, last_attempt_at = NOW()
                WHERE id = ? AND status = 'pending' AND remind_at = ? AND remind_at <= ?
                AND attempts = ? AND attempts < max_attempts");
            $claim->execute([$id, $rem['remind_at'], date('Y-m-d H:i:s'), (int) $rem['attempts']]);
            if ($claim->rowCount() !== 1) {
                return false;
            }
            return self::dispatchClaimed($db, $rem, $originateFn);
        } finally {
            DBHelper::fetchColumn('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function dispatchClaimed(PDO $db, array $rem, ?callable $originateFn): bool
    {
        $id = (int) $rem['id'];
        $attempts = (int) $rem['attempts'] + 1;
        $claimed = [$id, $rem['remind_at'], $attempts];
        $where = " WHERE id = ? AND status = 'pending' AND remind_at = ? AND attempts = ?";
        $ok = false;
        $err = '';
        try {
            $ok = $originateFn !== null ? (bool) $originateFn($rem) : self::originateViaAmi($rem);
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 250);
        }

        if ($ok) {
            $rec = (string) ($rem['recurrence'] ?? 'none');
            if ($rec === 'daily' || $rec === 'weekdays') {
                $next = self::nextOccurrence((string) $rem['remind_at'], $rec);
                $db->prepare('UPDATE ai_reminders SET remind_at = ?, attempts = 0, last_error = NULL' . $where)
                    ->execute([$next, ...$claimed]);
            } else {
                $db->prepare("UPDATE ai_reminders SET status = 'completed', last_error = NULL" . $where)->execute($claimed);
            }
            return true;
        }

        $max = max(1, (int) ($rem['max_attempts'] ?? 3));
        if ($attempts >= $max) {
            $failed = $db->prepare("UPDATE ai_reminders SET status = 'failed', last_error = ?" . $where);
            $failed->execute([$err !== '' ? $err : 'No answer after max attempts', ...$claimed]);
            if ($failed->rowCount() === 1) {
                self::notifyFallback($rem);
            }
        } else {
            $db->prepare('UPDATE ai_reminders SET last_error = ?' . $where)->execute([$err !== '' ? $err : 'Unanswered', ...$claimed]);
        }
        return false;
    }

    public static function nextOccurrence(string $currentAt, string $recurrence): string
    {
        $now = time();
        $ts = strtotime($currentAt) ?: $now;
        $next = strtotime('+1 day', $ts);
        if ($next <= $now) {
            $next = strtotime(date('Y-m-d', $now) . ' ' . date('H:i:s', $ts));
            if ($next <= $now) {
                $next = strtotime('+1 day', $next);
            }
        }
        if ($recurrence === 'weekdays') {
            while ((int) date('N', $next) >= 6) {
                $next = strtotime('+1 day', $next);
            }
        }
        return date('Y-m-d H:i:s', $next);
    }

    /**
     * @param array<string, mixed> $rem
     * @return array<string, string>
     */
    public static function buildOriginateFields(array $rem, string $ttsUuid = ''): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', (string) ($rem['extension'] ?? ''));
        $callerId = ((string) ($rem['kind'] ?? '') === 'wakeup') ? 'Wakeup <*88>' : 'Reminder <*88>';
        $useTts = strlen($ttsUuid) === 36;
        return [
            'Action' => 'Originate',
            'Channel' => "Local/{$ext}@from-internal-pbx-ortak/n",
            'Application' => $useTts ? 'AudioSocket' : 'Playback',
            'Data' => $useTts ? ($ttsUuid . ',127.0.0.1:8791') : 'beep',
            'CallerID' => $callerId,
            'Timeout' => '25000',
            'Async' => 'false',
            'Variable' => 'AIPBX_REMINDER_ID=' . (int) ($rem['id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $rem */
    private static function prepareTtsCallUuid(array $rem): string
    {
        if (!is_readable('/etc/aipbx/ai.token')) {
            return '';
        }
        $msg = trim((string) ($rem['message'] ?? ''));
        if ($msg === '') {
            return '';
        }
        try {
            require_once __DIR__ . '/AiAppService.php';
            $key = AiAppService::dialplanKey();
            $lang = MailTemplateService::systemLanguage();
            require_once __DIR__ . '/AiBindingService.php';
            $model = AiAppService::resolveTaskBinding(0, 'tts', (string) AiBindingService::resolve('reminders', 'tts'), $lang, true);
            if ($key === '' || $model === '') {
                return '';
            }
            $postBody = http_build_query([
                'app' => 1,
                'key' => $key,
                'model' => $model,
                'speed' => '1.00',
                'max' => 10,
                'caller' => (string) ($rem['extension'] ?? ''),
                'text' => $msg,
            ]);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postBody,
                    'timeout' => 3.0,
                ],
            ]);
            $uuid = trim((string) @file_get_contents('http://127.0.0.1:8790/v1/calls/start', false, $ctx));
            return strlen($uuid) === 36 ? $uuid : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @param array<string, mixed> $rem */
    private static function originateViaAmi(array $rem): bool
    {
        $ext = (string) preg_replace('/[^0-9]/', '', (string) $rem['extension']);
        if ($ext === '') {
            return false;
        }
        $ttsUuid = self::prepareTtsCallUuid($rem);
        $fields = self::buildOriginateFields($rem, $ttsUuid);
        $cmd = '';
        foreach ($fields as $k => $v) {
            $cmd .= "{$k}: {$v}\r\n";
        }
        $raw = self::sendAmiOriginate($cmd . "\r\n", 30);
        return stripos($raw, 'Response: Success') !== false;
    }

    private static function sendAmiOriginate(string $cmd, int $timeoutSec = 30): string
    {
        $host = defined('AMI_HOST') ? (string) AMI_HOST : '127.0.0.1';
        $port = defined('AMI_PORT') ? (int) AMI_PORT : 5038;
        $user = defined('AMI_USER') ? (string) AMI_USER : '';
        $pass = defined('AMI_PASS') ? (string) AMI_PASS : '';
        if ($user === '' || $pass === '') {
            return '';
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        if (!is_resource($fp)) {
            return '';
        }

        stream_set_timeout($fp, 3);
        fwrite($fp, "Action: Login\r\nUsername: {$user}\r\nSecret: {$pass}\r\n\r\n");

        $authenticated = false;
        $start = time();
        while (!feof($fp) && (time() - $start < 3)) {
            $line = fgets($fp, 4096);
            if ($line === false) {
                break;
            }
            if (strpos($line, 'Response: Success') !== false) {
                $authenticated = true;
            }
            if (trim($line) === '' && $authenticated) {
                break;
            }
        }

        if (!$authenticated) {
            fclose($fp);
            return '';
        }

        stream_set_timeout($fp, max(5, $timeoutSec));
        fwrite($fp, $cmd);
        $response = '';
        $start = time();
        while (!feof($fp) && (time() - $start < max(5, $timeoutSec))) {
            $line = fgets($fp, 4096);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (trim($line) === '' && strpos($response, 'Response:') !== false) {
                break;
            }
        }

        fwrite($fp, "Action: Logoff\r\n\r\n");
        fclose($fp);
        return $response;
    }

    /** @param array<string, mixed> $rem */
    private static function notifyFallback(array $rem): void
    {
        if (empty($rem['user_id'])) {
            return;
        }
        try {
            $st = getDB()->prepare('SELECT email, full_name FROM sys_users WHERE id = ? LIMIT 1');
            $st->execute([(int) $rem['user_id']]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u && !empty($u['email'])) {
                MailTemplateService::send('ai_request', (string) $u['email'], MailTemplateService::systemLanguage(), [
                    'request' => 'Missed Reminder (' . $rem['extension'] . ')',
                    'caller' => 'AI Reminder',
                    'caller_name' => 'AI Reminder',
                    'caller_number' => (string) $rem['extension'],
                    'transcript' => (string) $rem['message'],
                    'app' => 'Reminders',
                    'date' => (string) $rem['remind_at'],
                    'portal_link' => '/reminders',
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
}
