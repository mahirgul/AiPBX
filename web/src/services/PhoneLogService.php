<?php
/**
 * PBX → Phone logs: what desk phones send as syslog, received by
 * aipbx-syslogd (Network services → Syslog) into FILE, one line per message:
 *
 *     2026-10-15T11:10:01+03:00 10.12.3.90 err <message>
 *
 * The page shows the newest lines and follows the file (the browser asks for
 * what came after its cursor every few seconds). Lines can be narrowed to one
 * phone by IP or MAC address, and by text.
 *
 * A MAC address stands for the IP addresses that phone used: its row on the
 * Phones page (last configuration download), waiting phones, the download log
 * and the log itself (NEC phones write "[MAC:0060B9EF8B1F]" in every line),
 * and for lines that name the MAC.
 *
 * Next to the syslog the page has a tab for every other way a phone talks to
 * the server, so one place answers "did the phone get its file?":
 *  - HTTPS: provisioning requests (pbx_phone_fetch_log),
 *  - FTP:   vsftpd's transfer log (/var/log/aipbx-ftp.log),
 *  - TFTP and DHCP: dnsmasq's journal (dnsmasq-tftp, dnsmasq-dhcp).
 * FTP, TFTP and DHCP are read through `aipbx-priv netsvc log` (root only).
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once __DIR__ . '/phones/PhoneModels.php';
require_once __DIR__ . '/PhoneProvisionService.php';
require_once dirname(__DIR__) . '/priv_helper.php';

class PhoneLogService
{
    public const FILE = '/var/log/aipbx-phones/phones.log';

    /** Lines shown when the page opens. */
    public const MAX_LINES = 500;
    /** Bytes read from the end of the file when the page opens / per follow-up. */
    private const TAIL_BYTES = 4 * 1024 * 1024;
    private const FOLLOW_BYTES = 1024 * 1024;

    private const LINE = '/^(\S+) (\S+) (\S+) ?(.*)$/';
    private const MAC_IN_TEXT = '/\bMAC:\s*((?:[0-9A-Fa-f]{2}[:-]?){5}[0-9A-Fa-f]{2})/';

    public static function file(): string
    {
        return getenv('AIPBX_PHONE_LOG') ?: self::FILE;
    }

    /**
     * The newest matching lines ($cursor null), or the matching lines written
     * after $cursor (['ino' => …, 'pos' => …] of an earlier answer).
     *
     * @return array{lines: list<array{time: string, ip: string, severity: string, message: string, mac: string}>,
     *               cursor: array{ino: int, pos: int}, exists: bool}
     */
    public static function read(string $who = '', string $text = '', ?array $cursor = null): array
    {
        $file = self::file();
        clearstatcache(true, $file);
        $st = @stat($file);
        if ($st === false) {
            return ['lines' => [], 'cursor' => ['ino' => 0, 'pos' => 0], 'exists' => false];
        }
        $size = (int) $st['size'];
        $ino = (int) $st['ino'];

        if ($cursor !== null && (int) ($cursor['ino'] ?? 0) === $ino && (int) ($cursor['pos'] ?? 0) <= $size) {
            $from = (int) $cursor['pos'];
            $from = max($from, $size - self::FOLLOW_BYTES);
            $max = 0;                                      // everything new
        } else {
            // First call, or the file was rotated / emptied since.
            $from = max(0, $size - ($cursor === null ? self::TAIL_BYTES : self::FOLLOW_BYTES));
            $max = $cursor === null ? self::MAX_LINES : 0;
        }
        $raw = self::readRange($file, $from, $size);
        $readEnd = $from + strlen($raw);
        // Only whole lines: a line still being written comes with the next call.
        $lastNl = strrpos($raw, "\n");
        if ($lastNl === false) {
            return ['lines' => [], 'cursor' => ['ino' => $ino, 'pos' => $from], 'exists' => true];
        }
        $end = $readEnd - (strlen($raw) - $lastNl - 1);
        $raw = substr($raw, 0, $lastNl);
        if ($from > 0 && ($cursor === null || $from !== (int) ($cursor['pos'] ?? -1))) {
            $nl = strpos($raw, "\n");                     // drop the cut first line
            $raw = $nl === false ? '' : substr($raw, $nl + 1);
        }

        $lines = [];
        foreach ($raw === '' ? [] : explode("\n", $raw) as $l) {
            if (preg_match(self::LINE, $l, $m)) {
                $lines[] = ['time' => $m[1], 'ip' => $m[2], 'severity' => $m[3], 'message' => $m[4], 'mac' => self::macInText($m[4])];
            }
        }
        $lines = self::filter($lines, $who, $text);
        if ($max > 0 && count($lines) > $max) {
            $lines = array_slice($lines, -$max);
        }
        return ['lines' => $lines, 'cursor' => ['ino' => $ino, 'pos' => $end], 'exists' => true];
    }

    /**
     * @param list<array{time: string, ip: string, severity: string, message: string, mac: string}> $lines
     * @return list<array{time: string, ip: string, severity: string, message: string, mac: string}>
     */
    public static function filter(array $lines, string $who, string $text): array
    {
        $who = trim($who);
        $text = trim($text);
        // IP address → MAC, from the lines themselves (NEC names its MAC in every line).
        $macOfIp = [];
        foreach ($lines as $l) {
            if ($l['mac'] !== '') {
                $macOfIp[$l['ip']] = $l['mac'];
            }
        }
        foreach ($lines as &$l) {
            if ($l['mac'] === '' && isset($macOfIp[$l['ip']])) {
                $l['mac'] = $macOfIp[$l['ip']];
            }
        }
        unset($l);

        $ip = filter_var($who, FILTER_VALIDATE_IP) !== false ? $who : '';
        $mac = $ip === '' ? PhoneModels::normalizeMac($who) : '';
        $ips = $mac !== '' ? array_flip(self::ipsOfMac($mac)) : [];

        return array_values(array_filter($lines, function (array $l) use ($who, $ip, $mac, $ips, $text): bool {
            if ($ip !== '' && $l['ip'] !== $ip) {
                return false;
            }
            if ($mac !== '' && $l['mac'] !== $mac && !isset($ips[$l['ip']])) {
                return false;
            }
            if ($who !== '' && $ip === '' && $mac === '' && stripos($l['ip'], $who) === false) {
                return false;                       // part of an address, e.g. "10.12.3."
            }
            return $text === '' || stripos($l['message'], $text) !== false;
        }));
    }

    /** Tabs of the page besides the syslog. */
    public const SOURCES = ['http', 'ftp', 'tftp', 'dhcp'];

    /**
     * The newest events of one source, newest first, filtered like the syslog
     * (IP or MAC address, text).
     *
     * @return list<array{time: string, ip: string, mac: string, result: string, ok: bool, file: string, detail: string}>
     */
    public static function events(string $source, string $who = '', string $text = '', ?string $raw = null): array
    {
        if ($source === 'http') {
            $rows = [];
            foreach (PhoneProvisionService::recentLog(500) as $r) {
                $rows[] = ['time' => date('c', strtotime((string) $r['created_at'])), 'ip' => (string) $r['ip'],
                    'mac' => (string) $r['mac'], 'result' => (string) $r['result'], 'ok' => $r['result'] === 'served',
                    'file' => (string) $r['file'], 'detail' => (string) ($r['user_agent'] ?? '')];
            }
        } else {
            if ($raw === null) {
                $res = PrivHelper::run(['netsvc', 'log', $source]);
                $raw = $res['success'] ? (string) $res['output'] : '';
            }
            $parse = match ($source) {
                'ftp' => self::parseFtp(...),
                'tftp' => self::parseTftp(...),
                'dhcp' => self::parseDhcp(...),
                default => fn(string $l) => null,
            };
            $rows = [];
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                $row = $parse($line);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            $rows = array_reverse(array_slice($rows, -500));
        }
        return self::filterEvents($rows, $who, $text);
    }

    /**
     * vsftpd transfer log (times in the server's local time zone):
     *   Thu Oct 15 11:31:12 2026 [pid 824877] [NECSDT700] OK DOWNLOAD: Client "10.23.3.70", "/dt-0060b98cb528.cfg", 1265 bytes, 3676.64Kbyte/sec
     *   Thu Oct 15 11:31:12 2026 [pid 824875] [NECSDT700] FAIL LOGIN: Client "10.12.3.90"
     * CONNECT lines are left out (one per session, no information).
     */
    public static function parseFtp(string $line, ?\DateTimeZone $tz = null): ?array
    {
        if (!preg_match('/^(\w{3} \w{3} +\d{1,2} \d\d:\d\d:\d\d \d{4}) \[pid \d+\] (?:\[([^\]]*)\] )?(OK|FAIL) ([A-Z]+): Client "([^"]+)"(?:, "([^"]*)")?(.*)$/', trim($line), $m)) {
            return null;
        }
        $t = \DateTimeImmutable::createFromFormat('D M j H:i:s Y', (string) preg_replace('/ +/', ' ', $m[1]), $tz ?? self::systemTimezone());
        $file = ltrim($m[6], '/');
        return ['time' => $t ? $t->format('c') : $m[1],
            'ip' => $m[5], 'mac' => self::macInFile($file), 'result' => $m[3] . ' ' . $m[4], 'ok' => $m[3] === 'OK',
            'file' => $file, 'detail' => trim(($m[2] !== '' ? 'user ' . $m[2] : '') . ' ' . trim((string) $m[7], ', '))];
    }

    /**
     * dnsmasq TFTP (journalctl -o short-iso):
     *   2026-10-15T13:39:43+0200 host dnsmasq-tftp[1]: sent /var/lib/aipbx/tftp/a.cfg to 10.1.2.3
     *   ... dnsmasq-tftp[1]: file /var/lib/aipbx/tftp/b.cfg not found for 10.1.2.3
     *   ... dnsmasq-tftp[1]: error 0 Early terminate received from 10.1.2.3
     */
    public static function parseTftp(string $line): ?array
    {
        if (!preg_match('/^(\S+) \S+ dnsmasq-tftp\[\d+\]: (.*)$/', trim($line), $m)) {
            return null;
        }
        $msg = $m[2];
        if (preg_match('#^sent (\S+) to (\S+)$#', $msg, $x)) {
            [$result, $ok, $file, $ip] = ['sent', true, $x[1], $x[2]];
        } elseif (preg_match('#^file (\S+) not found(?: for (\S+))?#', $msg, $x)) {
            [$result, $ok, $file, $ip] = ['not found', false, $x[1], $x[2] ?? ''];
        } elseif (preg_match('#^(?:error|failed|cannot)\b.*?(\d{1,3}(?:\.\d{1,3}){3})#', $msg, $x)) {
            [$result, $ok, $file, $ip] = ['error', false, '', $x[1]];
        } else {
            return null;                           // "TFTP root is ...", start-up lines
        }
        $file = basename($file);
        return ['time' => self::isoTime($m[1]), 'ip' => $ip, 'mac' => self::macInFile($file), 'result' => $result,
            'ok' => $ok, 'file' => $file, 'detail' => $ok ? '' : $msg];
    }

    /**
     * dnsmasq DHCP (journalctl -o short-iso):
     *   ... dnsmasq-dhcp[1]: DHCPDISCOVER(eth1) 00:60:b9:ef:8b:1f
     *   ... dnsmasq-dhcp[1]: DHCPACK(eth1) 192.168.10.50 00:60:b9:ef:8b:1f DT710
     */
    public static function parseDhcp(string $line): ?array
    {
        if (!preg_match('/^(\S+) \S+ dnsmasq-dhcp\[\d+\]: (DHCP[A-Z]+)\(([^)]*)\) ?(.*)$/', trim($line), $m)) {
            return null;
        }
        $ip = preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $m[4], $x) ? $x[1] : '';
        $mac = preg_match('/\b((?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2})\b/', $m[4], $y) ? PhoneModels::normalizeMac($y[1]) : '';
        return ['time' => self::isoTime($m[1]), 'ip' => $ip, 'mac' => $mac, 'result' => $m[2],
            'ok' => !in_array($m[2], ['DHCPNAK', 'DHCPDECLINE'], true), 'file' => '', 'detail' => trim($m[3] . ' ' . $m[4])];
    }

    /**
     * The server's own time zone (/etc/localtime), which vsftpd writes in. It
     * can differ from PHP's zone (the portal's setting).
     */
    private static function systemTimezone(): \DateTimeZone
    {
        $link = (string) @readlink('/etc/localtime');
        if (preg_match('#zoneinfo/(.+)$#', $link, $m)) {
            try {
                return new \DateTimeZone($m[1]);
            } catch (\Exception $e) {
            }
        }
        return new \DateTimeZone(date_default_timezone_get());
    }

    /** journalctl's short-iso time ("2026-10-15T13:39:43+02:00", older: "+0200") as ISO 8601. */
    private static function isoTime(string $t): string
    {
        try {
            return (new \DateTimeImmutable($t))->format('c');
        } catch (\Exception $e) {
            return $t;
        }
    }

    /** The MAC in a phone's file name (dt-0060b9ef609f-phone.cfg, 001565aabbcc.cfg), or ''. */
    public static function macInFile(string $file): string
    {
        return preg_match('/(?<![0-9a-f])([0-9a-f]{12})(?![0-9a-f])/i', $file, $m) && $m[1] !== '000000000000'
            ? PhoneModels::normalizeMac($m[1]) : '';
    }

    /**
     * @param list<array{time: string, ip: string, mac: string, result: string, ok: bool, file: string, detail: string}> $rows
     * @return list<array{time: string, ip: string, mac: string, result: string, ok: bool, file: string, detail: string}>
     */
    public static function filterEvents(array $rows, string $who, string $text): array
    {
        $who = trim($who);
        $text = trim($text);
        $ip = filter_var($who, FILTER_VALIDATE_IP) !== false ? $who : '';
        $mac = $ip === '' ? PhoneModels::normalizeMac($who) : '';
        $ips = $mac !== '' ? array_flip(self::ipsOfMac($mac)) : [];
        return array_values(array_filter($rows, function (array $r) use ($who, $ip, $mac, $ips, $text): bool {
            if ($ip !== '' && $r['ip'] !== $ip) {
                return false;
            }
            if ($mac !== '' && $r['mac'] !== $mac && !isset($ips[$r['ip']])) {
                return false;
            }
            if ($who !== '' && $ip === '' && $mac === '' && stripos($r['ip'], $who) === false) {
                return false;
            }
            return $text === '' || stripos($r['result'] . ' ' . $r['file'] . ' ' . $r['detail'], $text) !== false;
        }));
    }

    /** The MAC a message names ("MAC:0060B9EF8B1F", "MAC: 00:60:B9:EF:8B:1F"), lowercase 12 hex, or ''. */
    public static function macInText(string $message): string
    {
        return preg_match(self::MAC_IN_TEXT, $message, $m) ? PhoneModels::normalizeMac($m[1]) : '';
    }

    /**
     * Addresses the phone with this MAC was seen at.
     *
     * @return string[]
     */
    public static function ipsOfMac(string $mac): array
    {
        $db = getDB();
        $ips = [];
        foreach ([
            'SELECT last_ip FROM pbx_phones WHERE mac = ? AND last_ip IS NOT NULL',
            'SELECT ip FROM pbx_phones_waiting WHERE mac = ?',
            'SELECT DISTINCT ip FROM pbx_phone_fetch_log WHERE mac = ? AND created_at > NOW() - INTERVAL 30 DAY',
        ] as $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$mac]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ip) {
                if ($ip !== '' && $ip !== null) {
                    $ips[] = (string) $ip;
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Known phones for the labels: MAC → "1001 · Yealink T46U".
     *
     * @return array<string, string>
     */
    public static function phoneLabels(): array
    {
        $out = [];
        $rows = getDB()->query('SELECT p.mac, p.model, u.extension FROM pbx_phones p LEFT JOIN sys_users u ON u.id = p.user_id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $label = PhoneModels::get((string) $r['model'])['label'] ?? (string) $r['model'];
            $out[$r['mac']] = ($r['extension'] ? $r['extension'] . ' · ' : '') . $label;
        }
        return $out;
    }

    private static function readRange(string $file, int $from, int $to): string
    {
        if ($to <= $from) {
            return '';
        }
        $f = @fopen($file, 'rb');
        if ($f === false) {
            return '';
        }
        fseek($f, $from);
        $data = (string) fread($f, $to - $from);
        fclose($f);
        return $data;
    }
}
