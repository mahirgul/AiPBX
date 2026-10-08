<?php
/**
 * Reads the e-mail Asterisk builds for a new voicemail (see bin/voicemail_mail.php).
 * Asterisk's emailbody is set to "AIPBX-VM" plus key=value lines
 * (SyncVoicemail::generalConf), so the details are read from there, not from
 * a localised text.
 */
class VoicemailMailParser
{
    const MARKER = 'AIPBX-VM';

    /**
     * @return array{to: string, info: array<string, string>, attachment: ?array{name: string, type: string, data: string}}|null
     */
    public static function parse(string $raw): ?array
    {
        [$head, $body] = self::split($raw);
        $headers = self::headers($head);
        $to = self::address($headers['to'] ?? '');
        if ($to === '') {
            return null;
        }

        $info = null;
        $attachment = null;
        $type = $headers['content-type'] ?? 'text/plain';
        if (preg_match('/boundary="?([^";]+)"?/i', $type, $m)) {
            foreach (self::parts($body, $m[1]) as $part) {
                [$ph, $pb] = self::split($part);
                $h = self::headers($ph);
                $data = self::decode($pb, $h['content-transfer-encoding'] ?? '');
                $ptype = strtolower(trim(explode(';', $h['content-type'] ?? 'text/plain')[0]));
                if ($info === null && str_starts_with($ptype, 'text/') && str_contains($data, self::MARKER)) {
                    $info = self::info($data);
                } elseif ($attachment === null && (str_starts_with($ptype, 'audio/') || stripos($h['content-disposition'] ?? '', 'attachment') !== false)) {
                    $name = 'voicemail.wav';
                    if (preg_match('/filename="?([^";]+)"?/i', ($h['content-disposition'] ?? '') . ';' . ($h['content-type'] ?? ''), $fm)) {
                        $name = basename(trim($fm[1]));
                    }
                    $attachment = ['name' => $name, 'type' => $ptype ?: 'audio/x-wav', 'data' => $data];
                }
            }
        } else {
            $data = self::decode($body, $headers['content-transfer-encoding'] ?? '');
            if (str_contains($data, self::MARKER)) {
                $info = self::info($data);
            }
        }
        if ($info === null || ($info['mailbox'] ?? '') === '') {
            return null;
        }
        return ['to' => $to, 'info' => $info, 'attachment' => $attachment];
    }

    /** Template values from the details (and the mailbox owner, when known). */
    public static function templateVars(array $info, ?array $user): array
    {
        // Asterisk writes "an unknown caller" into both fields when there is no caller ID.
        $unknown = fn(string $v): string => in_array(strtolower($v), ['unknown', 'an unknown caller', 'anonymous'], true) ? '' : $v;
        $cidName = $unknown(trim($info['cidname'] ?? ''));
        $cidNum = $unknown(trim($info['cidnum'] ?? ''));
        $caller = $cidName !== '' && $cidNum !== '' && $cidName !== $cidNum
            ? "{$cidName} <{$cidNum}>"
            : ($cidNum !== '' ? $cidNum : ($cidName !== '' ? $cidName : trim($info['callerid'] ?? '')));
        $name = $user ? (string) (($user['full_name'] ?? '') ?: ($user['username'] ?? '')) : trim($info['name'] ?? '');
        return [
            'name' => $name !== '' ? $name : (string) ($info['mailbox'] ?? ''),
            'mailbox' => (string) ($info['mailbox'] ?? ''),
            'caller' => $unknown($caller),
            'caller_name' => $cidName,
            'caller_number' => $cidNum,
            'date' => trim($info['date'] ?? ''),
            'duration' => trim($info['duration'] ?? ''),
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $s): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $s, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** @return array<string, string> lower-case name => value (folded lines joined) */
    private static function headers(string $head): array
    {
        $out = [];
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
        foreach (preg_split('/\r?\n/', $head) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }
        return $out;
    }

    private static function address(string $to): string
    {
        if (preg_match('/<([^>]+)>/', $to, $m)) {
            $to = $m[1];
        }
        $to = trim($to);
        return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $out = [];
        foreach (explode('--' . $boundary, $body) as $i => $chunk) {
            if ($i === 0 || str_starts_with($chunk, '--')) {
                continue;
            }
            $out[] = ltrim($chunk, "\r\n");
        }
        return $out;
    }

    private static function decode(string $data, string $encoding): string
    {
        $encoding = strtolower(trim($encoding));
        if ($encoding === 'base64') {
            return (string) base64_decode(preg_replace('/\s+/', '', $data));
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($data);
        }
        return $data;
    }

    /** @return array<string, string> */
    private static function info(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', substr($text, strpos($text, self::MARKER))) as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', trim($line), $m)) {
                $out[$m[1]] = trim($m[2]);
            }
        }
        return $out;
    }
}
