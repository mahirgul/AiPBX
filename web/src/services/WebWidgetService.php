<?php
/**
 * Website call widget and call-back form (roadmap item 1, #14).
 *
 * A widget is a virtual trunk: calls from it enter the PBX with the widget's
 * own number as the DID, and the server alone decides where they go (an
 * extension, ring group, queue, IVR or time condition, or an external number
 * through one outbound route when the administrator allows it).
 *
 * Security model:
 *  - The website is checked by its Origin header against the widget's
 *    allowed websites (the portal itself is always allowed, for "Try it").
 *  - Each widget has one WebRTC endpoint (widget-<id>) whose context only
 *    reaches that widget's routing ([widget-<id>]); copying its credentials
 *    lets nobody call anywhere else.
 *  - A call also needs a one-time token, handed out per click after the
 *    checks below and claimed by the dialplan (bin/widget_claim.php) within
 *    TOKEN_TTL seconds; without one the call is refused.
 *  - Limits: requests per IP per hour and calls per day here, concurrent
 *    calls (GROUP_COUNT) and call length (TIMEOUT(absolute)) in the dialplan.
 *  - Every request is logged in pbx_web_widget_sessions (the abuse log).
 *
 * The call-back form dials the visitor through the widget's outbound route
 * and, once they answer, sends them to the destination like a widget call.
 * The number must start with one of the allowed prefixes, and a daily limit
 * is mandatory.
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/audit_log.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';
require_once __DIR__ . '/ForgotPasswordService.php';
require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

class WebWidgetService
{
    /** Destinations a widget may have; 'external' needs an outbound route and a daily limit. */
    public const DEST_TYPES = ['extension', 'ring_group', 'queue', 'ivr', 'time_condition', 'external'];

    /** Languages widget.js has texts for. */
    public const LANGUAGES = ['en', 'tr', 'de', 'fr', 'es'];

    public const POSITIONS = ['right', 'left'];

    /** Seconds a call token stays valid after the click. */
    public const TOKEN_TTL = 300;

    /** Session (abuse log) rows are kept this long. */
    private const LOG_KEEP_DAYS = 90;

    /** Results that count as a call for the daily limit. */
    private const COUNTED = ['claimed', 'callback'];

    // ------------------------------------------------------------ widgets

    public static function listWidgets(): array
    {
        $rows = getDB()->query(
            "SELECT w.*,
                    (SELECT COUNT(*) FROM pbx_web_widget_sessions s
                      WHERE s.widget_id = w.id AND s.result IN ('claimed', 'callback') AND s.created_at >= CURDATE()) AS calls_today
             FROM pbx_web_widgets w ORDER BY w.name ASC, w.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    public static function getWidget(int $id): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pbx_web_widgets WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getByPublicId(string $publicId): ?array
    {
        if (!preg_match('/^w_[a-z0-9]{8,20}$/', $publicId)) {
            return null;
        }
        $stmt = getDB()->prepare('SELECT * FROM pbx_web_widgets WHERE public_id = ?');
        $stmt->execute([$publicId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function saveWidget(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = self::upsertWidget($data);
            return sprintf(t('web_widgets.saved'), (string) self::getWidget($id)['name']);
        });
    }

    /**
     * Validates and stores a widget (by id, or a new one when id is 0) and
     * returns its id. The PBX side follows on "Apply" (pending sync).
     */
    public static function upsertWidget(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $row = self::validate($data, $id);

        $db = getDB();
        if ($id > 0) {
            if (self::getWidget($id) === null) {
                throw new \Exception(t('web_widgets.err_not_found'));
            }
            $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($row)));
            $db->prepare("UPDATE pbx_web_widgets SET {$sets} WHERE id = ?")
                ->execute([...array_values($row), $id]);
            $action = 'update';
        } else {
            $row['public_id'] = self::newPublicId();
            $row['sip_secret'] = self::newSecret();
            $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($row)));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            $db->prepare("INSERT INTO pbx_web_widgets ({$cols}) VALUES ({$marks})")->execute(array_values($row));
            $id = (int) $db->lastInsertId();
            $action = 'create';
        }
        markPendingSync('widgets', 'web_widget', $id, $row['name'], $action, $_SESSION['user_id'] ?? null);
        return $id;
    }

    /**
     * The checked column values of a widget form.
     *
     * @return array<string, int|string|null>
     */
    public static function validate(array $d, int $id = 0): array
    {
        $name = trim(mb_substr((string) ($d['name'] ?? ''), 0, 100));
        if ($name === '') {
            throw new \Exception(t('web_widgets.err_name'));
        }
        $number = trim((string) ($d['number'] ?? ''));
        if (!preg_match('/^[0-9]{2,20}$/', $number)) {
            throw new \Exception(t('web_widgets.err_number'));
        }
        $dup = getDB()->prepare('SELECT id FROM pbx_web_widgets WHERE number = ? AND id != ?');
        $dup->execute([$number, $id]);
        if ($dup->fetchColumn()) {
            throw new \Exception(t('web_widgets.err_number_taken'));
        }

        $destType = (string) ($d['dest_type'] ?? '');
        if (!in_array($destType, self::DEST_TYPES, true)) {
            throw new \Exception(t('web_widgets.err_dest'));
        }
        $destId = trim((string) ($d['dest_id'] ?? ''));
        $external = '';
        if ($destType === 'external') {
            $destId = '';
            $external = self::cleanNumber((string) ($d['external_number'] ?? ''));
            if ($external === '') {
                throw new \Exception(t('web_widgets.err_external_number'));
            }
        } elseif (!self::destinationExists($destType, $destId)) {
            throw new \Exception(t('web_widgets.err_dest'));
        }

        $callEnabled = !empty($d['call_enabled']) ? 1 : 0;
        $callbackEnabled = !empty($d['callback_enabled']) ? 1 : 0;
        if (!$callEnabled && !$callbackEnabled) {
            throw new \Exception(t('web_widgets.err_no_feature'));
        }

        $routeId = (int) ($d['outbound_route_id'] ?? 0);
        $needsRoute = $destType === 'external' || $callbackEnabled;
        if ($needsRoute && !self::routeExists($routeId)) {
            throw new \Exception(t('web_widgets.err_route'));
        }

        $dailyLimit = self::intIn($d['daily_limit'] ?? 0, 0, 100000);
        if ($needsRoute && $dailyLimit < 1) {
            // Toll fraud: anything that can reach an outside line needs a cap.
            throw new \Exception(t('web_widgets.err_daily_limit'));
        }

        $prefixes = self::parsePrefixes((string) ($d['callback_prefixes'] ?? ''));
        if ($callbackEnabled && $prefixes === []) {
            throw new \Exception(t('web_widgets.err_prefixes'));
        }

        $bad = [];
        $origins = self::parseOrigins((string) ($d['allowed_origins'] ?? ''), $bad);
        if ($bad !== []) {
            throw new \Exception(sprintf(t('web_widgets.err_origins_bad'), implode(', ', $bad)));
        }
        if ($origins === []) {
            throw new \Exception(t('web_widgets.err_origins'));
        }

        $color = strtolower(trim((string) ($d['color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $color = '#2563eb';
        }
        $position = in_array($d['position'] ?? '', self::POSITIONS, true) ? (string) $d['position'] : 'right';
        $language = in_array($d['language'] ?? '', self::LANGUAGES, true) ? (string) $d['language'] : 'en';

        return [
            'name' => $name,
            'number' => $number,
            'is_active' => !empty($d['is_active']) ? 1 : 0,
            'dest_type' => $destType,
            'dest_id' => mb_substr($destId, 0, 64),
            'external_number' => $external,
            'outbound_route_id' => $needsRoute ? $routeId : null,
            'allowed_origins' => implode("\n", $origins),
            'call_enabled' => $callEnabled,
            'callback_enabled' => $callbackEnabled,
            'callback_prefixes' => implode(',', $prefixes),
            'callback_cid' => self::cleanNumber((string) ($d['callback_cid'] ?? '')),
            'max_concurrent' => self::intIn($d['max_concurrent'] ?? 2, 1, 100),
            'max_call_seconds' => self::intIn($d['max_call_seconds'] ?? 900, 60, 14400),
            'ip_hourly_limit' => self::intIn($d['ip_hourly_limit'] ?? 10, 1, 1000),
            'daily_limit' => $dailyLimit,
            'button_text' => trim(mb_substr((string) ($d['button_text'] ?? ''), 0, 60)),
            'color' => $color,
            'position' => $position,
            'language' => $language,
            'ask_name' => !empty($d['ask_name']) ? 1 : 0,
        ];
    }

    public static function deleteWidget(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $w = self::getWidget($id);
            if ($w === null) {
                throw new \Exception(t('web_widgets.err_not_found'));
            }
            getDB()->prepare('DELETE FROM pbx_web_widgets WHERE id = ?')->execute([$id]);
            markPendingSync('widgets', 'web_widget', $id, (string) $w['name'], 'delete', $_SESSION['user_id'] ?? null);
            return t('web_widgets.deleted');
        });
    }

    /** The switch on the page: off stops new calls at once (no Apply needed for that). */
    public static function toggleWidget(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $w = self::getWidget($id);
            if ($w === null) {
                throw new \Exception(t('web_widgets.err_not_found'));
            }
            $active = (int) $w['is_active'] === 1 ? 0 : 1;
            getDB()->prepare('UPDATE pbx_web_widgets SET is_active = ? WHERE id = ?')->execute([$active, $id]);
            markPendingSync('widgets', 'web_widget', $id, (string) $w['name'], 'update', $_SESSION['user_id'] ?? null);
            return t($active ? 'web_widgets.enabled' : 'web_widgets.disabled');
        });
    }

    /** The one line the website owner pastes into their pages. */
    public static function embedCode(array $w): string
    {
        return '<script src="' . ForgotPasswordService::portalUrl() . '/widget.js" data-widget="'
            . $w['public_id'] . '" async></script>';
    }

    public static function recentSessions(int $limit = 50): array
    {
        $stmt = getDB()->prepare(
            'SELECT s.*, w.name AS widget_name FROM pbx_web_widget_sessions s
             JOIN pbx_web_widgets w ON w.id = s.widget_id
             ORDER BY s.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Outbound routes an external destination or the call-back can use. */
    public static function outboundRoutes(): array
    {
        return getDB()->query(
            'SELECT id, route_name, match_pattern FROM pbx_outbound_routes WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------ website side

    /**
     * Whether a request's Origin may use the widget: its host must be one of
     * the allowed websites ("*.example.com" also allows sub-domains), or the
     * portal itself.
     */
    public static function originAllowed(array $w, string $origin): bool
    {
        $host = self::originHost($origin);
        if ($host === '') {
            return false;
        }
        $portal = strtolower((string) parse_url(ForgotPasswordService::portalUrl(), PHP_URL_HOST));
        if ($host === $portal) {
            return true;
        }
        foreach (self::parseOrigins((string) $w['allowed_origins']) as $allowed) {
            if (str_starts_with($allowed, '*.')) {
                $base = substr($allowed, 2);
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }
        return false;
    }

    /** What widget.js needs to draw itself (no secrets). */
    public static function publicConfig(array $w): array
    {
        return [
            'call' => (int) $w['call_enabled'] === 1,
            'callback' => (int) $w['callback_enabled'] === 1,
            'button_text' => (string) $w['button_text'],
            'color' => (string) $w['color'],
            'position' => (string) $w['position'],
            'language' => (string) $w['language'],
            'ask_name' => (int) $w['ask_name'] === 1,
            'max_seconds' => (int) $w['max_call_seconds'],
            'jssip' => ForgotPasswordService::portalUrl() . '/assets/js/jssip.min.js',
        ];
    }

    /**
     * A visitor clicked "call": checks the website and the limits, then hands
     * out what the browser needs for one call. The SIP password is the
     * widget's own (the context reaches nothing else); the one-time token in
     * the target is what lets the call through.
     *
     * @return array{status: int, body: array}
     */
    public static function requestCall(string $publicId, string $origin, string $ip, string $ua, string $name = ''): array
    {
        $w = self::getByPublicId($publicId);
        if ($w === null) {
            return self::refuse(404, 'not_found');
        }
        $name = self::cleanName($name);
        $refused = self::gate($w, 'call', $origin, $ip, $ua, $name, '');
        if ($refused !== null) {
            return $refused;
        }

        $token = bin2hex(random_bytes(16));
        self::log((int) $w['id'], 'call', 'issued', $origin, $ip, $ua, $name, '', $token);

        $host = (string) parse_url(ForgotPasswordService::portalUrl(), PHP_URL_HOST);
        $hostPort = (string) preg_replace('#^https://#', '', ForgotPasswordService::portalUrl());
        $user = self::endpointName($w);
        return ['status' => 200, 'body' => [
            'success' => true,
            'ws_url' => 'wss://' . $hostPort . (string) getSystemSetting('pjsip_ws_path', '/ws'),
            'uri' => 'sip:' . $user . '@' . $host,
            'user' => $user,
            'password' => (string) $w['sip_secret'],
            'target' => 'sip:w' . $token . '@' . $host,
            'display_name' => $name,
            'ice_servers' => self::iceServers($user),
            'max_seconds' => (int) $w['max_call_seconds'],
        ]];
    }

    /**
     * A visitor asked to be called back: the PBX dials their number through
     * the widget's outbound route and, once they answer, sends them to the
     * destination.
     *
     * @return array{status: int, body: array}
     */
    public static function requestCallback(string $publicId, string $origin, string $ip, string $ua, string $number, string $name = ''): array
    {
        $w = self::getByPublicId($publicId);
        if ($w === null) {
            return self::refuse(404, 'not_found');
        }
        $name = self::cleanName($name);
        $number = self::cleanNumber($number);
        $refused = self::gate($w, 'callback', $origin, $ip, $ua, $name, $number);
        if ($refused !== null) {
            return $refused;
        }
        if (!self::callbackNumberAllowed($w, $number)) {
            self::log((int) $w['id'], 'callback', 'bad_number', $origin, $ip, $ua, $name, $number);
            return self::refuse(422, 'bad_number');
        }

        self::placeCallback($w, $number, $name);
        self::log((int) $w['id'], 'callback', 'callback', $origin, $ip, $ua, $name, $number);
        return ['status' => 200, 'body' => ['success' => true]];
    }

    /** The number is 6-15 digits and starts with one of the widget's prefixes. */
    public static function callbackNumberAllowed(array $w, string $number): bool
    {
        if (!preg_match('/^[0-9]{6,15}$/', $number)) {
            return false;
        }
        foreach (self::parsePrefixes((string) $w['callback_prefixes']) as $prefix) {
            if (str_starts_with($number, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Called by the dialplan (bin/widget_claim.php) when a widget call
     * arrives: a token is valid once, for TOKEN_TTL seconds, for its own
     * widget, while the daily limit allows.
     *
     * @return string "OK|<name>" or "DENY|<reason>"
     */
    public static function claim(int $widgetId, string $token): string
    {
        $w = self::getWidget($widgetId);
        if ($w === null || (int) $w['is_active'] !== 1 || (int) $w['call_enabled'] !== 1) {
            return 'DENY|disabled';
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return 'DENY|bad_token';
        }
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT id, caller_name, created_at < (NOW() - INTERVAL " . self::TOKEN_TTL . " SECOND) AS expired
             FROM pbx_web_widget_sessions WHERE token = ? AND widget_id = ? AND result = 'issued'"
        );
        $stmt->execute([$token, $widgetId]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            return 'DENY|bad_token';
        }
        if ((int) $s['expired'] === 1) {
            $db->prepare("UPDATE pbx_web_widget_sessions SET result = 'expired', token = NULL WHERE id = ?")->execute([$s['id']]);
            return 'DENY|expired';
        }
        if ((int) $w['daily_limit'] > 0 && self::countedToday($widgetId) >= (int) $w['daily_limit']) {
            $db->prepare("UPDATE pbx_web_widget_sessions SET result = 'limit_daily', token = NULL WHERE id = ?")->execute([$s['id']]);
            return 'DENY|limit_daily';
        }
        // The token is spent: a second INVITE with it finds no 'issued' row.
        $upd = $db->prepare("UPDATE pbx_web_widget_sessions SET result = 'claimed', token = NULL, claimed_at = NOW() WHERE id = ? AND result = 'issued'");
        $upd->execute([$s['id']]);
        if ($upd->rowCount() !== 1) {
            return 'DENY|bad_token';
        }
        return 'OK|' . self::dialplanName((string) $s['caller_name']);
    }

    // ------------------------------------------------------------ PBX side

    /** PJSIP endpoint / auth user of a widget. */
    public static function endpointName(array $w): string
    {
        return 'widget-' . (int) $w['id'];
    }

    /** The caller ID number shown to outside parties (call-back, external destination). */
    public static function outsideCallerId(array $w): string
    {
        return $w['callback_cid'] !== '' ? (string) $w['callback_cid'] : (string) $w['number'];
    }

    /**
     * Drops a call file: Local/<number>@widget-<id>-out rings the visitor
     * through the outbound route; when they answer, [widget-<id>-callback]
     * routes them like a widget call (see SyncWidgets.php). Skipped in tests
     * (AIPBX_NO_ASTERISK).
     */
    private static function placeCallback(array $w, string $number, string $name): void
    {
        $id = (int) $w['id'];
        $cid = self::outsideCallerId($w);
        $content = "Channel: Local/{$number}@widget-{$id}-out/n\n"
            . "CallerID: \"" . self::dialplanName($name) . "\" <{$cid}>\n"
            . "MaxRetries: 0\n"
            . "WaitTime: 45\n"
            . "Context: widget-{$id}-callback\n"
            . "Extension: {$number}\n"
            . "Priority: 1\n"
            . "SetVar: WIDGET_CB_NAME=" . self::dialplanName($name) . "\n";

        if (getenv('AIPBX_NO_ASTERISK') === '1') {
            return;
        }
        // Written next to the spool and renamed in (atomic on one file
        // system), the same way FaxSendService::submitCallFile() does it.
        $file = 'widget_' . $id . '_' . bin2hex(random_bytes(6)) . '.call';
        $tmp = FAX_OUTGOING_SPOOL . '/.' . $file . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('Call file could not be written: ' . $tmp);
        }
        @chgrp($tmp, 'asterisk');
        chmod($tmp, 0660);
        if (!rename($tmp, ASTERISK_CALL_SPOOL . '/' . $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Call file could not be placed in the Asterisk spool: ' . ASTERISK_CALL_SPOOL);
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * Shared checks of a call and a call-back request; logs and returns the
     * refusal, or null when the request may go on.
     *
     * @return array{status: int, body: array}|null
     */
    private static function gate(array $w, string $kind, string $origin, string $ip, string $ua, string $name, string $number): ?array
    {
        $id = (int) $w['id'];
        if ((int) $w['is_active'] !== 1 || (int) $w[$kind === 'call' ? 'call_enabled' : 'callback_enabled'] !== 1) {
            self::log($id, $kind, 'disabled', $origin, $ip, $ua, $name, $number);
            return self::refuse(403, 'disabled');
        }
        if (!self::originAllowed($w, $origin)) {
            self::log($id, $kind, 'denied_origin', $origin, $ip, $ua, $name, $number);
            return self::refuse(403, 'denied_origin');
        }
        // Rate limit before the daily limit: a flood must not fill the log
        // with daily-limit rows nor use up the day.
        if (self::requestsThisHour($id, $ip) >= (int) $w['ip_hourly_limit']) {
            return self::refuse(429, 'rate_ip');
        }
        if ((int) $w['daily_limit'] > 0 && self::countedToday($id) >= (int) $w['daily_limit']) {
            self::log($id, $kind, 'limit_daily', $origin, $ip, $ua, $name, $number);
            return self::refuse(429, 'limit_daily');
        }
        return null;
    }

    /** @return array{status: int, body: array} */
    private static function refuse(int $status, string $reason): array
    {
        return ['status' => $status, 'body' => ['success' => false, 'error' => $reason]];
    }

    private static function requestsThisHour(int $widgetId, string $ip): int
    {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM pbx_web_widget_sessions WHERE widget_id = ? AND ip = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute([$widgetId, mb_substr($ip, 0, 45)]);
        return (int) $stmt->fetchColumn();
    }

    public static function countedToday(int $widgetId): int
    {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM pbx_web_widget_sessions
             WHERE widget_id = ? AND result IN ('" . implode("', '", self::COUNTED) . "') AND created_at >= CURDATE()"
        );
        $stmt->execute([$widgetId]);
        return (int) $stmt->fetchColumn();
    }

    private static function log(int $widgetId, string $kind, string $result, string $origin, string $ip, string $ua, string $name, string $number, ?string $token = null): void
    {
        $db = getDB();
        $db->prepare(
            'INSERT INTO pbx_web_widget_sessions (widget_id, kind, token, result, ip, origin, user_agent, caller_name, caller_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $widgetId, $kind, $token, $result, mb_substr($ip, 0, 45),
            mb_substr(self::cleanText($origin), 0, 255), mb_substr(self::cleanText($ua), 0, 255),
            $name, mb_substr($number, 0, 32),
        ]);
        if (random_int(1, 200) === 1) {
            $db->exec('DELETE FROM pbx_web_widget_sessions WHERE created_at < (NOW() - INTERVAL ' . self::LOG_KEEP_DAYS . ' DAY)');
        }
    }

    /** TURN credential for one call (the same TURN REST scheme as the web phone). */
    private static function iceServers(string $user): array
    {
        if (!defined('TURN_SECRET') || TURN_SECRET === '') {
            return [];
        }
        $username = (time() + 600) . ':' . $user;
        return [[
            'urls' => ['turns:' . TURN_HOST . ':' . TURNS_PORT . '?transport=tcp'],
            'username' => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, TURN_SECRET, true)),
        ]];
    }

    /**
     * One allowed website per line (or comma separated): a host name, with or
     * without scheme and path; "*.example.com" also allows sub-domains.
     *
     * @param string[]|null $bad entries that are not a host name
     * @return string[] lower-case host patterns
     */
    public static function parseOrigins(string $text, ?array &$bad = null): array
    {
        $bad = [];
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($text)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $entry);
            $host = (string) preg_replace('#[/?\#].*$#', '', $host);
            $host = (string) preg_replace('#:\d+$#', '', $host);
            if (preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host)
                && $host !== '*') {
                $out[] = $host;
            } else {
                $bad[] = $entry;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return string[] digit prefixes */
    public static function parsePrefixes(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $p) {
            if (preg_match('/^[0-9]{1,10}$/', $p)) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    private static function originHost(string $origin): string
    {
        if (!preg_match('#^https?://#i', $origin)) {
            return '';
        }
        return strtolower((string) parse_url($origin, PHP_URL_HOST));
    }

    private static function destinationExists(string $type, string $id): bool
    {
        if ($id === '') {
            return false;
        }
        foreach (DestinationRegistry::getOptionsFor($type) as $opt) {
            if ((string) $opt['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    private static function routeExists(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $stmt = getDB()->prepare('SELECT 1 FROM pbx_outbound_routes WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /** Digits only (a leading + is dropped; the outbound route decides the format). */
    public static function cleanNumber(string $number): string
    {
        return mb_substr((string) preg_replace('/[^0-9]/', '', $number), 0, 32);
    }

    /** A visitor's name as typed, without control characters, at most 60 characters. */
    public static function cleanName(string $name): string
    {
        return trim(mb_substr(self::cleanText($name), 0, 60));
    }

    /** A name safe inside dialplan values and call files: ASCII letters, digits, space . - ' */
    public static function dialplanName(string $name): string
    {
        $clean = (string) preg_replace("/[^A-Za-z0-9 .'-]/", '', toCleanAscii($name));
        return trim(mb_substr((string) preg_replace('/ {2,}/', ' ', $clean), 0, 40));
    }

    private static function cleanText(string $s): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s);
    }

    private static function intIn($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private static function newPublicId(): string
    {
        return 'w_' . bin2hex(random_bytes(6));
    }

    private static function newSecret(): string
    {
        return bin2hex(random_bytes(20));
    }
}
