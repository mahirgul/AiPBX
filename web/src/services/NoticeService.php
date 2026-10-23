<?php
require_once __DIR__ . '/HealthService.php';

/**
 * The bell in the top bar (administrators): what is wrong right now, and what
 * changed lately.
 *
 * It shows state, not a log. An item is there while its problem lasts and
 * goes away by itself when it is fixed, so nobody has to clear a list:
 *
 *  - every health check that is warn or failed (health_checks);
 *  - problems other parts raise with raise() and end with resolve()
 *    (sys_notices; e.g. a plugin that sees a trunk losing media);
 *  - the alarm path itself: no recipient chosen, or the health checks have
 *    stopped running. Then alarms reach nobody, and the bell is the only
 *    place that can say so.
 *
 * E-mail: health problems are mailed by HealthService as always, and the
 * problems raised here through its "alerts" check, so everything in the bell
 * is also e-mailed when recipients are set. The bell works without mail,
 * which is the point when mail is not set up or broken. "New" is per person:
 * changes after the user last opened the bell.
 */
class NoticeService
{
    public const SEVERITIES = ['warn', 'fail'];

    /** The cron runs the due checks every minute; half an hour without a result means it stopped. */
    private const STALE_MINUTES = 30;

    private const HISTORY_DAYS = 7;

    /** Health checks whose own page explains them better than /health. */
    private const HEALTH_LINKS = ['call_quality' => '/call-quality'];

    /**
     * Opens or updates a problem. Raising the same key again keeps its first
     * time; a resolved one opens again.
     */
    public static function raise(string $key, string $severity, string $title, string $message = '', string $link = '', string $source = 'core'): void
    {
        $key = mb_substr(trim($key), 0, 80);
        if ($key === '' || trim($title) === '') {
            throw new InvalidArgumentException('a notice needs a key and a title');
        }
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'warn';
        // Only portal paths: the link is printed as an href.
        $link = preg_match('#^/[A-Za-z0-9/_\-?=&.%]*$#', $link) ? mb_substr($link, 0, 200) : '';
        getDB()->prepare(
            'INSERT INTO sys_notices (notice_key, severity, title, message, link, source, raised_at, updated_at, resolved_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                raised_at = IF(resolved_at IS NULL, raised_at, NOW()),
                updated_at = IF(resolved_at IS NULL AND severity = VALUES(severity), updated_at, NOW()),
                severity = VALUES(severity), title = VALUES(title), message = VALUES(message),
                link = VALUES(link), source = VALUES(source), resolved_at = NULL'
        )->execute([$key, $severity, mb_substr(trim($title), 0, 150), mb_substr(trim($message), 0, 500), $link, mb_substr($source, 0, 40)]);
    }

    /** Ends a problem; nothing happens when it is not open. */
    public static function resolve(string $key): void
    {
        getDB()->prepare('UPDATE sys_notices SET resolved_at = NOW(), updated_at = NOW() WHERE notice_key = ? AND resolved_at IS NULL')
            ->execute([mb_substr(trim($key), 0, 80)]);
    }

    /**
     * Everything the bell shows for one user.
     *
     * @return array{open: list<array>, recent: list<array>, count: int, worst: string, new: int}
     */
    public static function forUser(int $userId): array
    {
        // PHP's and MariaDB's time zone can differ (health_checks.changed_at is
        // written by PHP, the rest by the database): database times are moved
        // to PHP's, so the list shows and compares one clock.
        $db = getDB();
        $shift = (int) date('Z') - (int) $db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        $php = fn(?string $t) => ($t === null || $t === '' || $shift === 0) ? $t : date('Y-m-d H:i:s', strtotime($t) + $shift);
        $db->exec('DELETE FROM sys_notices WHERE resolved_at < NOW() - INTERVAL 30 DAY');

        $open = [];
        $checks = HealthService::checks();
        foreach ($checks as $key => $c) {
            // "alerts" only carries the items below to the e-mails; they are listed one by one.
            if ($key !== 'alerts' && $c['interval'] > 0 && in_array($c['status'], self::SEVERITIES, true)) {
                $open[] = self::item('health:' . $key, $c['status'], t('health.check_' . $key), $c['message'], self::HEALTH_LINKS[$key] ?? '/health', $c['changed_at']);
            }
        }
        foreach (self::openNotices() as $n) {
            $open[] = self::item('notice:' . $n['notice_key'], $n['severity'], $n['title'], $n['message'], $n['link'], $php($n['raised_at']));
        }
        $age = $db->query('SELECT TIMESTAMPDIFF(SECOND, MAX(checked_at), NOW()) FROM health_checks')->fetchColumn();
        foreach (self::alarmPath($checks, $age === null || $age === false ? null : (int) $age) as $item) {
            $open[] = $item;
        }
        // Failed first, then the oldest problem.
        usort($open, fn($a, $b) => [$b['severity'] === 'fail', $a['since']] <=> [$a['severity'] === 'fail', $b['since']]);

        $since = (string) $db->query('SELECT NOW() - INTERVAL ' . self::HISTORY_DAYS . ' DAY')->fetchColumn();
        $recent = [];
        $ev = $db->prepare("SELECT check_key, status, message, created_at FROM health_events WHERE created_at >= ? AND check_key <> 'alerts' ORDER BY created_at DESC LIMIT 15");
        $ev->execute([$since]);
        foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $recent[] = self::item('health:' . $e['check_key'], $e['status'], t('health.check_' . $e['check_key']), $e['message'], self::HEALTH_LINKS[$e['check_key']] ?? '/health', $php($e['created_at']));
        }
        $nv = $db->prepare('SELECT * FROM sys_notices WHERE updated_at >= ? ORDER BY updated_at DESC LIMIT 15');
        $nv->execute([$since]);
        foreach ($nv->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $recent[] = self::item('notice:' . $n['notice_key'], $n['resolved_at'] !== null ? 'ok' : $n['severity'], $n['title'], $n['message'], $n['link'], $php($n['updated_at']));
        }
        usort($recent, fn($a, $b) => strcmp((string) $b['since'], (string) $a['since']));
        // The newest change of a problem that is still open is the open item itself.
        $openState = array_column($open, 'severity', 'key');
        $recent = array_values(array_filter($recent, function ($i) use (&$openState) {
            if (($openState[$i['key']] ?? null) === $i['severity']) {
                unset($openState[$i['key']]);
                return false;
            }
            unset($openState[$i['key']]);
            return true;
        }));
        $recent = array_slice($recent, 0, 15);

        $seen = (string) $php((string) getSystemSetting(self::seenKey($userId), ''));
        $new = 0;
        foreach (array_merge($open, $recent) as $i) {
            if ($i['since'] !== null && ($seen === '' || $i['since'] > $seen)) {
                $new++;
            }
        }
        $worst = '';
        foreach ($open as $i) {
            $worst = ($i['severity'] === 'fail' || $worst === 'fail') ? 'fail' : 'warn';
        }
        return ['open' => $open, 'recent' => $recent, 'count' => count($open), 'worst' => $worst, 'new' => $new];
    }

    /**
     * Open problems raised with raise(), of core and of enabled plugins
     * (a disabled plugin cannot resolve what it raised).
     *
     * @return list<array>
     */
    public static function openNotices(): array
    {
        $rows = getDB()->query('SELECT * FROM sys_notices WHERE resolved_at IS NULL ORDER BY raised_at')->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, fn($n) => self::sourceActive((string) $n['source'])));
    }

    /** The user opened the bell: what is there now is not new for them any more. */
    public static function markSeen(int $userId): void
    {
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::seenKey($userId)]);
    }

    /**
     * Problems of the alarm path: without recipients, or without health
     * checks running, a broken trunk or a full disk reaches nobody.
     *
     * @param array<string, array> $checks HealthService::checks()
     * @param ?int $lastRunAge seconds since the newest result (null: none yet, a fresh install)
     * @return list<array>
     */
    public static function alarmPath(array $checks, ?int $lastRunAge): array
    {
        $items = [];
        $enabled = array_filter($checks, fn($c) => $c['interval'] > 0);
        $last = max(array_map(fn($c) => (string) $c['checked_at'], $enabled) ?: ['']);
        if ($enabled && $lastRunAge !== null && $lastRunAge > self::STALE_MINUTES * 60) {
            $items[] = self::item('alarm:checks_stopped', 'fail', t('notices.checks_stopped'), sprintf(t('notices.checks_stopped_msg'), $last), '/health', $last);
        }
        if ($enabled && !HealthService::recipients()) {
            $items[] = self::item('alarm:no_recipients', 'warn', t('notices.no_recipients'), t('notices.no_recipients_msg'), '/health', null);
        }
        return $items;
    }

    /** 'core', or a plugin that is still enabled. */
    private static function sourceActive(string $source): bool
    {
        if ($source === 'core' || !class_exists('PluginManager') || PluginManager::getPlugin($source) === null) {
            return true;
        }
        return PluginManager::isEnabled($source);
    }

    private static function item(string $key, string $severity, string $title, string $message, string $link, ?string $since): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'message' => $message, 'link' => $link, 'since' => $since];
    }

    private static function seenKey(int $userId): string
    {
        return 'notices_seen_u' . $userId;
    }
}
