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
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once __DIR__ . '/phones/PhoneModels.php';

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
        return ['lines' => array_values($lines), 'cursor' => ['ino' => $ino, 'pos' => $end], 'exists' => true];
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
