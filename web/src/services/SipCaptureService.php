<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * Troubleshooting → SIP traffic. The signaling daemon (signaling/aipbx_signaling/pcap.py)
 * writes every SIP message Asterisk reports over HEP into pcap files of at most
 * 10 MB in DIR; this reads them for the pages: the files, the calls of a file,
 * the messages of one call (the ladder) and the newest messages (live).
 *
 * A packet is a raw IPv4/IPv6 + UDP packet (LINKTYPE_RAW) around the SIP message.
 */
final class SipCaptureService
{
    public const DIR = '/var/lib/aipbx/sip-capture';
    private const NAME = '/^sip-(\d{8}-\d{6})(?:_(\d{8}-\d{6}))?\.pcap$/';
    /** At most this many messages are read for one page. */
    public const MAX_MESSAGES = 20000;

    // ---------------------------------------------------------------- settings

    /** @return array{capture: bool, files: bool, days: int, max_mb: int, retention: int} */
    public static function settings(): array
    {
        return [
            'capture' => getSystemSetting('signaling_capture', '0') === '1',
            'files' => getSystemSetting('sip_capture_files', '0') === '1',
            'days' => max(1, min(10, (int) getSystemSetting('sip_capture_days', '10'))),
            'max_mb' => max(100, min(51200, (int) getSystemSetting('sip_capture_max_mb', '2048'))),
            'retention' => max(1, min(3650, (int) getSystemSetting('signaling_retention_days', '30'))),
        ];
    }

    public static function saveSettings(array $post): array
    {
        return PBXHelper::handleAction($post['csrf_token'] ?? '', function () use ($post) {
            $capture = ($post['capture'] ?? '0') === '1';
            $files = ($post['files'] ?? '0') === '1';
            $new = [
                'signaling_capture' => $capture ? '1' : '0',
                'sip_capture_files' => $files ? '1' : '0',
                'sip_capture_days' => (string) max(1, min(10, (int) ($post['days'] ?? 10))),
                'sip_capture_max_mb' => (string) max(100, min(51200, (int) ($post['max_mb'] ?? 2048))),
                'signaling_retention_days' => (string) max(1, min(3650, (int) ($post['retention'] ?? 30))),
            ];
            $captureChanged = getSystemSetting('signaling_capture', '0') !== $new['signaling_capture'];
            foreach ($new as $k => $v) {
                setSystemSetting($k, $v);
            }
            if ($captureChanged) {
                require_once __DIR__ . '/../sync/SyncSignaling.php';
                syncSignalingCapture();
            }
            writeAuditLog('system', 'sip_capture', 'settings', 'SIP capture: ' . ($capture ? 'on' : 'off') . ', files: '
                . ($files ? "on, {$new['sip_capture_days']} days, {$new['sip_capture_max_mb']} MB" : 'off')
                . ", events kept {$new['signaling_retention_days']} days", 'update', $_SESSION['user_id'] ?? null);
            return t('siptrace.settings_saved');
        });
    }

    /** @return array{rows: int, last: ?string, files: int, bytes: int} */
    public static function status(): array
    {
        $rows = 0;
        $last = null;
        try {
            $r = getDB()->query('SELECT COUNT(*) AS n, MAX(ts) AS last FROM pbx_signaling_events WHERE ts >= NOW() - INTERVAL 1 DAY')->fetch(PDO::FETCH_ASSOC);
            $rows = (int) ($r['n'] ?? 0);
            $last = $r['last'] ?? null;
        } catch (\Throwable $e) {
        }
        $files = self::files();
        return ['rows' => $rows, 'last' => $last, 'files' => count($files), 'bytes' => array_sum(array_column($files, 'size'))];
    }

    // ------------------------------------------------------------------- files

    /**
     * The capture files, newest first. "open" is the one still being written.
     *
     * @return list<array{name: string, start: int, end: int, size: int, open: bool}>
     */
    public static function files(string $dir = self::DIR): array
    {
        $out = [];
        foreach (glob($dir . '/sip-*.pcap') ?: [] as $path) {
            if (!preg_match(self::NAME, basename($path), $m)) {
                continue;
            }
            $start = self::stampToTime($m[1]);
            $open = empty($m[2]);
            $out[] = ['name' => basename($path), 'start' => $start,
                'end' => $open ? (int) @filemtime($path) : self::stampToTime($m[2]),
                'size' => (int) @filesize($path), 'open' => $open];
        }
        usort($out, fn($a, $b) => $b['start'] <=> $a['start']);
        return $out;
    }

    private static function stampToTime(string $stamp): int
    {
        $dt = \DateTime::createFromFormat('Ymd-His', $stamp);
        return $dt ? $dt->getTimestamp() : 0;
    }

    /** A file of DIR by its name; throws for anything else. */
    public static function path(string $name, string $dir = self::DIR): string
    {
        if (!preg_match(self::NAME, $name) || !is_file($dir . '/' . $name)) {
            throw new \Exception(t('siptrace.err_no_file'));
        }
        return $dir . '/' . $name;
    }

    // ----------------------------------------------------------------- reading

    /**
     * Messages of a pcap file from byte $offset on.
     *
     * @return array{messages: list<array<string, mixed>>, offset: int}
     */
    public static function read(string $path, int $offset = 24, int $limit = self::MAX_MESSAGES): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return ['messages' => [], 'offset' => $offset];
        }
        $head = fread($fh, 24);
        if (strlen((string) $head) < 24 || unpack('V', $head)[1] !== 0xA1B2C3D4) {
            fclose($fh);
            return ['messages' => [], 'offset' => $offset];
        }
        $offset = max(24, $offset);
        fseek($fh, $offset);
        $messages = [];
        while (count($messages) < $limit) {
            $rec = fread($fh, 16);
            if (strlen((string) $rec) < 16) {
                break;
            }
            ['s' => $sec, 'u' => $usec, 'l' => $len] = unpack('Vs/Vu/Vl', $rec);
            if ($len <= 0 || $len > 70000) {
                break;
            }
            $pkt = fread($fh, $len);
            if (strlen((string) $pkt) < $len) {
                break; // being written right now: read it next time
            }
            $offset += 16 + $len;
            $msg = self::packet($pkt);
            if ($msg !== null) {
                $msg['ts'] = $sec + $usec / 1e6;
                $msg['pos'] = $offset;
                $messages[] = $msg;
            }
        }
        fclose($fh);
        return ['messages' => $messages, 'offset' => $offset];
    }

    /** A raw IP + UDP packet -> source, destination and the parsed SIP message. */
    public static function packet(string $pkt): ?array
    {
        $v = ord($pkt[0] ?? "\0") >> 4;
        if ($v === 4) {
            $ihl = (ord($pkt[0]) & 0x0F) * 4;
            $src = inet_ntop(substr($pkt, 12, 4));
            $dst = inet_ntop(substr($pkt, 16, 4));
            $udp = substr($pkt, $ihl);
        } elseif ($v === 6) {
            $src = self::ip6(substr($pkt, 8, 16));
            $dst = self::ip6(substr($pkt, 24, 16));
            $udp = substr($pkt, 40);
        } else {
            return null;
        }
        if (strlen($udp) < 8) {
            return null;
        }
        ['sp' => $sp, 'dp' => $dp] = unpack('nsp/ndp', $udp);
        $sip = self::sip(substr($udp, 8));
        if ($sip === null) {
            return null;
        }
        return $sip + ['src' => $src . ':' . $sp, 'dst' => $dst . ':' . $dp];
    }

    private static function ip6(string $raw): string
    {
        $ip = (string) inet_ntop($raw);
        return str_starts_with($ip, '::ffff:') && str_contains($ip, '.') ? substr($ip, 7) : $ip;
    }

    /** The parts of a SIP message the pages need, and the message itself. */
    public static function sip(string $text): ?array
    {
        $first = strtok($text, "\r\n");
        if ($first === false) {
            return null;
        }
        if (preg_match('#^SIP/2\.0\s+(\d{3})\s*(.*)$#', $first, $m)) {
            $label = $m[1] . ' ' . trim($m[2]);
            $method = '';
            $status = (int) $m[1];
        } elseif (preg_match('#^([A-Z]+)\s+(\S+)\s+SIP/2\.0$#', $first, $m)) {
            $label = $m[1];
            $method = $m[1];
            $status = 0;
        } else {
            return null;
        }
        $header = static function (array $names) use ($text): string {
            foreach ($names as $n) {
                if (preg_match('/^' . $n . '\s*:\s*(.+?)\s*$/mi', $text, $m)) {
                    return $m[1];
                }
            }
            return '';
        };
        $cseq = $header(['CSeq']);
        return [
            'label' => $label,
            'method' => $method !== '' ? $method : (preg_match('/\d+\s+([A-Z]+)/', $cseq, $c) ? $c[1] : ''),
            'request' => $method !== '',
            'status' => $status,
            'call_id' => $header(['Call-ID', 'i']),
            'from' => self::party($header(['From', 'f'])),
            'to' => self::party($header(['To', 't'])),
            'cseq' => $cseq,
            'ua' => $header(['User-Agent', 'Server']),
            'text' => $text,
        ];
    }

    /** "\"Kemal\" <sip:1001@pbx>;tag=x" -> "1001". */
    public static function party(string $value): string
    {
        return preg_match('/<?(?:sips?|tel):([^@;>]+)/i', $value, $m) ? $m[1] : '';
    }

    /**
     * The dialogs of a file: grouped by Call-ID, with the first request's method,
     * the last final answer, the time and the numbers. Optional filter on a number,
     * address, method or status.
     *
     * @return list<array<string, mixed>>
     */
    public static function calls(array $messages, string $filter = ''): array
    {
        $calls = [];
        foreach ($messages as $m) {
            $id = $m['call_id'] !== '' ? $m['call_id'] : '(no Call-ID)';
            $c = $calls[$id] ?? ['call_id' => $id, 'start' => $m['ts'], 'end' => $m['ts'], 'method' => '', 'from' => '', 'to' => '',
                'status' => 0, 'count' => 0, 'hosts' => [], 'ua' => ''];
            $c['end'] = $m['ts'];
            $c['count']++;
            if ($m['request'] && $c['method'] === '') {
                $c['method'] = $m['method'];
                $c['from'] = $m['from'];
                $c['to'] = $m['to'];
            }
            if (!$m['request'] && $m['status'] >= 200 && $m['method'] === ($c['method'] ?: $m['method'])) {
                $c['status'] = $m['status'];
            }
            if ($c['ua'] === '' && $m['ua'] !== '') {
                $c['ua'] = $m['ua'];
            }
            $c['hosts'][explode(':', $m['src'])[0]] = true;
            $c['hosts'][explode(':', $m['dst'])[0]] = true;
            $calls[$id] = $c;
        }
        $filter = trim($filter);
        $out = [];
        foreach (array_reverse($calls) as $c) {
            $c['hosts'] = array_keys($c['hosts']);
            if ($filter !== '' && stripos(implode(' ', [$c['call_id'], $c['method'], $c['from'], $c['to'], (string) $c['status'], implode(' ', $c['hosts'])]), $filter) === false) {
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    /** The messages of one dialog, in order. */
    public static function dialog(array $messages, string $callId): array
    {
        return array_values(array_filter($messages, fn($m) => $m['call_id'] === $callId));
    }
}
