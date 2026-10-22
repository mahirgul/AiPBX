<?php
/**
 * Call quality from RTCP — the reports `res_hep_rtcp` already sends.
 *
 * This service never touches audio. It reads the RTCP counters the capture
 * daemon stores (packet loss and jitter), groups them per Call-ID and answers
 * one question: is a call losing media, sustained enough to act on?
 *
 * Thresholds are deliberately simple and stated in the notice:
 *   - worst report at 5 % loss or more, twice in the window -> poor;
 *   - any report above 1 %                             -> fair;
 *   - otherwise                                        -> good.
 * `fraction_lost` is RFC 3550's 8-bit fixed point (255 = 100 %), so 13 is
 * ~5 %. Jitter is `ia_jitter` in RTP timestamp units; the sample rate is not
 * in the report, so it is reported as-is and never converted to milliseconds
 * or a made-up MOS score.
 *
 * The one automatic change is a trunk demotion for NEW calls: when the lossy
 * peer is a trunk and an outbound route has another trunk behind it, the
 * trunk moves to the end of that route's chain. Live calls are untouched, the
 * change is written to the audit log, and the administrator can reorder it
 * back on the Outbound routes page. Local phones, Wi-Fi and a carrier that is
 * bad on every trunk have no safe automatic fix — those become a notice for a
 * human.
 *
 * Everything a person sees goes through the portal, not through a private
 * channel: a notice in the top bar (`NoticeService`, deduped per problem), an
 * audit row for the one change it makes, and an evidence row that keeps the
 * RTCP samples behind every decision.
 *
 * Quality sensing needs the built-in capture: when Asterisk's `hep.conf` sends
 * HEP to a remote collector there are no local RTCP rows to read, and
 * `CaptureState` says so instead of reporting every line healthy.
 */

require_once __DIR__ . '/CaptureState.php';

final class CallQualityService
{
    public const WINDOW_MIN = 15;
    /** Two reports at this loss make a call poor, not one spike. */
    public const POOR_PCT = 5.0;
    public const FAIR_PCT = 1.0;
    /** Auto-fix looks at the last few minutes, not the whole window. */
    public const FIX_WINDOW_MIN = 10;
    /** One demotion per trunk per six hours: no flapping. */
    private const FIX_COOLDOWN = 21600;
    private const MAX_NOTICES = 5;
    /** Where this package's notices say they come from. */
    public const SOURCE = 'call-quality';

    /** This package's own table, created from `schema.sql` in the plugin root. */
    private const EVIDENCE_TABLE = 'plg_call_quality_evidence';

    /** @return array<int, array<string, mixed>> raw RTCP rows, oldest first */
    public static function rows(int $minutes = self::WINDOW_MIN): array
    {
        $minutes = max(1, min(1440, $minutes));
        // NOW() is the database's clock, which is how the daemon writes ts.
        // A PHP date would compare two timezones (the portal runs in local
        // time, MariaDB in UTC on many installs) and silently see no rows.
        $minutes = (int) $minutes;
        try {
            $st = getDB()->prepare(
                "SELECT call_id, ts, src, dst, rtcp_ssrc, rtcp_lost,
                        rtcp_fraction_lost, rtcp_jitter
                   FROM pbx_signaling_events
                  WHERE proto = 'rtcp'
                    AND ts >= (NOW() - INTERVAL {$minutes} MINUTE)
                  ORDER BY ts ASC"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // A missing migration or a database hiccup is "no measurement",
            // never a failed page or a false alarm.
            return [];
        }
    }

    /**
     * Groups rows per Call-ID and keeps the worst report block. Pure, so the
     * arithmetic is tested without a database.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    public static function aggregate(array $rows): array
    {
        $calls = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['call_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (!isset($calls[$id])) {
                $calls[$id] = [
                    'call_id' => $id,
                    'reports' => 0,
                    'worst_fraction' => 0,
                    'poor_reports' => 0,
                    'jitter' => 0,
                    'lost' => 0,
                    'first_ts' => (string) ($row['ts'] ?? ''),
                    'last_ts' => (string) ($row['ts'] ?? ''),
                    'peers' => [],
                ];
            }
            $c =& $calls[$id];
            $c['reports']++;
            $fraction = max(0, min(255, (int) ($row['rtcp_fraction_lost'] ?? 0)));
            $c['worst_fraction'] = max((int) $c['worst_fraction'], $fraction);
            if ($fraction * 100 / 256 >= self::POOR_PCT) {
                $c['poor_reports']++;
            }
            $c['jitter'] = max((int) $c['jitter'], (int) ($row['rtcp_jitter'] ?? 0));
            $c['lost'] = max((int) $c['lost'], (int) ($row['rtcp_lost'] ?? 0));
            $c['last_ts'] = (string) ($row['ts'] ?? $c['last_ts']);
            foreach (['src', 'dst'] as $side) {
                $host = self::hostOf((string) ($row[$side] ?? ''));
                if ($host !== '') {
                    $c['peers'][$host] = true;
                }
            }
            unset($c);
        }
        foreach ($calls as &$call) {
            $call['peers'] = array_keys($call['peers']);
        }
        unset($call);
        return $calls;
    }

    /**
     * One call's verdict. Pure.
     *
     * @param array<string, mixed> $call
     * @return array{level:string, loss_pct:float, reports:int, poor_reports:int,
     *               jitter:int, lost:int, reasons:array<int,string>}
     */
    public static function verdict(array $call): array
    {
        $fraction = max(0, min(255, (int) ($call['worst_fraction'] ?? 0)));
        $loss = round($fraction * 100 / 256, 2);
        $reports = (int) ($call['reports'] ?? 0);
        $poorReports = (int) ($call['poor_reports'] ?? 0);
        if ($poorReports >= 2 && $reports >= 2) {
            $level = 'poor';
        } elseif ($loss >= self::FAIR_PCT || $poorReports > 0) {
            // A single report at 5 %+ is a spike, not sustained loss, but it
            // still deserves a look; below 1 % is background noise.
            $level = 'fair';
        } else {
            $level = 'good';
        }
        $reasons = [];
        if ($fraction > 0) {
            $reasons[] = sprintf('packet loss %.2f%% (worst report)', $loss);
        }
        if ($poorReports > 0) {
            $reasons[] = sprintf('%d of %d RTCP reports were at or above %.0f%% loss', $poorReports, $reports, self::POOR_PCT);
        }
        if ((int) ($call['jitter'] ?? 0) > 0) {
            $reasons[] = sprintf('jitter %d RTP timestamp units', (int) $call['jitter']);
        }
        return [
            'level' => $level,
            'loss_pct' => $loss,
            'reports' => $reports,
            'poor_reports' => $poorReports,
            'jitter' => (int) ($call['jitter'] ?? 0),
            'lost' => (int) ($call['lost'] ?? 0),
            'reasons' => $reasons,
        ];
    }

    /** @return array<int, array<string, mixed>> calls with a `verdict` key, worst first */
    public static function active(int $minutes = self::WINDOW_MIN): array
    {
        $out = [];
        $map = self::trunkMap();
        foreach (self::aggregate(self::rows($minutes)) as $call) {
            $call['verdict'] = self::verdict($call);
            $call['trunk'] = self::trunkFor($call['peers'], $map);
            $out[] = $call;
        }
        usort($out, fn($a, $b) => [$a['verdict']['level'] === 'poor', $a['verdict']['loss_pct']]
            <=> [$b['verdict']['level'] === 'poor', $b['verdict']['loss_pct']]);
        return $out;
    }

    /** @return array<int, array<string, mixed>> only the poor calls */
    public static function degraded(int $minutes = self::WINDOW_MIN): array
    {
        return array_values(array_filter(
            self::active($minutes),
            fn($c) => $c['verdict']['level'] === 'poor'
        ));
    }

    /** @return array{measured:int, poor:int, fair:int, good:int, worst:?array<string, mixed>} */
    public static function summary(int $minutes = self::WINDOW_MIN): array
    {
        $calls = self::active($minutes);
        $count = ['poor' => 0, 'fair' => 0, 'good' => 0];
        foreach ($calls as $call) {
            $count[$call['verdict']['level']]++;
        }
        return [
            'measured' => count($calls),
            'poor' => $count['poor'],
            'fair' => $count['fair'],
            'good' => $count['good'],
            'worst' => $calls[0] ?? null,
        ];
    }

    /** Quality sensing is only possible where local RTCP rows exist. */
    public static function available(): bool
    {
        return CaptureState::local();
    }

    /** The peer host out of "1.2.3.4:5060" or "[2001:db8::1]:5060". */
    public static function hostOf(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '') {
            return '';
        }
        if ($addr[0] === '[') {
            $end = strpos($addr, ']');
            return $end === false ? $addr : substr($addr, 1, $end - 1);
        }
        $pos = strrpos($addr, ':');
        if ($pos !== false && substr_count($addr, ':') === 1) {
            return substr($addr, 0, $pos);
        }
        return $addr;
    }

    /**
     * The trunk whose configured address is one of these peers, or null.
     *
     * @param array<int, string> $peers
     * @param array<string, array<string, mixed>>|null $map trunk_name => row
     * @return array<string, mixed>|null
     */
    public static function trunkFor(array $peers, ?array $map = null): ?array
    {
        $map = $map ?? self::trunkMap();
        foreach ($peers as $peer) {
            if (isset($map[$peer])) {
                return $map[$peer];
            }
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> ip address => trunk row */
    public static function trunkMap(): array
    {
        try {
            $rows = getDB()->query(
                'SELECT id, trunk_name, title, ip_address FROM pbx_trunks WHERE is_active = 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip_address'] ?? ''));
            if ($ip !== '') {
                $map[$ip] = $row;
            }
        }
        return $map;
    }

    // ------------------------------------------------------------- evidence

    /**
     * The raw RTCP rows behind one call, bounded to what a ticket needs.
     * A long call can have hundreds of reports; the first four and the last
     * four tell the story (onset, peak, recovery).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function proofFor(string $callId, int $minutes = self::FIX_WINDOW_MIN): array
    {
        $minutes = max(1, min(1440, $minutes));
        try {
            $st = getDB()->prepare(
                "SELECT ts, src, dst, rtcp_ssrc, rtcp_lost, rtcp_fraction_lost, rtcp_jitter
                   FROM pbx_signaling_events
                  WHERE proto = 'rtcp' AND call_id = ?
                    AND ts >= (NOW() - INTERVAL {$minutes} MINUTE)
                  ORDER BY ts ASC"
            );
            $st->execute([$callId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        if (count($rows) <= 8) {
            return $rows;
        }
        return array_merge(array_slice($rows, 0, 4), array_slice($rows, -4));
    }

    /**
     * Copies the proof of one incident into durable storage. Returns the
     * evidence id, or null when it could not be stored (which never stops the
     * notice or the fix — the live rows are still there until retention).
     *
     * @param array<string, mixed> $call
     */
    public static function recordEvidence(array $call, string $action = 'none',
                                          string $detail = '', string $source = 'cron'): ?int
    {
        try {
            // One incident per call and first report, whatever the cron decided
            // on a later pass. A first "cooldown" record is upgraded when the
            // trunk is actually moved, instead of writing a second story.
            $key = substr(hash(
                'sha256',
                (string) $call['call_id'] . '|' . (string) $call['first_ts']
            ), 0, 64);
            $db = getDB();
            $table = self::EVIDENCE_TABLE;
            $find = $db->prepare("SELECT id, action FROM {$table} WHERE dedupe_key = ? LIMIT 1");
            $find->execute([$key]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                $previous = (string) ($existing['action'] ?? 'none');
                // Only a real outcome replaces an earlier guess; a later
                // "cooldown" pass must not erase "trunk_demoted".
                if (in_array($action, ['trunk_demoted', 'reload_failed'], true) && $action !== $previous) {
                    $db->prepare("UPDATE {$table} SET action = ?, action_detail = ? WHERE id = ?")
                        ->execute([mb_substr($action, 0, 32), mb_substr($detail, 0, 500), (int) $existing['id']]);
                }
                return (int) $existing['id'];
            }
            $verdict = self::verdict($call);
            $samples = self::proofFor((string) $call['call_id']);
            $proof = [
                'source' => "res_hep_rtcp -> aipbx-signaling -> pbx_signaling_events (proto='rtcp')",
                'window_minutes' => self::FIX_WINDOW_MIN,
                'thresholds' => [
                    'poor_pct' => self::POOR_PCT,
                    'fair_pct' => self::FAIR_PCT,
                    'min_poor_reports' => 2,
                ],
                'call' => [
                    'call_id' => (string) $call['call_id'],
                    'reports' => (int) $call['reports'],
                    'worst_fraction' => (int) $call['worst_fraction'],
                    'loss_pct' => $verdict['loss_pct'],
                    'jitter' => (int) $call['jitter'],
                    'first_ts' => (string) $call['first_ts'],
                    'last_ts' => (string) $call['last_ts'],
                    'peers' => array_values((array) $call['peers']),
                ],
                'samples' => $samples,
                'trunk' => $call['trunk'] ? [
                    'trunk_name' => (string) $call['trunk']['trunk_name'],
                    'ip_address' => (string) $call['trunk']['ip_address'],
                ] : null,
            ];
            $db->prepare(
                "INSERT INTO {$table}
                    (created_at, call_id, trunk_name, peer, level, loss_pct, jitter,
                     reports, first_ts, last_ts, evidence_json, action, action_detail,
                     source, dedupe_key)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                mb_substr((string) $call['call_id'], 0, 128),
                mb_substr((string) ($call['trunk']['trunk_name'] ?? ''), 0, 50),
                mb_substr((string) ($call['peers'][0] ?? ''), 0, 64),
                $verdict['level'] === 'poor' ? 'poor' : $verdict['level'],
                $verdict['loss_pct'],
                (int) $call['jitter'],
                (int) $call['reports'],
                $call['first_ts'] !== '' ? (string) $call['first_ts'] : null,
                $call['last_ts'] !== '' ? (string) $call['last_ts'] : null,
                json_encode($proof, JSON_UNESCAPED_SLASHES),
                mb_substr($action, 0, 32),
                mb_substr($detail, 0, 500),
                mb_substr($source, 0, 16),
                $key,
            ]);
            return (int) $db->lastInsertId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> newest evidence first */
    public static function recentEvidence(int $hours = 24, int $limit = 10): array
    {
        $hours = max(1, min(8760, $hours));
        $limit = max(1, min(100, $limit));
        try {
            $st = getDB()->prepare(
                "SELECT * FROM " . self::EVIDENCE_TABLE . "
                  WHERE created_at >= (NOW() - INTERVAL {$hours} HOUR)
                  ORDER BY id DESC LIMIT {$limit}"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public static function evidenceById(int $id): ?array
    {
        try {
            $st = getDB()->prepare('SELECT * FROM ' . self::EVIDENCE_TABLE . ' WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The trail a VoIP engineer writes on a ticket: what was measured, from
     * which source, over which window, and what was changed.
     */
    public static function evidenceTrail(int $id): string
    {
        $row = self::evidenceById($id);
        if ($row === null) {
            return '';
        }
        $proof = json_decode((string) ($row['evidence_json'] ?? ''), true);
        $proof = is_array($proof) ? $proof : [];
        $call = (array) ($proof['call'] ?? []);
        $th = (array) ($proof['thresholds'] ?? []);
        $trunk = $proof['trunk'] ?? null;
        $lines = [
            sprintf('Evidence #%d — %s quality, recorded %s (server time)', (int) $row['id'],
                (string) $row['level'], (string) $row['created_at']),
            'Call: ' . (string) $row['call_id'],
            sprintf(
                'Measured: %d RTCP report(s), %s to %s; worst loss %.2f%% (fraction %d/256); jitter %d RTP timestamp units',
                (int) $row['reports'],
                (string) ($row['first_ts'] ?? '?'),
                (string) ($row['last_ts'] ?? '?'),
                (float) $row['loss_pct'],
                (int) ($call['worst_fraction'] ?? 0),
                (int) $row['jitter']
            ),
            'Peers: ' . implode(', ', (array) ($call['peers'] ?? [$row['peer']])),
            $trunk
                ? 'Trunk: ' . (string) ($trunk['trunk_name'] ?? '') . ' (pbx_trunks.ip_address = ' . (string) ($trunk['ip_address'] ?? '') . ')'
                : 'Trunk: no configured trunk matched the media peer.',
            'Samples:',
        ];
        foreach ((array) ($proof['samples'] ?? []) as $sample) {
            $lines[] = sprintf(
                '  %s  %s -> %s  SSRC=%s  fraction_lost=%s  lost=%s  jitter=%s',
                (string) ($sample['ts'] ?? ''),
                (string) ($sample['src'] ?? ''),
                (string) ($sample['dst'] ?? ''),
                (string) ($sample['rtcp_ssrc'] ?? ''),
                (string) ($sample['rtcp_fraction_lost'] ?? '?'),
                (string) ($sample['rtcp_lost'] ?? '?'),
                (string) ($sample['rtcp_jitter'] ?? '?')
            );
        }
        $lines[] = sprintf(
            'Decision: two reports at %.0f%% loss or more -> poor (fair from %.0f%%, window %d min).',
            (float) ($th['poor_pct'] ?? self::POOR_PCT),
            (float) ($th['fair_pct'] ?? self::FAIR_PCT),
            (int) ($proof['window_minutes'] ?? self::FIX_WINDOW_MIN)
        );
        $lines[] = 'Action: ' . ((string) ($row['action_detail'] ?? '') !== ''
            ? (string) $row['action_detail']
            : 'none — recorded for the trail only.');
        $lines[] = 'Source: ' . (string) ($proof['source'] ?? 'pbx_signaling_events');
        return implode("\n", $lines);
    }

    /** One compact line for a notice or an e-mail. */
    private static function evidenceNote(array $ids): string
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return '';
        }
        $row = self::evidenceById($ids[0]);
        if ($row === null) {
            return '';
        }
        return "\n\n" . sprintf(
            t('call_quality.evidence_ref', "Evidence #%d: %d RTCP report(s), %s to %s, peer %s, worst loss %.2f%%. Full trail: aipbx_plugin_cli.php call-quality evidence %d"),
            (int) $row['id'],
            (int) $row['reports'],
            (string) ($row['first_ts'] ?? '?'),
            (string) ($row['last_ts'] ?? '?'),
            (string) $row['peer'],
            (float) $row['loss_pct'],
            (int) $row['id']
        );
    }

    /** A day's incidents, with their evidence ids, for a report or the CLI. */
    public static function report(int $hours = 24, int $limit = 20): string
    {
        $rows = self::recentEvidence($hours, $limit);
        $header = sprintf('Quality evidence, last %d h — %d incident(s).', $hours, count($rows));
        if (!$rows) {
            return $header . "\nNo stored quality evidence in this window.";
        }
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%d  %s  %s  call %s%s  %.2f%% loss over %d report(s) (%s to %s), peer %s — %s',
                (int) $row['id'],
                (string) $row['created_at'],
                (string) $row['level'],
                mb_substr((string) $row['call_id'], 0, 32),
                (string) $row['trunk_name'] !== '' ? ' on ' . $row['trunk_name'] : '',
                (float) $row['loss_pct'],
                (int) $row['reports'],
                (string) ($row['first_ts'] ?? '?'),
                (string) ($row['last_ts'] ?? '?'),
                (string) $row['peer'],
                (string) $row['action_detail'] !== '' ? (string) $row['action_detail'] : 'no action'
            );
        }
        return implode("\n", $lines);
    }

    /**
     * How long per-call evidence is kept, from this package's own setting.
     *
     * @param array<string, mixed> $settings the plugin settings the loader passes
     */
    public static function evidenceDays(array $settings = []): int
    {
        $days = $settings['evidence_days'] ?? 180;
        return max(7, min(3650, (int) $days));
    }

    /**
     * Whether the one automatic change is allowed. Off by default: installing
     * a telemetry package must not reorder somebody's outbound routes.
     *
     * @param array<string, mixed> $settings
     */
    public static function autoFix(array $settings = []): bool
    {
        $value = strtolower(trim((string) ($settings['auto_fix'] ?? '')));
        return in_array($value, ['1', 'yes', 'true', 'on'], true);
    }

    /** @param array<string, mixed> $settings */
    private static function pruneEvidence(array $settings): void
    {
        $days = self::evidenceDays($settings);
        try {
            getDB()->exec("DELETE FROM " . self::EVIDENCE_TABLE . " WHERE created_at < (NOW() - INTERVAL {$days} DAY)");
        } catch (\Throwable $e) {
            // Pruning is housekeeping; never fail the quality run for it.
        }
    }

    /**
     * The cron entry (`plugin.json` -> `cron[]`): one pass every five minutes.
     *
     * The decision is printed as one JSON line so it lands in
     * `sys_plugin_cron_runs.output`; a failure returns 1, which the dispatcher
     * records, and never throws.
     *
     * @param array<string, mixed> $settings
     * @param array<int, string> $argv
     */
    public static function run(array $settings = [], string $slug = 'call-quality', array $argv = []): int
    {
        $result = self::tick($settings);
        // echo, not fwrite(STDOUT): the dispatcher records whatever this
        // process writes, and buffered output is what a test can see.
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return isset($result['error']) ? 1 : 0;
    }

    /**
     * The quality cron: notice what is degraded and make the one safe change.
     * Silent when capture is not local or nothing is degraded; observe-only
     * still records evidence and raises notices.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function tick(array $settings = []): array
    {
        $out = [
            'fixed' => [],
            'notices' => 0,
            'skipped' => [],
            'capture' => 'unknown',
            'capture_detail' => '',
        ];
        try {
            if (!class_exists('NoticeService')) {
                // The portal this package stands on. Without it there is no way
                // to tell anyone anything, so saying "fine" would be worse than
                // saying nothing.
                $out['skipped'][] = 'notices_missing';
                return $out;
            }
            $capture = CaptureState::snapshot();
            $out['capture'] = $capture['mode'];
            $out['capture_detail'] = $capture['detail'];
            self::pruneEvidence($settings);
            if (!$capture['local']) {
                // No local rows: saying nothing is right. Scoring an empty set
                // would report every line healthy, which is the one answer this
                // package must never invent.
                $out['skipped'][] = 'capture_not_local';
                return $out;
            }
            // Observe-only is still useful: evidence and notices continue, the
            // trunk is simply not moved. The switch is about changing routes,
            // not about keeping the proof.
            $autoFix = self::autoFix($settings);
            if (!$autoFix) {
                $out['skipped'][] = 'auto_fix_off';
            }
            $calls = self::degraded(self::FIX_WINDOW_MIN);
            if (!$calls) {
                return $out;
            }
            $map = self::trunkMap();
            $byTrunk = [];
            $others = [];
            foreach ($calls as $call) {
                $trunk = self::trunkFor($call['peers'], $map);
                if ($trunk !== null) {
                    $byTrunk[(string) $trunk['trunk_name']]['trunk'] = $trunk;
                    $byTrunk[(string) $trunk['trunk_name']]['calls'][] = $call;
                } else {
                    $others[] = $call;
                }
            }
            // Two trunks losing packets at once is not a trunk problem: moving
            // either one would just swap the order and punish a carrier that is
            // not at fault. That is a human decision (or the LAN).
            if (count($byTrunk) > 1) {
                $ids = [];
                foreach ($byTrunk as $group) {
                    foreach ($group['calls'] as $call) {
                        $ids[] = self::recordEvidence(
                            $call,
                            'multiple_trunks',
                            'Sustained loss on ' . implode(', ', array_keys($byTrunk))
                                . '; no automatic change, a human must check the uplink and both carriers.'
                        );
                    }
                }
                NoticeService::notify(
                    'critical',
                    t('call_quality.notice_wide_title', 'Sustained packet loss on more than one trunk'),
                    sprintf(
                        t('call_quality.notice_wide_body', 'Calls are losing media on %s at the same time. No automatic change was made: moving either trunk would blame a carrier that may not be at fault. Check the uplink and both carriers.'),
                        implode(', ', array_keys($byTrunk))
                    ) . self::evidenceNote($ids),
                    'quality:wide',
                    self::SOURCE
                );
                $out['skipped'][] = 'multiple_trunks';
                $byTrunk = [];
            }
            foreach ($byTrunk as $name => $group) {
                $result = $autoFix
                    ? self::failover($name)
                    : ['skipped' => 'auto_off', 'detail' => 'Automatic trunk failover is switched off for this plugin; evidence recorded only.'];
                if (isset($result['routes'])) {
                    $out['fixed'][] = $name;
                }
                $action = isset($result['routes']) ? 'trunk_demoted'
                    : (!empty($result['failed']) ? 'reload_failed'
                    : ((string) ($result['skipped'] ?? '') === 'no_route' ? 'no_alternative'
                    : ((string) ($result['skipped'] ?? '') === 'cooldown' ? 'cooldown'
                    : ((string) ($result['skipped'] ?? '') === 'pending' ? 'pending'
                    : ((string) ($result['skipped'] ?? '') === 'auto_off' ? 'observed' : 'none')))));
                $detail = (string) ($result['detail'] ?? '');
                $ids = [];
                foreach ($group['calls'] as $call) {
                    $ids[] = self::recordEvidence($call, $action, $detail);
                }
                if ($out['notices'] >= self::MAX_NOTICES || !empty($result['failed'])) {
                    // A failed reload already raised its own critical notice.
                    continue;
                }
                if (isset($result['routes'])) {
                    $worst = $group['calls'][0];
                    $res = NoticeService::notify(
                        'warn',
                        sprintf(t('call_quality.notice_fixed_title', 'Trunk %s moved to backup'), $name),
                        sprintf(
                            t('call_quality.notice_fixed_body', 'Calls on %s lost %.2f%% of their packets (%d RTCP report(s)). New calls now try the other trunks first in %s. Live calls were not touched; undo on Outbound routes.'),
                            $name,
                            $worst['verdict']['loss_pct'],
                            $worst['verdict']['reports'],
                            implode(', ', $result['routes'])
                        ) . self::evidenceNote($ids),
                        'quality:fix:' . $name,
                        self::SOURCE
                    );
                } elseif (($result['skipped'] ?? '') === 'no_route') {
                    $res = NoticeService::notify(
                        'warn',
                        sprintf(t('call_quality.notice_no_alt_title', 'Sustained loss on %s, no alternative'), $name),
                        sprintf(
                            t('call_quality.notice_no_alt_body', 'Calls on %s are losing %.2f%% of their packets and no outbound route has another trunk behind it, so nothing was changed. Add a second trunk to the route or talk to the carrier.'),
                            $name,
                            $group['calls'][0]['verdict']['loss_pct']
                        ) . self::evidenceNote($ids),
                        'quality:noalt:' . $name,
                        self::SOURCE
                    );
                } elseif ($action === 'observed') {
                    $res = NoticeService::notify(
                        'warn',
                        sprintf(t('call_quality.notice_observed_title', 'Sustained loss on %s (observe only)'), $name),
                        sprintf(
                            t('call_quality.notice_observed_body', 'Calls on %s are losing %.2f%% of their packets. Automatic trunk failover is switched off for this plugin, so the evidence was recorded and nothing was changed.'),
                            $name,
                            $group['calls'][0]['verdict']['loss_pct']
                        ) . self::evidenceNote($ids),
                        'quality:observed:' . $name,
                        self::SOURCE
                    );
                } else {
                    $res = ['deduped' => true];
                }
                if (isset($res['id'])) {
                    $out['notices']++;
                }
            }
            foreach (array_slice($others, 0, max(0, self::MAX_NOTICES - $out['notices'])) as $call) {
                $peer = (string) ($call['peers'][0] ?? 'unknown');
                $id = self::recordEvidence(
                    $call,
                    'local_peer',
                    'No automatic change: the media peer is not a configured trunk.'
                );
                $res = NoticeService::notify(
                    'warn',
                    t('call_quality.notice_local_title', 'Call quality problem on a phone or LAN peer'),
                    sprintf(
                        t('call_quality.notice_local_body', 'Call %s is losing media (%.2f%% packet loss) against %s (%s). The peer is not a configured trunk, so there is no safe automatic change: check the phone, its Wi-Fi or the local switch.'),
                        mb_substr($call['call_id'], 0, 32),
                        $call['verdict']['loss_pct'],
                        $peer,
                        implode(', ', $call['peers'])
                    ) . self::evidenceNote([(int) $id]),
                    'quality:local:' . $peer,
                    self::SOURCE
                );
                if (isset($res['id'])) {
                    $out['notices']++;
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 200);
        }
        return $out;
    }

    /**
     * Moves one trunk to the end of every outbound route that has another
     * trunk, then regenerates the dialplan. New calls use the new order; live
     * calls keep their channel. Returns ['routes'=>names] on success.
     *
     * @return array<string, mixed>
     */
    private static function failover(string $trunkName): array
    {
        // The cooldown is read from the evidence table, not from a second
        // memory: the row that says what happened is the row that says when.
        if (self::recentlyDemoted($trunkName)) {
            return [
                'skipped' => 'cooldown',
                'detail' => 'Trunk ' . $trunkName . ' was already moved to backup within the last six hours; no change.',
            ];
        }
        if (!self::loadSyncHelpers()) {
            return [
                'skipped' => 'sync_unavailable',
                'detail' => 'This portal has no pending-sync API for one domain; nothing was changed and the trunk stays where it is.',
            ];
        }
        $db = getDB();
        // Never apply someone else's outbound edits as a side effect.
        $pending = (int) $db->query(
            "SELECT COUNT(*) FROM sys_pending_sync WHERE domain = 'outbound_dialplan'"
        )->fetchColumn();
        if ($pending > 0) {
            return [
                'skipped' => 'pending',
                'detail' => 'Another outbound change is waiting for Apply; no change was made to ' . $trunkName . '.',
            ];
        }
        $routes = $db->query(
            'SELECT id, route_name, trunks_json FROM pbx_outbound_routes WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $changed = [];
        foreach ($routes as $route) {
            $chain = json_decode((string) ($route['trunks_json'] ?? ''), true);
            if (!is_array($chain) || count($chain) < 2) {
                continue;
            }
            $names = array_map(fn($t) => trim((string) ($t['trunk_name'] ?? '')), $chain);
            $pos = array_search($trunkName, $names, true);
            if ($pos === false || $pos === count($chain) - 1) {
                continue;
            }
            $before = $chain;
            $item = array_splice($chain, (int) $pos, 1);
            $chain[] = $item[0];
            $db->prepare('UPDATE pbx_outbound_routes SET trunks_json = ? WHERE id = ?')
                ->execute([json_encode($chain), (int) $route['id']]);
            $changed[] = [
                'id' => (int) $route['id'],
                'name' => (string) ($route['route_name'] ?? ('route ' . $route['id'])),
                'chain_before' => $before,
                'before' => array_map(fn($t) => trim((string) ($t['trunk_name'] ?? '')), $before),
                'after' => array_map(fn($t) => trim((string) ($t['trunk_name'] ?? '')), $chain),
            ];
        }
        if (!$changed) {
            return [
                'skipped' => 'no_route',
                'detail' => 'No active outbound route has ' . $trunkName . ' before another trunk; nothing changed.',
            ];
        }

        foreach ($changed as $route) {
            markPendingSync('outbound_dialplan', 'outbound_route', $route['id'], $route['name'], 'update', null);
        }
        // Apply only the domain this fix just changed. Calling the sync
        // function directly would skip the pending-sync bookkeeping; applying
        // every domain would push another administrator's queued edits to
        // Asterisk as a side effect of a trunk demotion.
        try {
            $applied = applyPendingSyncDomain('outbound_dialplan');
            $ok = !empty($applied['success']);
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            // The sync rolled the file back; put the database back too, so the
            // page and Asterisk cannot disagree about the route order.
            $undo = $db->prepare('UPDATE pbx_outbound_routes SET trunks_json = ? WHERE id = ?');
            $drop = $db->prepare("DELETE FROM sys_pending_sync WHERE domain = 'outbound_dialplan' AND entity_id = ?");
            foreach ($changed as $route) {
                // `before` is the names for the ticket; the JSON is rebuilt
                // from the saved chain below.
                $undo->execute([json_encode($route['chain_before']), $route['id']]);
                $drop->execute([(string) $route['id']]);
            }
            $out = [
                'failed' => true,
                'detail' => 'Dialplan reload failed; route order for ' . $trunkName . ' was restored and nothing changed.',
            ];
            NoticeService::notify(
                'critical',
                sprintf(t('call_quality.notice_undo_title', 'Trunk demotion for %s was rolled back'), $trunkName),
                sprintf(t('call_quality.notice_undo_body', 'The dialplan reload failed after moving %s to backup, so the previous route order was restored. Nothing changed and no call was affected.'), $trunkName),
                'quality:undofail:' . $trunkName,
                self::SOURCE
            );
            return $out;
        }
        // applyPendingSyncDomain() deletes this domain's rows on success.
        $names = array_column($changed, 'name');
        $trail = [];
        foreach ($changed as $route) {
            $trail[] = $route['name'] . ' [' . implode(', ', $route['before']) . '] -> [' . implode(', ', $route['after']) . ']';
        }
        writeAuditLog(
            'call_quality',
            'trunk_failover',
            $trunkName,
            $trunkName . ' -> last in ' . implode(', ', $names)
                . ' — reason: sustained RTCP packet loss; scope: new calls only; undo: Outbound routes',
            'update',
            null
        );
        return [
            'routes' => $names,
            'detail' => 'Moved ' . $trunkName . ' to the end of: ' . implode('; ', $trail)
                . '. Dialplan reloaded; new calls use the new order. Undo: Outbound routes.',
        ];
    }

    /**
     * Loads the portal's pending-sync helpers, once.
     *
     * `applyPendingSyncDomain()` is procedural, so no autoloader finds it, and
     * the plugin cannot reach it with a relative path: the catalog usually
     * lives outside the portal tree (`/var/lib/aipbx/plugins`). The portal that
     * booted this process can say where it is, though — a core class knows its
     * own file name, and three directories up is the web root.
     */
    private static function loadSyncHelpers(): bool
    {
        if (function_exists('applyPendingSyncDomain') && function_exists('markPendingSync')) {
            return true;
        }
        foreach (['PluginManager', 'NoticeService'] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $file = (new ReflectionClass($class))->getFileName();
            if (!is_string($file) || $file === '') {
                continue;
            }
            $helper = dirname($file, 3) . '/src/asterisk_sync.php';
            if (is_file($helper)) {
                require_once $helper;
                break;
            }
        }
        return function_exists('applyPendingSyncDomain') && function_exists('markPendingSync');
    }

    /** Has this trunk been demoted inside the cooldown window? */
    private static function recentlyDemoted(string $trunkName): bool
    {
        try {
            $st = getDB()->prepare(
                'SELECT COUNT(*) FROM ' . self::EVIDENCE_TABLE . "
                  WHERE trunk_name = ? AND action = 'trunk_demoted'
                    AND created_at >= (NOW() - INTERVAL " . self::FIX_COOLDOWN . ' SECOND)'
            );
            $st->execute([$trunkName]);
            return (int) $st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            // Without the table the demotion cannot be remembered; one repeat
            // is better than a crash, and the evidence insert will report the
            // missing table on its own.
            return false;
        }
    }

    // ------------------------------------------------------------------ CLI

    /**
     * `aipbx_plugin_cli.php call-quality report [--hours=24]`
     *
     * @param array<string, mixed> $settings
     * @param array<int, string> $argv
     */
    public static function cliReport(array $settings = [], string $slug = 'call-quality', array $argv = []): int
    {
        $hours = 24;
        foreach ($argv as $arg) {
            if (preg_match('/^--hours=(\d+)$/', (string) $arg, $m) === 1) {
                $hours = max(1, min(8760, (int) $m[1]));
            }
        }
        echo self::report($hours) . "\n";
        return 0;
    }

    /**
     * `aipbx_plugin_cli.php call-quality evidence <id>`
     *
     * @param array<string, mixed> $settings
     * @param array<int, string> $argv
     */
    public static function cliEvidence(array $settings = [], string $slug = 'call-quality', array $argv = []): int
    {
        $id = (int) ($argv[0] ?? 0);
        $trail = $id > 0 ? self::evidenceTrail($id) : '';
        if ($trail === '') {
            fwrite(STDERR, 'No quality evidence #' . $id . ".\n");
            return 1;
        }
        echo $trail . "\n";
        return 0;
    }

    /**
     * `aipbx_plugin_cli.php call-quality tick` — the same pass the cron runs,
     * printed for a person, on demand.
     *
     * @param array<string, mixed> $settings
     * @param array<int, string> $argv
     */
    public static function cliTick(array $settings = [], string $slug = 'call-quality', array $argv = []): int
    {
        return self::run($settings, $slug, $argv);
    }

    /**
     * `aipbx_plugin_cli.php call-quality capture` — say where RTCP goes, and
     * why the scoring can or cannot see it. This is the first thing to run when
     * an install "does nothing".
     *
     * @param array<string, mixed> $settings
     * @param array<int, string> $argv
     */
    public static function cliCapture(array $settings = [], string $slug = 'call-quality', array $argv = []): int
    {
        $state = CaptureState::snapshot();
        echo sprintf(
            "mode: %s\nlocal: %s\nconfigured: %s\nfile: %s\ndaemon: %s\n%s\n",
            $state['mode'],
            $state['local'] ? 'yes' : 'no',
            $state['address'] !== '' ? $state['address'] : '(none)',
            $state['path'],
            $state['listener'],
            $state['detail']
        );
        return 0;
    }
}
