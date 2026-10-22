<?php
require_once __DIR__ . '/../repositories/NoticeRepository.php';
require_once __DIR__ . '/MailTemplateService.php';
require_once __DIR__ . '/ForgotPasswordService.php';

/**
 * A notice: something the PBX found that a person should know about.
 *
 * The point of this class is the two rules that keep notices readable:
 *
 *  - **One problem, one notice.** A repeating problem (a trunk that stays
 *    down, a mailbox that stays full) is deduped per `dedupe_key` inside a
 *    window — six hours by default — so it informs once and then keeps its
 *    row. A *different* problem always gets its own row.
 *  - **Do not wake anybody.** Mail is off by default, only warn/critical are
 *    e-mailed, and the quiet window silences even those. In-app the notice is
 *    always written; the mail is the optional part.
 *
 * Callers do not have to guard: `notify()` never throws on a mail failure and
 * always writes the row first, so a notice that could not be e-mailed is still
 * visible in the portal.
 */
class NoticeService
{
    const SEVERITIES = ['info', 'warn', 'critical'];

    /** Six hours: a repeated problem informs twice a day, not every five minutes. */
    const DEFAULT_WINDOW_HOURS = 6;

    /** One mail per recipient is a loop; this stops a mis-set list from being a mail storm. */
    const MAX_RECIPIENTS = 5;

    /**
     * @return array{email: bool, to: string, quiet_hours: string, window_hours: int}
     */
    public static function settings(): array
    {
        return [
            'email' => getSystemSetting('notice_email', '0') === '1',
            'to' => trim((string) getSystemSetting('notice_email_to', '')),
            'quiet_hours' => trim((string) getSystemSetting('notice_quiet_hours', '')),
            'window_hours' => self::windowHours(),
        ];
    }

    /** @param array<string, mixed> $in */
    public static function saveSettings(array $in): array
    {
        $hours = (int) ($in['notice_window_hours'] ?? self::DEFAULT_WINDOW_HOURS);
        if ($hours < 0 || $hours > 168) {
            return ['success' => false, 'error' => t('notices.err_window')];
        }
        $quiet = trim((string) ($in['notice_quiet_hours'] ?? ''));
        if ($quiet !== '' && !self::validWindow($quiet)) {
            return ['success' => false, 'error' => t('notices.err_quiet')];
        }
        $to = trim((string) ($in['notice_email_to'] ?? ''));
        foreach ($to !== '' ? explode(',', $to) : [] as $address) {
            if (filter_var(trim($address), FILTER_VALIDATE_EMAIL) === false) {
                return ['success' => false, 'error' => t('notices.err_to')];
            }
        }

        $db = getDB();
        $save = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $save->execute(['notice_email', !empty($in['notice_email']) ? '1' : '0']);
        $save->execute(['notice_email_to', $to]);
        $save->execute(['notice_quiet_hours', $quiet]);
        $save->execute(['notice_window_hours', (string) $hours]);

        return ['success' => true, 'message' => t('notices.settings_saved')];
    }

    /**
     * Writes a notice, unless the same problem was reported inside the window.
     *
     * @return array{id: int, emailed: bool}|array{deduped: true}
     */
    public static function notify(string $severity, string $title, string $body,
                                  string $dedupeKey = '', string $source = 'core'): array
    {
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'info';
        }
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('a notice needs a title');
        }
        $body = trim($body);
        $dedupeKey = $dedupeKey !== ''
            ? mb_substr($dedupeKey, 0, 64)
            : mb_substr(sha1($severity . '|' . $title), 0, 64);
        $settings = self::settings();

        $window = $settings['window_hours'] * 3600;
        if (NoticeRepository::findRecentByKey($dedupeKey, $window) !== null) {
            return ['deduped' => true];
        }

        $id = NoticeRepository::add([
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'source' => $source,
            'dedupe_key' => $dedupeKey,
        ]);
        writeAuditLog('notices', 'notice', $id, mb_substr($title, 0, 150), 'create', null);

        $emailed = false;
        if ($settings['email'] && in_array($severity, ['warn', 'critical'], true) && !self::quietHoursNow()) {
            $emailed = self::email($severity, $title, $body, $settings['to']);
            if ($emailed) {
                NoticeRepository::markEmailed($id);
            }
        }

        return ['id' => $id, 'emailed' => $emailed];
    }

    /** True inside the configured quiet window; a window may cross midnight. */
    public static function quietHoursNow(?int $ts = null): bool
    {
        return self::inQuietWindow(self::settings()['quiet_hours'], date('H:i', $ts ?? time()));
    }

    /**
     * "22:00-07:00" is a window that crosses midnight; "09:00-17:00" does not.
     * An empty, malformed or zero-length window is no window at all, which is
     * the safe answer: a typo must not silence alarms forever.
     */
    public static function inQuietWindow(string $window, string $time): bool
    {
        if (!self::validWindow($window)) {
            return false;
        }
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time)) {
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

    public static function validWindow(string $window): bool
    {
        return preg_match(
            '/^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]$/',
            $window
        ) === 1;
    }

    /** @return list<array<string, mixed>> newest first */
    public static function recent(int $limit = 20): array
    {
        return NoticeRepository::recent($limit);
    }

    public static function unreadCount(): int
    {
        return NoticeRepository::unreadCount();
    }

    public static function markRead(int $id): bool
    {
        return NoticeRepository::markRead($id) > 0;
    }

    public static function markAllRead(): int
    {
        return NoticeRepository::markAllRead();
    }

    /** A severity as the UI shows it: colour class and icon. */
    public static function look(string $severity): array
    {
        return match ($severity) {
            'critical' => ['danger', 'fa-circle-exclamation'],
            'warn' => ['warning', 'fa-triangle-exclamation'],
            default => ['info', 'fa-circle-info'],
        };
    }

    private static function windowHours(): int
    {
        $raw = getSystemSetting('notice_window_hours', (string) self::DEFAULT_WINDOW_HOURS);
        $hours = is_numeric($raw) ? (int) $raw : self::DEFAULT_WINDOW_HOURS;
        return max(0, min(168, $hours));
    }

    /**
     * Who gets the mail: the configured list, otherwise every admin with an
     * address. Silence is not a failure — an install that never configured
     * mail simply has no recipients.
     *
     * @return list<string>
     */
    private static function recipients(string $configured): array
    {
        $list = [];
        if ($configured !== '') {
            foreach (explode(',', $configured) as $address) {
                $address = trim($address);
                if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                    $list[] = $address;
                }
            }
        } else {
            $rows = getDB()->query("SELECT email FROM sys_users WHERE role = 'admin' AND email <> ''")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $address) {
                if (filter_var((string) $address, FILTER_VALIDATE_EMAIL) !== false) {
                    $list[] = (string) $address;
                }
            }
        }
        return array_slice(array_values(array_unique($list)), 0, self::MAX_RECIPIENTS);
    }

    /**
     * Never throws: a notice is written before this is called, so a broken
     * mail setup costs the notice its e-mail, not its existence.
     */
    private static function email(string $severity, string $title, string $body, string $configured): bool
    {
        try {
            $recipients = self::recipients($configured);
            if ($recipients === []) {
                return false;
            }
            $vars = [
                'title' => $title,
                'severity' => t('notices.severity_' . $severity),
                'body' => $body,
                'date' => date('d.m.Y H:i'),
                'portal_link' => rtrim(ForgotPasswordService::portalUrl(), '/') . '/',
            ];
            $sent = false;
            foreach ($recipients as $address) {
                $res = MailTemplateService::send(
                    'notice',
                    $address,
                    MailTemplateService::languageFor($address),
                    $vars
                );
                $sent = $sent || !empty($res['success']);
            }
            return $sent;
        } catch (Throwable $e) {
            error_log('notice mail failed: ' . $e->getMessage());
            return false;
        }
    }
}
