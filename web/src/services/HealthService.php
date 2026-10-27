<?php
require_once __DIR__ . '/health/HealthChecks.php';
require_once __DIR__ . '/MailTemplateService.php';
require_once dirname(__DIR__) . '/repositories/CdrReportRepository.php';
require_once __DIR__ . '/ForgotPasswordService.php';

/**
 * Admin → Health checks (docs/health.md): which checks are due, their results,
 * the alarm e-mails and the daily summary for managers.
 *
 * bin/aipbx_health.php runs every minute as root (cron) and calls runDue();
 * the page only changes settings and shows the results (a check it asks for
 * runs at the next minute, since most checks need root).
 *
 * Alarms: a check that turns warn/fail sends one e-mail to the chosen people
 * (checks every 15 minutes or more often only after two bad results in a row,
 * so a short hiccup stays quiet); it is repeated after health_repeat_hours
 * while the problem lasts, and a "back to normal" e-mail follows when it is
 * fixed. All problems of one run go into one e-mail.
 *
 * Two rules keep that mail worth reading:
 *  - one problem, one mail per health_repeat_hours: a check that fails,
 *    recovers and fails again is the same incident, not two alarms;
 *  - quiet hours (health_quiet_hours) hold warnings and recoveries, never a
 *    failure, and hold them without recording them as reported, so the run
 *    after the window mails what is still wrong.
 */
class HealthService
{
    public const SETTINGS = [
        'health_recipients' => '[]',
        'health_extra_emails' => '',
        'health_repeat_hours' => '6',
        'health_quiet_hours' => '',
        'health_summary_enabled' => '1',
        'health_summary_time' => '08:00',
    ];

    public const REPEAT_HOURS = [1, 3, 6, 12, 24];

    // ------------------------------------------------------------ settings

    public static function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $k => $default) {
            $out[$k] = (string) getSystemSetting($k, $default);
        }
        return $out;
    }

    /** @return int[] user ids that get the e-mails */
    public static function recipientIds(?string $json = null): array
    {
        $ids = json_decode($json ?? (string) getSystemSetting('health_recipients', '[]'), true);
        return array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
    }

    /** @return string[] */
    public static function extraEmails(?string $text = null): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text ?? (string) getSystemSetting('health_extra_emails', ''))) ?: [] as $e) {
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($e)] = $e;
            }
        }
        return array_values($out);
    }

    /**
     * Where the e-mails go: the chosen users (in their language) and the extra
     * addresses (system language).
     *
     * @return list<array{email: string, lang: string, user_id: ?int}>
     */
    public static function recipients(): array
    {
        $out = [];
        $ids = self::recipientIds();
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = getDB()->prepare("SELECT id, email FROM sys_users WHERE id IN ($in) AND is_active = 1 AND email IS NOT NULL AND email <> ''");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $out[strtolower($u['email'])] = ['email' => $u['email'], 'lang' => MailTemplateService::languageFor($u['email'], (int) $u['id']), 'user_id' => (int) $u['id']];
            }
        }
        foreach (self::extraEmails() as $e) {
            $out[strtolower($e)] ??= ['email' => $e, 'lang' => MailTemplateService::systemLanguage(), 'user_id' => null];
        }
        return array_values($out);
    }

    /** Users the page offers as recipients (active, with an e-mail address). */
    public static function candidateUsers(): array
    {
        return getDB()->query("SELECT id, username, full_name, email, role FROM sys_users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY role = 'admin' DESC, full_name, username")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $known = array_map('intval', array_column(self::candidateUsers(), 'id'));
            $ids = array_values(array_intersect(array_map('intval', (array) ($data['health_recipients'] ?? [])), $known));
            $extraText = (string) ($data['health_extra_emails'] ?? '');
            foreach (preg_split('/[\s,;]+/', trim($extraText)) ?: [] as $e) {
                if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception(sprintf(t('health.err_email'), $e));
                }
            }
            $time = (string) ($data['health_summary_time'] ?? '08:00');
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                throw new \Exception(t('health.err_time'));
            }
            $repeat = (int) ($data['health_repeat_hours'] ?? 6);
            $quiet = trim((string) ($data['health_quiet_hours'] ?? ''));
            if ($quiet !== '' && !self::validQuietWindow($quiet)) {
                throw new \Exception(t('health.err_quiet'));
            }
            $v = [
                'health_recipients' => json_encode($ids),
                'health_extra_emails' => implode("\n", self::extraEmails($extraText)),
                'health_repeat_hours' => (string) (in_array($repeat, self::REPEAT_HOURS, true) ? $repeat : 6),
                'health_quiet_hours' => $quiet,
                'health_summary_enabled' => !empty($data['health_summary_enabled']) ? '1' : '0',
                'health_summary_time' => $time,
            ];
            $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($v as $k => $val) {
                $stmt->execute([$k, $val]);
            }

            // Interval of each check (minutes; 0 = off).
            $up = $db->prepare('INSERT INTO health_checks (check_key, interval_min) VALUES (?, ?) ON DUPLICATE KEY UPDATE interval_min = VALUES(interval_min)');
            foreach (HealthChecks::DEFAULTS as $key => $default) {
                if (!isset($data['interval'][$key])) {
                    continue;
                }
                $min = (int) $data['interval'][$key];
                $up->execute([$key, in_array($min, HealthChecks::INTERVALS, true) ? $min : $default]);
            }
            writeAuditLog('health', 'settings', 0, 'Health checks: ' . count($ids) . ' users, ' . count(self::extraEmails($extraText)) . ' extra addresses', 'update', $_SESSION['user_id'] ?? null);
            return t('health.saved');
        });
    }

    /** "Check now" on the page: the cron run of the next minute does every check. */
    public static function requestRunAll(string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () {
            getDB()->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('health_run_all', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
            return t('health.run_queued');
        });
    }

    // ------------------------------------------------------------ results

    /**
     * Every check with its interval and last result, in page order.
     *
     * @return array<string, array{key: string, interval: int, status: string, message: string, checked_at: ?string, changed_at: ?string, bad_streak: int, alerted_status: string, alerted_at: ?string}>
     */
    public static function checks(): array
    {
        $rows = [];
        foreach (getDB()->query('SELECT * FROM health_checks')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['check_key']] = $r;
        }
        $out = [];
        foreach (HealthChecks::DEFAULTS as $key => $default) {
            $r = $rows[$key] ?? [];
            $out[$key] = [
                'key' => $key,
                'interval' => isset($r['interval_min']) ? (int) $r['interval_min'] : $default,
                'status' => (string) ($r['status'] ?? 'unknown'),
                'message' => (string) ($r['message'] ?? ''),
                'checked_at' => $r['checked_at'] ?? null,
                'changed_at' => $r['changed_at'] ?? null,
                'bad_streak' => (int) ($r['bad_streak'] ?? 0),
                'alerted_status' => (string) ($r['alerted_status'] ?? ''),
                'alerted_at' => $r['alerted_at'] ?? null,
            ];
        }
        return $out;
    }

    /** Keys whose interval has passed (all of them with $all). */
    public static function due(array $checks, int $now, bool $all = false): array
    {
        $due = [];
        foreach ($checks as $key => $c) {
            if ($c['interval'] <= 0) {
                continue;
            }
            $last = $c['checked_at'] ? strtotime($c['checked_at']) : 0;
            // 30 s slack: a cron run a little early still counts.
            if ($all || $now - $last >= $c['interval'] * 60 - 30) {
                $due[] = $key;
            }
        }
        return $due;
    }

    /**
     * What a new result means for the e-mails: 'alarm', 'recovered' or null.
     * Pure, so the rules are tested on their own.
     *
     * $quiet holds a warning and a recovery, never a failure, and holds them by
     * answering null: nothing is recorded as reported, so the run after the
     * quiet window mails whatever is still wrong (and says nothing about a
     * problem that fixed itself overnight).
     */
    public static function decide(array $prev, string $status, int $interval, int $now, int $repeatHours,
                                  bool $quiet = false): ?string
    {
        $bad = in_array($status, ['warn', 'fail'], true);
        if (!$bad) {
            return ($status === 'ok' && $prev['alerted_status'] !== '' && !$quiet) ? 'recovered' : null;
        }
        $streak = in_array($prev['status'], ['warn', 'fail'], true) ? $prev['bad_streak'] + 1 : 1;
        $needed = $interval <= 15 ? 2 : 1;
        if ($streak < $needed) {
            return null;
        }
        if ($quiet && $status !== 'fail') {
            return null;
        }
        if ($prev['alerted_status'] === '') {
            // A problem that flaps is one problem: a fresh alarm waits for the
            // window that starts at this check's last mail, not at its last alarm.
            $last = $prev['alerted_at'] ? strtotime($prev['alerted_at']) : 0;
            return ($last > 0 && $now - $last < $repeatHours * 3600) ? null : 'alarm';
        }
        // Worse than what was reported (warn → fail) or the repeat time has passed.
        if ($prev['alerted_status'] === 'warn' && $status === 'fail') {
            return 'alarm';
        }
        $last = $prev['alerted_at'] ? strtotime($prev['alerted_at']) : 0;
        return $now - $last >= $repeatHours * 3600 ? 'alarm' : null;
    }

    /** True inside the configured quiet window, on the clock the summary uses. */
    public static function quietHoursNow(?int $ts = null): bool
    {
        return self::inQuietWindow((string) getSystemSetting('health_quiet_hours', ''), date('H:i', $ts ?? time()));
    }

    /**
     * "22:00-07:00" is a window that crosses midnight; "09:00-17:00" does not.
     * Empty, malformed or zero-length is no window at all, which is the safe
     * answer: a typo must never silence alarms.
     */
    public static function inQuietWindow(string $window, string $time): bool
    {
        if (!self::validQuietWindow($window) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            return false;
        }
        [$start, $end] = explode('-', $window, 2);
        if ($start === $end) {
            return false;
        }
        return $start < $end
            ? ($time >= $start && $time < $end)
            : ($time >= $start || $time < $end);
    }

    public static function validQuietWindow(string $window): bool
    {
        return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]$/', $window) === 1;
    }

    /**
     * Runs the due checks (cron) or all of them, stores the results and sends
     * the e-mails. Returns the results by key.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runDue(bool $all = false, bool $mail = true): array
    {
        $db = getDB();
        $now = time();
        if ((string) getSystemSetting('health_run_all', '0') === '1') {
            $all = true;
            $db->exec("UPDATE sys_settings SET setting_value = '0' WHERE setting_key = 'health_run_all'");
        }
        $checks = self::checks();
        $repeat = (int) getSystemSetting('health_repeat_hours', '6') ?: 6;
        $quiet = self::quietHoursNow($now);
        $alarms = [];
        $recovered = [];
        $results = [];
        $save = $db->prepare('INSERT INTO health_checks (check_key, status, message, checked_at, changed_at, bad_streak, alerted_status, alerted_at)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), message = VALUES(message), checked_at = NOW(), changed_at = VALUES(changed_at),
                bad_streak = VALUES(bad_streak), alerted_status = VALUES(alerted_status), alerted_at = VALUES(alerted_at)');
        $event = $db->prepare('INSERT INTO health_events (check_key, status, message) VALUES (?, ?, ?)');

        foreach (self::due($checks, $now, $all) as $key) {
            $prev = $checks[$key];
            [$status, $message] = HealthChecks::run($key);
            $message = mb_substr($message, 0, 500);
            $results[$key] = [$status, $message];

            // Without e-mails (aipbx-selftest) nothing counts as reported.
            $decision = $mail ? self::decide($prev, $status, $prev['interval'], $now, $repeat, $quiet) : null;
            $bad = in_array($status, ['warn', 'fail'], true);
            $streak = $bad ? (in_array($prev['status'], ['warn', 'fail'], true) ? $prev['bad_streak'] + 1 : 1) : 0;
            $alertedStatus = $prev['alerted_status'];
            $alertedAt = $prev['alerted_at'];
            if ($decision === 'alarm') {
                $alarms[$key] = [$status, $message];
                $alertedStatus = $status;
                $alertedAt = date('Y-m-d H:i:s', $now);
            } elseif ($decision === 'recovered') {
                $recovered[$key] = [$status, $message];
                $alertedStatus = '';
                // Kept, not nulled: when this check last mailed anything is what
                // stops a flapping problem from mailing again inside the window.
                $alertedAt = date('Y-m-d H:i:s', $now);
            }
            $changed = $status !== $prev['status'] ? date('Y-m-d H:i:s', $now) : $prev['changed_at'];
            $save->execute([$key, $status, $message, $changed, $streak, $alertedStatus, $alertedAt]);
            if ($status !== $prev['status'] && $prev['status'] !== 'unknown') {
                $event->execute([$key, $status, $message]);
            }
        }
        $db->exec('DELETE FROM health_events WHERE created_at < NOW() - INTERVAL 30 DAY');

        if ($mail) {
            if ($alarms) {
                self::sendList('health_alarm', $alarms);
            }
            if ($recovered) {
                self::sendList('health_recovered', $recovered);
            }
            self::maybeSendSummary($now);
        }
        return $results;
    }

    // ------------------------------------------------------------ e-mails

    /** One e-mail for all problems (or recoveries) of a run, to every recipient. */
    private static function sendList(string $template, array $items): void
    {
        foreach (self::recipients() as $r) {
            $blocks = MailTemplateService::inLanguage($r['lang'], function () use ($items) {
                $rows = [];
                foreach ($items as $key => [$status, $message]) {
                    $rows[t('health.check_' . $key) . ' — ' . t('health.status_' . $status)] = $message;
                }
                return ['problems' => MailTemplateService::detailsBlock('', $rows)];
            });
            MailTemplateService::send($template, $r['email'], $r['lang'], self::commonVars() + [
                'count' => (string) count($items),
                'checks' => implode(', ', MailTemplateService::inLanguage($r['lang'], fn() => array_map(fn($k) => t('health.check_' . $k), array_keys($items)))),
            ], $blocks);
        }
    }

    private static function maybeSendSummary(int $now): void
    {
        if (getSystemSetting('health_summary_enabled', '1') !== '1') {
            return;
        }
        $today = date('Y-m-d', $now);
        if (getSystemSetting('health_last_summary', '') === $today || date('H:i', $now) < (string) getSystemSetting('health_summary_time', '08:00')) {
            return;
        }
        getDB()->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('health_last_summary', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$today]);
        self::sendSummary($now);
    }

    /** The daily summary (also "send a test summary" on the page). Returns how many were sent. */
    public static function sendSummary(?int $now = null, ?array $only = null): int
    {
        $now ??= time();
        $sent = 0;
        foreach ($only ?? self::recipients() as $r) {
            $s = MailTemplateService::inLanguage($r['lang'], fn() => self::summary($now));
            $res = MailTemplateService::send('health_summary', $r['email'], $r['lang'], $s['vars'], $s['blocks']);
            $sent += !empty($res['success']) ? 1 : 0;
        }
        return $sent;
    }

    /** Page button: the summary right now, to the recipients (or the admin's own address). */
    public static function sendTestSummary(string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () {
            $to = self::recipients();
            if ($to === []) {
                throw new \Exception(t('health.err_no_recipients'));
            }
            $n = self::sendSummary(null, $to);
            if ($n === 0) {
                throw new \Exception(t('health.err_summary_failed'));
            }
            return sprintf(t('health.summary_sent'), $n);
        });
    }

    /**
     * Content of the daily summary: yesterday's calls and faxes, the state of
     * every check and yesterday's alarms.
     *
     * @return array{vars: array<string, string>, blocks: array<string, array{html: string, text: string}>, problems: int}
     */
    public static function summary(int $now): array
    {
        $day = date('Y-m-d', strtotime('-1 day', $now));
        $from = $day . ' 00:00:00';
        $to = $day . ' 23:59:59';
        $calls = self::callStats($from, $to);

        $callRows = [
            t('health.sum_calls_total') => (string) $calls['total'],
            t('health.sum_calls_in') => (string) $calls['inbound'],
            t('health.sum_calls_out') => (string) $calls['outbound'],
            t('health.sum_calls_internal') => (string) $calls['internal'],
            t('health.sum_answered') => (string) $calls['answered'],
            t('health.sum_missed') => (string) $calls['missed'],
            t('health.sum_busy') => (string) $calls['busy'],
            t('health.sum_avg_talk') => gmdate($calls['avg_talk'] >= 3600 ? 'H:i:s' : 'i:s', $calls['avg_talk']),
            t('health.sum_total_talk') => HealthChecks::duration($calls['total_duration']),
        ];
        $fax = self::faxStats($from, $to);
        if ($fax['received'] + $fax['sent'] + $fax['failed'] > 0) {
            $callRows[t('health.sum_fax')] = sprintf(t('health.sum_fax_value'), $fax['received'], $fax['sent'], $fax['failed']);
        }

        $checkRows = [];
        $problems = 0;
        foreach (self::checks() as $key => $c) {
            if ($c['interval'] <= 0 || $c['status'] === 'off') {
                continue;
            }
            if (in_array($c['status'], ['warn', 'fail'], true)) {
                $problems++;
            }
            $checkRows[self::mark($c['status']) . ' ' . t('health.check_' . $key)] = $c['message'];
        }

        $events = getDB()->prepare("SELECT check_key, status FROM health_events WHERE created_at BETWEEN ? AND ? AND status IN ('warn', 'fail') ORDER BY created_at");
        $events->execute([$from, $to]);
        $alarmKeys = array_unique(array_column($events->fetchAll(PDO::FETCH_ASSOC), 'check_key'));

        return [
            'vars' => self::commonVars() + [
                'day' => $day,
                'state' => $problems === 0 ? t('health.sum_state_ok') : sprintf(t('health.sum_state_problems'), $problems),
                'alarms' => $alarmKeys ? implode(', ', array_map(fn($k) => t('health.check_' . $k), $alarmKeys)) : t('health.sum_no_alarms'),
            ],
            'blocks' => [
                'calls' => MailTemplateService::detailsBlock(sprintf(t('health.sum_calls_title'), $day), $callRows),
                'checks' => MailTemplateService::detailsBlock(t('health.sum_checks_title'), $checkRows),
            ],
            'problems' => $problems,
        ];
    }

    /** Calls of a period, grouped as on the call report (one row per call, not per leg). */
    public static function callStats(string $from, string $to): array
    {
        $r = CdrReportRepository::summaryGrouped(true, '', $from, $to, '', '', '');
        foreach (['total', 'inbound', 'outbound', 'internal', 'answered', 'missed', 'busy', 'avg_talk', 'total_duration'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        return $r;
    }

    private static function faxStats(string $from, string $to): array
    {
        $db = getDB();
        $q = fn(string $sql) => (function () use ($db, $sql, $from, $to) {
            $s = $db->prepare($sql);
            $s->execute([$from, $to]);
            return (int) $s->fetchColumn();
        })();
        return [
            'received' => $q("SELECT COUNT(*) FROM fax_received WHERE status = 'SUCCESS' AND received_at BETWEEN ? AND ?"),
            'sent' => $q("SELECT COUNT(*) FROM fax_sent WHERE status <> 'FAILED' AND status <> 'PENDING' AND created_at BETWEEN ? AND ?"),
            'failed' => $q("SELECT COUNT(*) FROM fax_sent WHERE status = 'FAILED' AND created_at BETWEEN ? AND ?"),
        ];
    }

    private static function commonVars(): array
    {
        return [
            'host' => gethostname() ?: 'pbx',
            'date' => date('d.m.Y H:i'),
            'portal_link' => rtrim(ForgotPasswordService::portalUrl(), '/') . '/health',
        ];
    }

    /** Status mark used in e-mails and on the page. */
    public static function mark(string $status): string
    {
        return match ($status) {
            'ok' => '✅',
            'warn' => '⚠️',
            'fail' => '❌',
            'info' => 'ℹ️',
            default => '⚪',
        };
    }
}
