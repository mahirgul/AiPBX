<?php
/**
 * Call quality from RTCP (Reports → Call quality, docs/call-quality.md).
 *
 * Every minute (from aipbx-webhook-worker) the RTCP reports that arrived since
 * the last pass are read from pbx_signaling_events, added to one row per call
 * leg (pbx_call_quality_legs) and the leg is named from its SIP INVITE (the
 * trunk it came over, or the extension). Problems are a health check
 * (call_quality), so they reach the alert bell and, when set up, the alarm
 * e-mails like every other problem. Read-only towards the PBX: no route or
 * dialplan is ever changed. Needs signaling capture on (PBX settings →
 * Signaling).
 *
 * Idea and first version: @shido6 (community plugin proposal, AiPBX-plugins #1).
 */
final class CallQualityService
{
    public const SETTINGS = [
        'call_quality_poor_pct' => '5',
        'call_quality_alert_legs' => '3',
        'call_quality_keep_days' => '90',
    ];

    /** Minutes a leg counts for an alert. */
    public const ALERT_MINUTES = 15;

    /** A leg is "fair" from this loss on. */
    public const FAIR_PCT = 1.0;

    private const PASS_SECONDS = 60;
    private const BATCH = 20000;

    // ------------------------------------------------------------ settings

    /** @return array{poor_pct: float, alert_legs: int, keep_days: int} */
    public static function settings(): array
    {
        return [
            'poor_pct' => max(1.0, min(50.0, (float) getSystemSetting('call_quality_poor_pct', '5'))),
            'alert_legs' => max(1, min(50, (int) getSystemSetting('call_quality_alert_legs', '3'))),
            'keep_days' => max(7, min(3650, (int) getSystemSetting('call_quality_keep_days', '90'))),
        ];
    }

    public static function save(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $values = [
            'call_quality_poor_pct' => (string) max(1, min(50, (int) ($post['poor_pct'] ?? 5))),
            'call_quality_alert_legs' => (string) max(1, min(50, (int) ($post['alert_legs'] ?? 3))),
            'call_quality_keep_days' => (string) max(7, min(3650, (int) ($post['keep_days'] ?? 90))),
        ];
        $st = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $k => $v) {
            $st->execute([$k, $v]);
        }
        writeAuditLog('call_quality', 'settings', 0, 'Call quality', 'update', null);
        return ['success' => true, 'message' => t('call_quality.saved')];
    }

    // ------------------------------------------------------------ the pass

    /** Worker hook (every ~2 s): at most one pass a minute, only while capture is on. */
    public static function tick(): void
    {
        static $next = 0;
        if (time() < $next) {
            return;
        }
        $next = time() + self::PASS_SECONDS;
        if (getSystemSetting('signaling_capture', '0') !== '1') {
            return;
        }
        $result = self::pass();
        self::setState('call_quality_last_pass', json_encode(['at' => time()] + $result));
    }

    /** @return array{reports: int, legs: int, named: int, alerts: int} */
    public static function pass(): array
    {
        $db = getDB();
        if (!self::signalingTable($db)) {
            return ['reports' => 0, 'legs' => 0, 'named' => 0, 'alerts' => 0];
        }
        $s = self::settings();

        // Where the last pass stopped; the first one starts an hour back.
        $from = (int) getSystemSetting('call_quality_watermark', '0');
        if ($from === 0) {
            $from = (int) $db->query('SELECT COALESCE(MIN(id), 1) - 1 FROM pbx_signaling_events WHERE ts >= NOW() - INTERVAL 1 HOUR')->fetchColumn();
        }
        $st = $db->prepare("SELECT id, ts, call_id, src, rtcp_fraction_lost, rtcp_jitter FROM pbx_signaling_events
            WHERE id > ? AND proto = 'rtcp' AND call_id <> '' ORDER BY id LIMIT " . self::BATCH);
        $st->execute([$from]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $from = $rows
            ? (int) end($rows)['id']
            // Nothing new: move past the SIP rows too, so they are not read again.
            : max($from, (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM pbx_signaling_events')->fetchColumn());

        $legs = self::aggregate($rows, self::ownHosts(), $s['poor_pct']);
        self::store($db, $legs);
        self::setState('call_quality_watermark', (string) $from);

        $named = self::nameLegs($db);
        self::prune($db, $s['keep_days']);
        return ['reports' => count($rows), 'legs' => count($legs), 'named' => $named, 'alerts' => count(self::problems())];
    }

    /**
     * RTCP rows → per leg counters. A report sent BY this server describes
     * what it received ("in"); one sent to it, what the other side received.
     *
     * @param list<array> $rows
     * @param list<string> $own this server's addresses
     * @return array<string, array>
     */
    public static function aggregate(array $rows, array $own, float $poorPct): array
    {
        $legs = [];
        foreach ($rows as $r) {
            $id = (string) $r['call_id'];
            $l = $legs[$id] ?? ['first_ts' => $r['ts'], 'last_ts' => $r['ts'], 'reports_in' => 0, 'reports_out' => 0,
                'bad_in' => 0, 'bad_out' => 0, 'loss_in_max' => 0.0, 'loss_out_max' => 0.0,
                'loss_in_sum' => 0.0, 'loss_out_sum' => 0.0, 'jitter_in_max' => 0, 'jitter_out_max' => 0, 'peer' => ''];
            $l['first_ts'] = min($l['first_ts'], $r['ts']);
            $l['last_ts'] = max($l['last_ts'], $r['ts']);
            $srcHost = self::hostOf((string) $r['src']);
            $dir = in_array($srcHost, $own, true) ? 'in' : 'out';
            if ($dir === 'out' && (string) $l['peer'] === '') {
                $l['peer'] = $srcHost;
            }
            // A report without the counter says nothing about loss; it is not a zero.
            if ($r['rtcp_fraction_lost'] !== null) {
                $loss = round((int) $r['rtcp_fraction_lost'] * 100 / 256, 2);
                $l['reports_' . $dir]++;
                $l['loss_' . $dir . '_sum'] += $loss;
                $l['loss_' . $dir . '_max'] = max($l['loss_' . $dir . '_max'], $loss);
                $l['bad_' . $dir] += $loss >= $poorPct ? 1 : 0;
                $l['jitter_' . $dir . '_max'] = max($l['jitter_' . $dir . '_max'], (int) $r['rtcp_jitter']);
            }
            $legs[$id] = $l;
        }
        return $legs;
    }

    /** "poor": two bad reports in one direction; "fair": 1 % loss somewhere. */
    public static function level(array $l): string
    {
        if ($l['bad_in'] >= 2 || $l['bad_out'] >= 2) {
            return 'poor';
        }
        return max((float) $l['loss_in_max'], (float) $l['loss_out_max']) >= self::FAIR_PCT ? 'fair' : 'good';
    }

    private static function store(PDO $db, array $legs): void
    {
        if (!$legs) {
            return;
        }
        $up = $db->prepare('INSERT INTO pbx_call_quality_legs (call_id, first_ts, last_ts, peer, reports_in, reports_out, bad_in, bad_out,
                loss_in_max, loss_out_max, loss_in_sum, loss_out_sum, jitter_in_max, jitter_out_max)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE first_ts = LEAST(first_ts, VALUES(first_ts)), last_ts = GREATEST(last_ts, VALUES(last_ts)),
                peer = IF(peer = \'\', VALUES(peer), peer),
                reports_in = reports_in + VALUES(reports_in), reports_out = reports_out + VALUES(reports_out),
                bad_in = bad_in + VALUES(bad_in), bad_out = bad_out + VALUES(bad_out),
                loss_in_max = GREATEST(loss_in_max, VALUES(loss_in_max)), loss_out_max = GREATEST(loss_out_max, VALUES(loss_out_max)),
                loss_in_sum = loss_in_sum + VALUES(loss_in_sum), loss_out_sum = loss_out_sum + VALUES(loss_out_sum),
                jitter_in_max = GREATEST(jitter_in_max, VALUES(jitter_in_max)), jitter_out_max = GREATEST(jitter_out_max, VALUES(jitter_out_max))');
        $get = $db->prepare('SELECT bad_in, bad_out, loss_in_max, loss_out_max FROM pbx_call_quality_legs WHERE call_id = ?');
        $lvl = $db->prepare('UPDATE pbx_call_quality_legs SET level = ? WHERE call_id = ?');
        foreach ($legs as $id => $l) {
            $id = mb_substr((string) $id, 0, 128);
            $up->execute([$id, $l['first_ts'], $l['last_ts'], mb_substr($l['peer'], 0, 64),
                $l['reports_in'], $l['reports_out'], $l['bad_in'], $l['bad_out'], $l['loss_in_max'], $l['loss_out_max'],
                $l['loss_in_sum'], $l['loss_out_sum'], $l['jitter_in_max'], $l['jitter_out_max']]);
            $get->execute([$id]);
            $lvl->execute([self::level($get->fetch(PDO::FETCH_ASSOC)), $id]);
        }
    }

    // ------------------------------------------------------------ naming

    /**
     * Names new legs from their SIP INVITE: the trunk whose address (or match
     * list) the other side of the INVITE is, else the extension in the From or
     * To user part. Media may come from other addresses than the signaling, so
     * the INVITE is the reliable witness.
     */
    private static function nameLegs(PDO $db): int
    {
        $ids = $db->query("SELECT call_id FROM pbx_call_quality_legs WHERE leg_type = '' AND first_ts >= NOW() - INTERVAL 1 DAY LIMIT 300")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return 0;
        }
        $own = self::ownHosts();
        $trunks = self::trunkMatchers($db);
        $exts = array_flip($db->query("SELECT extension FROM sys_users WHERE extension IS NOT NULL AND extension <> ''")->fetchAll(PDO::FETCH_COLUMN));
        $inv = $db->prepare("SELECT src, dst, from_uri, to_uri FROM pbx_signaling_events WHERE call_id = ? AND proto = 'sip' AND method = 'INVITE' ORDER BY id LIMIT 1");
        $old = $db->prepare('SELECT first_ts < NOW() - INTERVAL 1 HOUR FROM pbx_call_quality_legs WHERE call_id = ?');
        $set = $db->prepare('UPDATE pbx_call_quality_legs SET leg_type = ?, leg_name = ? WHERE call_id = ?');
        $named = 0;
        foreach ($ids as $id) {
            $inv->execute([$id]);
            $sip = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$sip) {
                // Reports without signaling (capture started mid-call): give up after an hour.
                $old->execute([$id]);
                if ((int) $old->fetchColumn() === 1) {
                    $set->execute(['unknown', '', $id]);
                }
                continue;
            }
            [$type, $name] = self::classify($sip, $own, $trunks, $exts);
            $set->execute([$type, mb_substr($name, 0, 64), $id]);
            $named++;
        }
        return $named;
    }

    /**
     * @param array{src: string, dst: string, from_uri: string, to_uri: string} $sip
     * @param list<string> $own
     * @param array<string, list<string>> $trunks trunk name => addresses/CIDRs
     * @param array<string, int> $exts extension => any
     * @return array{0: string, 1: string}
     */
    public static function classify(array $sip, array $own, array $trunks, array $exts): array
    {
        $src = self::hostOf($sip['src']);
        $remote = in_array($src, $own, true) ? self::hostOf($sip['dst']) : $src;
        foreach ($trunks as $name => $hosts) {
            foreach ($hosts as $h) {
                if ($h === $remote || self::inCidr($remote, $h)) {
                    return ['trunk', (string) $name];
                }
            }
        }
        foreach ([$sip['from_uri'], $sip['to_uri']] as $uri) {
            if (preg_match('/^(?:"[^"]*"\s*)?<?sips?:([^@;>]+)@/i', trim((string) $uri), $m) && isset($exts[$m[1]])) {
                return ['extension', $m[1]];
            }
        }
        return ['other', $remote];
    }

    /** @return array<string, list<string>> */
    private static function trunkMatchers(PDO $db): array
    {
        $out = [];
        foreach ($db->query('SELECT trunk_name, ip_address, match_hosts FROM pbx_trunks WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $hosts = [];
            foreach (preg_split('/[\s,]+/', trim($t['ip_address'] . ',' . $t['match_hosts'])) ?: [] as $h) {
                $h = preg_replace('/:\d+$/', '', trim($h));
                if ($h === '') {
                    continue;
                }
                // A host name: compare its addresses (looked up once per pass).
                $ips = filter_var(explode('/', $h)[0], FILTER_VALIDATE_IP) ? [$h] : (@gethostbynamel($h) ?: []);
                array_push($hosts, ...$ips);
            }
            $out[$t['trunk_name']] = array_values(array_unique($hosts));
        }
        return $out;
    }

    // ------------------------------------------------------------ alerts

    /**
     * Trunks and extensions losing audio now: enough poor legs in the last
     * ALERT_MINUTES, and at least a fifth of their measured legs. The health
     * check call_quality reports them (bell, alarm e-mails).
     *
     * @return list<array{leg_type: string, leg_name: string, legs: int, poor: int, bad_in: int, bad_out: int, worst: string}>
     */
    public static function problems(): array
    {
        $minPoor = self::settings()['alert_legs'];
        $st = getDB()->query("SELECT leg_type, leg_name, COUNT(*) AS legs, SUM(level = 'poor') AS poor,
                SUM(bad_in) AS bad_in, SUM(bad_out) AS bad_out, MAX(GREATEST(loss_in_max, loss_out_max)) AS worst
            FROM pbx_call_quality_legs WHERE last_ts >= NOW() - INTERVAL " . self::ALERT_MINUTES . " MINUTE AND leg_type IN ('trunk', 'extension')
            GROUP BY leg_type, leg_name ORDER BY SUM(level = 'poor') DESC");
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC),
            fn($g) => (int) $g['poor'] >= $minPoor && (int) $g['poor'] * 5 >= (int) $g['legs']));
    }

    public static function alertTitle(array $g): string
    {
        return sprintf(t($g['leg_type'] === 'trunk' ? 'call_quality.alert_trunk' : 'call_quality.alert_extension'), $g['leg_name']);
    }

    /** Which way the audio is lost tells where to look. */
    public static function alertMessage(array $g): string
    {
        $in = (int) $g['bad_in'];
        $out = (int) $g['bad_out'];
        $trunk = $g['leg_type'] === 'trunk';
        $where = $in > 2 * $out ? ($trunk ? 'where_from_carrier' : 'where_from_phone')
            : ($out > 2 * $in ? ($trunk ? 'where_to_carrier' : 'where_to_phone') : 'where_both');
        return sprintf(t('call_quality.alert_message'), (int) $g['poor'], (int) $g['legs'], self::ALERT_MINUTES,
            self::pct((float) $g['worst']), t('call_quality.' . $where));
    }

    // ------------------------------------------------------------ the page

    /** Is anything measured at all? */
    public static function status(): array
    {
        $db = getDB();
        $last = self::signalingTable($db)
            ? $db->query("SELECT MAX(ts) FROM pbx_signaling_events WHERE proto = 'rtcp' AND ts >= NOW() - INTERVAL 1 DAY")->fetchColumn()
            : null;
        $pass = json_decode((string) getSystemSetting('call_quality_last_pass', ''), true);
        return [
            'capture' => getSystemSetting('signaling_capture', '0') === '1',
            'last_rtcp' => $last ?: null,
            'pass_ago' => is_array($pass) && isset($pass['at']) ? time() - (int) $pass['at'] : null,
        ];
    }

    /** Per trunk/extension over the last $hours. */
    public static function summary(int $hours = 24): array
    {
        return getDB()->query("SELECT leg_type, leg_name, COUNT(*) AS legs, SUM(level = 'poor') AS poor, SUM(level = 'fair') AS fair,
                ROUND(SUM(loss_in_sum) / NULLIF(SUM(reports_in), 0), 2) AS avg_in, ROUND(SUM(loss_out_sum) / NULLIF(SUM(reports_out), 0), 2) AS avg_out,
                MAX(loss_in_max) AS max_in, MAX(loss_out_max) AS max_out, MAX(jitter_in_max) AS jit_in, MAX(jitter_out_max) AS jit_out
            FROM pbx_call_quality_legs WHERE last_ts >= NOW() - INTERVAL " . max(1, $hours) . " HOUR
            GROUP BY leg_type, leg_name ORDER BY SUM(level = 'poor') DESC, COUNT(*) DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Poor and fair legs, newest first. */
    public static function badLegs(int $hours = 24, int $limit = 50): array
    {
        return getDB()->query('SELECT * FROM pbx_call_quality_legs WHERE last_ts >= NOW() - INTERVAL ' . max(1, $hours) . " HOUR
            AND level IN ('poor', 'fair') ORDER BY level = 'poor' DESC, last_ts DESC LIMIT " . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function legLabel(array $r): string
    {
        return match ($r['leg_type']) {
            'trunk' => sprintf(t('call_quality.leg_trunk'), $r['leg_name']),
            'extension' => sprintf(t('call_quality.leg_extension'), $r['leg_name']),
            'other' => sprintf(t('call_quality.leg_other'), $r['leg_name']),
            default => t('call_quality.leg_unknown'),
        };
    }

    public static function pct($v): string
    {
        return $v === null ? '–' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . ' %';
    }

    // ------------------------------------------------------------ helpers

    private static function prune(PDO $db, int $days): void
    {
        if (time() - (int) getSystemSetting('call_quality_pruned_at', '0') < 3600) {
            return;
        }
        $db->exec('DELETE FROM pbx_call_quality_legs WHERE last_ts < NOW() - INTERVAL ' . $days . ' DAY');
        self::setState('call_quality_pruned_at', (string) time());
    }

    private static function setState(string $key, string $value): void
    {
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }

    private static function signalingTable(PDO $db): bool
    {
        try {
            $db->query('SELECT rtcp_fraction_lost FROM pbx_signaling_events LIMIT 0');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** "10.0.0.5:5060", "[2001:db8::1]:5060" or a bare address → the address. */
    public static function hostOf(string $addr): string
    {
        $addr = trim($addr);
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $addr, $m)) {
            return $m[1];
        }
        return substr_count($addr, ':') === 1 ? explode(':', $addr)[0] : $addr;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/') || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }

    /** @return list<string> this server's addresses */
    public static function ownHosts(): array
    {
        $own = ['127.0.0.1', '::1'];
        foreach (['server_ip', 'pjsip_external_ip'] as $k) {
            $v = trim((string) getSystemSetting($k, ''));
            if ($v !== '') {
                $own[] = $v;
            }
        }
        foreach (preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $own[] = $ip;
            }
        }
        return array_values(array_unique($own));
    }
}
