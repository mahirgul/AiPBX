<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/WebhookService.php';

/**
 * Holiday calendars (Time Conditions -> Holidays). A calendar is a list of closed days: typed in
 * ("2026-01-01 New Year", one a line), imported from an .ics file, or read from an .ics address
 * and refreshed once a day (task_dispatcher.php). Time groups pick calendars; SyncTimeConditions
 * closes on their dates as on the group's own.
 */
final class HolidayCalendarService
{
    public const MAX_DATES = 1000;
    private const MAX_ICS_BYTES = 2 * 1024 * 1024;
    private const REFRESH_SECONDS = 86400;
    /** A yearly event (RRULE:FREQ=YEARLY) is written out for this year and the next ones. */
    private const YEARS_AHEAD = 2;

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        try {
            $rows = getDB()->query('SELECT * FROM pbx_holiday_calendars ORDER BY name ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return []; // before the migration
        }
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $dates = self::decode($r['dates_json'] ?? null);
            $r['dates'] = $dates;
            $r['dates_text'] = self::formatText($dates);
            $r['next'] = '';
            foreach ($dates as $d => $name) {
                if ($d >= $today) {
                    $r['next'] = trim($d . ' ' . $name);
                    break;
                }
            }
        }
        return $rows;
    }

    /** @return array<string, string> date => name, sorted */
    public static function decode(?string $json): array
    {
        $out = [];
        foreach ((array) json_decode((string) $json, true) as $d => $name) {
            if (is_string($d) && self::validDate($d)) {
                $out[$d] = (string) $name;
            }
        }
        ksort($out);
        return $out;
    }

    public static function validDate(string $d): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * "2026-01-01 New Year" (or just the date), one a line; a range "2026-12-24..2026-12-26 Christmas".
     *
     * @return array<string, string>
     */
    public static function parseText(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*\.\.\s*(\d{4}-\d{2}-\d{2}))?\s*(.*)$/u', $line, $m)
                || !self::validDate($m[1]) || ($m[2] !== '' && !self::validDate($m[2]))) {
                throw new \InvalidArgumentException(sprintf(t('holidays.err_line'), $i + 1, mb_substr($line, 0, 40)));
            }
            $name = mb_substr(trim($m[3]), 0, 80);
            foreach (self::days($m[1], $m[2] !== '' ? $m[2] : $m[1]) as $d) {
                $out[$d] = $name;
            }
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, string> $dates */
    public static function formatText(array $dates): string
    {
        $lines = [];
        foreach ($dates as $d => $name) {
            $lines[] = trim($d . ' ' . $name);
        }
        return implode("\n", $lines);
    }

    /**
     * All-day events of an iCalendar file (a public holiday feed): DTSTART/DTEND dates (DTEND is
     * the day after), and yearly repeats written out up to YEARS_AHEAD.
     *
     * @return array<string, string>
     */
    public static function parseIcs(string $ics, ?int $thisYear = null): array
    {
        $thisYear ??= (int) date('Y');
        // Unfold continuation lines (RFC 5545 3.1).
        $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)) ?? '';
        if (!str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw new \InvalidArgumentException(t('holidays.err_not_ics'));
        }
        $out = [];
        preg_match_all('/BEGIN:VEVENT\n(.*?)END:VEVENT/s', $ics, $events);
        foreach ($events[1] as $ev) {
            $prop = [];
            $excluded = [];
            foreach (explode("\n", $ev) as $line) {
                if (!preg_match('/^([A-Z-]+)(;[^:]*)?:(.*)$/i', trim($line), $m)) {
                    continue;
                }
                $key = strtoupper($m[1]);
                if ($key === 'EXDATE') {
                    foreach (explode(',', $m[3]) as $value) {
                        $date = self::icsDate($value);
                        if ($date !== null) {
                            $excluded[$date] = true;
                        }
                    }
                } else {
                    $prop[$key] ??= $m[3];
                }
            }
            $start = self::icsDate($prop['DTSTART'] ?? '');
            if ($start === null) {
                continue;
            }
            $end = self::icsDate($prop['DTEND'] ?? '');
            $last = $end !== null && $end > $start ? date('Y-m-d', strtotime($end . ' -1 day')) : $start;
            $name = mb_substr(str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', ' ', ' ', '\\'], trim($prop['SUMMARY'] ?? '')), 0, 80);
            $length = (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($last))->days;
            foreach (self::icsOccurrences($start, $prop['RRULE'] ?? '', $thisYear) as $s) {
                if (isset($excluded[$s])) {
                    continue;
                }
                $e = (new \DateTimeImmutable($s))->modify("+{$length} days")->format('Y-m-d');
                foreach (self::days($s, $e) as $d) {
                    $out[$d] = $name;
                }
            }
        }
        ksort($out);
        return $out;
    }

    private static function icsDate(string $v): ?string
    {
        // 20260101, or 20260101T000000(Z): a timed event counts for the day it starts.
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($v), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** @return iterable<string> The DTSTART or occurrences of a yearly holiday rule. */
    private static function icsOccurrences(string $start, string $rrule, int $thisYear): iterable
    {
        preg_match_all('/(?:^|;)([A-Z]+)=([^;]+)/', strtoupper(trim($rrule)), $parts);
        $rule = array_combine($parts[1], $parts[2]);
        if (($rule['FREQ'] ?? '') !== 'YEARLY' || isset($rule['BYWEEKNO']) || isset($rule['BYYEARDAY'])) {
            yield $start;
            return;
        }

        $startYear = (int) substr($start, 0, 4);
        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $remaining = isset($rule['COUNT']) ? max(0, (int) $rule['COUNT']) : null;
        $until = self::icsDate($rule['UNTIL'] ?? '');
        $lastYear = min($thisYear + self::YEARS_AHEAD, $until === null ? 9999 : (int) substr($until, 0, 4));
        // With COUNT, older occurrences still consume their places. Otherwise skip directly to the displayed years.
        $offset = $remaining === null ? max(0, $thisYear - $startYear) : 0;
        $offset += ($interval - $offset % $interval) % $interval;
        for ($year = $startYear + $offset; $year <= $lastYear; $year += $interval) {
            foreach (self::yearlyDates($year, $start, $rule) as $date) {
                if ($date < $start) {
                    continue;
                }
                if ($remaining === 0 || ($until !== null && $date > $until)) {
                    return;
                }
                if ($remaining !== null) {
                    --$remaining;
                }
                if ($year >= $thisYear) {
                    yield $date;
                }
            }
        }
    }

    /** @param array<string, string> $rule @return list<string> */
    private static function yearlyDates(int $year, string $start, array $rule): array
    {
        $weekdays = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
        $dayRules = explode(',', $rule['BYDAY'] ?? '');
        $monthDays = array_map('intval', explode(',', $rule['BYMONTHDAY'] ?? ''));
        $hasDayRule = isset($rule['BYDAY']) || isset($rule['BYMONTHDAY']);
        $months = isset($rule['BYMONTH'])
            ? array_map('intval', explode(',', $rule['BYMONTH']))
            : ($hasDayRule ? range(1, 12) : [(int) substr($start, 5, 2)]);
        $months = array_unique($months);
        sort($months);

        $dates = [];
        foreach ($months as $month) {
            if (!checkdate($month, 1, $year)) {
                continue;
            }
            $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $daysInMonth = (int) $first->format('t');
            $days = $hasDayRule ? range(1, $daysInMonth) : [(int) substr($start, 8, 2)];
            foreach ($days as $day) {
                if ($day > $daysInMonth || (isset($rule['BYMONTHDAY'])
                    && !in_array($day, $monthDays, true) && !in_array($day - $daysInMonth - 1, $monthDays, true))) {
                    continue;
                }
                $date = $first->setDate($year, $month, $day);
                if (isset($rule['BYDAY'])) {
                    $weekday = $weekdays[(int) $date->format('N') - 1];
                    // With BYMONTH the ordinal is within that month. Otherwise it is within the year.
                    $position = isset($rule['BYMONTH']) ? $day : (int) $date->format('z') + 1;
                    $length = isset($rule['BYMONTH']) ? $daysInMonth : 365 + (int) $date->format('L');
                    $fromStart = intdiv($position - 1, 7) + 1;
                    $fromEnd = '-' . (intdiv($length - $position, 7) + 1);
                    if (!array_intersect($dayRules, [$weekday, $fromStart . $weekday, '+' . $fromStart . $weekday, $fromEnd . $weekday])) {
                        continue;
                    }
                }
                $dates[] = $date->format('Y-m-d');
            }
        }
        if (!isset($rule['BYSETPOS'])) {
            return $dates;
        }

        $selected = [];
        foreach (array_map('intval', explode(',', $rule['BYSETPOS'])) as $position) {
            $index = $position > 0 ? $position - 1 : count($dates) + $position;
            if ($position !== 0 && isset($dates[$index])) {
                $selected[$index] = $dates[$index];
            }
        }
        ksort($selected);
        return array_values($selected);
    }

    /** @return list<string> the days from $a to $b, at most 31 */
    private static function days(string $a, string $b): array
    {
        $out = [];
        for ($t = strtotime($a), $end = strtotime($b), $n = 0; $t <= $end && $n < 31; $t = strtotime('+1 day', $t), $n++) {
            $out[] = date('Y-m-d', $t);
        }
        return $out;
    }

    /** The .ics behind an address: public addresses only, with the checked address pinned. */
    public static function fetchIcs(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^(https?|webcal)://#i', $url)) {
            throw new \InvalidArgumentException(t('holidays.err_url'));
        }
        $url = (string) preg_replace('#^webcal://#i', 'https://', $url);
        $resolve = WebhookService::validatedResolve($url);
        $ch = curl_init();
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'AiPBX holiday calendar',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > self::MAX_ICS_BYTES ? 0 : strlen($chunk);
            },
        ]);
        if ($resolve) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if (strlen($body) > self::MAX_ICS_BYTES) {
            throw new \RuntimeException(t('holidays.err_too_big'));
        }
        if ($ok === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf(t('holidays.err_fetch'), $code ?: $err));
        }
        return $body;
    }

    /** @param array<string, mixed> $post @param array<string, mixed>|null $upload one $_FILES entry */
    public static function save(array $post, ?array $upload): array
    {
        return PBXHelper::handleAction($post['csrf_token'] ?? '', function () use ($post, $upload) {
            $id = (int) ($post['hc_id'] ?? 0);
            $name = mb_substr(trim((string) ($post['name'] ?? '')), 0, 100);
            if ($name === '') {
                throw new \InvalidArgumentException(t('holidays.err_name'));
            }
            $url = trim((string) ($post['ics_url'] ?? ''));
            $fetchedAt = null;
            if ($url !== '') {
                // The address decides the dates; they are read again every day.
                $dates = self::parseIcs(self::fetchIcs($url));
                $fetchedAt = date('Y-m-d H:i:s');
            } else {
                $dates = self::parseText((string) ($post['dates'] ?? ''));
                if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $upload['tmp_name'])) {
                    if ((int) $upload['size'] > self::MAX_ICS_BYTES) {
                        throw new \RuntimeException(t('holidays.err_too_big'));
                    }
                    $dates = self::parseIcs((string) file_get_contents((string) $upload['tmp_name'])) + $dates;
                    ksort($dates);
                }
            }
            if (count($dates) > self::MAX_DATES) {
                throw new \InvalidArgumentException(sprintf(t('holidays.err_too_many'), self::MAX_DATES));
            }
            $json = json_encode($dates, JSON_UNESCAPED_UNICODE);
            $db = getDB();
            if ($id > 0) {
                $db->prepare('UPDATE pbx_holiday_calendars SET name = ?, dates_json = ?, ics_url = ?, fetched_at = ?, fetch_error = NULL WHERE id = ?')
                    ->execute([$name, $json, $url !== '' ? $url : null, $fetchedAt, $id]);
                $action = 'update';
            } else {
                $db->prepare('INSERT INTO pbx_holiday_calendars (name, dates_json, ics_url, fetched_at) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $json, $url !== '' ? $url : null, $fetchedAt]);
                $id = (int) $db->lastInsertId();
                $action = 'create';
            }
            markPendingSync('time_conditions', 'holiday_calendar', $id, "Holidays: {$name}", $action, $_SESSION['user_id'] ?? null);
            return sprintf(t('holidays.msg_saved'), $name, count($dates)) . ' ' . t('common.apply_hint');
        });
    }

    public static function delete(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $db = getDB();
            $st = $db->prepare('SELECT name FROM pbx_holiday_calendars WHERE id = ?');
            $st->execute([$id]);
            $name = $st->fetchColumn();
            if ($name === false) {
                throw new \InvalidArgumentException(t('holidays.err_not_found'));
            }
            $db->prepare('DELETE FROM pbx_holiday_calendars WHERE id = ?')->execute([$id]);
            // Time groups that picked it forget it.
            $groups = $db->query("SELECT id, holiday_calendar_ids FROM pbx_time_groups WHERE holiday_calendar_ids IS NOT NULL AND holiday_calendar_ids <> ''")->fetchAll(PDO::FETCH_ASSOC);
            $upd = $db->prepare('UPDATE pbx_time_groups SET holiday_calendar_ids = ? WHERE id = ?');
            foreach ($groups as $g) {
                $ids = array_diff(self::idList((string) $g['holiday_calendar_ids']), [$id]);
                $upd->execute([$ids ? implode(',', $ids) : null, $g['id']]);
            }
            markPendingSync('time_conditions', 'holiday_calendar', $id, "Holidays: {$name}", 'delete', $_SESSION['user_id'] ?? null);
            return sprintf(t('holidays.msg_deleted'), $name) . ' ' . t('common.apply_hint');
        });
    }

    /** @return list<int> */
    public static function idList(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)), static fn(int $i): bool => $i > 0)));
    }

    /** @param mixed $ids the posted checkboxes */
    public static function idCsv($ids): ?string
    {
        $list = self::idList(implode(',', array_map('strval', (array) $ids)));
        return $list ? implode(',', $list) : null;
    }

    /**
     * The dates of the calendars a time group picked, from $from on (older ones cannot match).
     *
     * @return list<string>
     */
    public static function datesFor(string $idsCsv, string $from): array
    {
        $ids = self::idList($idsCsv);
        if (!$ids) {
            return [];
        }
        try {
            $st = getDB()->prepare('SELECT dates_json FROM pbx_holiday_calendars WHERE id IN (' . implode(',', $ids) . ')');
            $st->execute();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach (array_keys(self::decode((string) $json)) as $d) {
                if ($d >= $from) {
                    $out[] = $d;
                }
            }
        }
        return $out;
    }

    /**
     * Once a minute (task_dispatcher.php): calendars with an address are read again once a day.
     * True when new dates should reach the dialplan now (the caller runs the time-condition sync);
     * when an administrator has time-condition changes waiting for Apply, they wait with them.
     */
    public static function refreshDue(): bool
    {
        try {
            $due = getDB()->query("SELECT id, name, ics_url, dates_json FROM pbx_holiday_calendars
                WHERE ics_url IS NOT NULL AND ics_url <> ''
                  AND (fetched_at IS NULL OR fetched_at < NOW() - INTERVAL " . self::REFRESH_SECONDS . " SECOND)")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
        $changed = false;
        foreach ($due as $c) {
            try {
                $dates = self::parseIcs(self::fetchIcs((string) $c['ics_url']));
                if ($dates === [] || count($dates) > self::MAX_DATES) {
                    throw new \RuntimeException(t('holidays.err_empty_feed'));
                }
                $json = json_encode($dates, JSON_UNESCAPED_UNICODE);
                getDB()->prepare('UPDATE pbx_holiday_calendars SET dates_json = ?, fetched_at = NOW(), fetch_error = NULL WHERE id = ?')->execute([$json, $c['id']]);
                $changed = $changed || $json !== (string) $c['dates_json'];
            } catch (\Throwable $e) {
                // Keep the dates it had; try again tomorrow.
                getDB()->prepare('UPDATE pbx_holiday_calendars SET fetched_at = NOW(), fetch_error = ? WHERE id = ?')
                    ->execute([mb_substr($e->getMessage(), 0, 250), $c['id']]);
            }
        }
        if (!$changed) {
            return false;
        }
        $waiting = getDB()->query("SELECT COUNT(*) FROM sys_pending_sync WHERE domain = 'time_conditions'")->fetchColumn();
        return (int) $waiting === 0;
    }
}
