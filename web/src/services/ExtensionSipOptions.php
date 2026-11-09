<?php

/**
 * The per-extension SIP settings of the Extensions page. They apply to the extension's SIP object
 * only: browser and mobile objects keep their own. Kept in the `sip` table under adv_ names, as older
 * rows there hold values for the same options that were never meant to reach a desk phone.
 *
 * Empty means the template's value. Every choice comes from a fixed list, because a wrong option
 * stays silent until the next restart and then takes the endpoint away.
 */
final class ExtensionSipOptions
{
    private const PREFIX = 'adv_';

    public const TRANSPORTS = ['udp', 'tcp', 'tls'];
    public const CODECS = ['opus', 'g722', 'alaw', 'ulaw', 'g729'];
    public const DTMF = ['rfc4733', 'inband', 'info', 'auto'];
    public const SRTP = ['no', 'sdes', 'dtls'];
    public const YES_NO = ['yes', 'no'];

    private const CHOICES = [
        'transport' => self::TRANSPORTS,
        'dtmf_mode' => self::DTMF,
        'media_encryption' => self::SRTP,
        'media_encryption_optimistic' => self::YES_NO,
        'force_rport' => self::YES_NO,
        'rewrite_contact' => self::YES_NO,
        'rtp_symmetric' => self::YES_NO,
        'direct_media' => self::YES_NO,
        'ice_support' => self::YES_NO,
    ];

    /**
     * The form's values, checked; '' for what is left to the template.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function fromPost(array $post): array
    {
        $out = [];
        foreach (self::CHOICES as $name => $allowed) {
            $value = (string) ($post[self::PREFIX . $name] ?? '');
            $out[$name] = in_array($value, $allowed, true) ? $value : '';
        }
        // The form's order is the codec order.
        $codecs = array_map('trim', explode(',', (string) ($post[self::PREFIX . 'allow'] ?? '')));
        $out['allow'] = implode(',', array_values(array_unique(array_intersect($codecs, self::CODECS))));
        $language = (string) ($post[self::PREFIX . 'language'] ?? '');
        $out['language'] = preg_match('/^[a-z]{2}(_[A-Z]{2})?$/D', $language) === 1 ? $language : '';
        $qualify = trim((string) ($post[self::PREFIX . 'qualify_frequency'] ?? ''));
        $out['qualify_frequency'] = ctype_digit($qualify) && (int) $qualify <= 3600 ? (string) (int) $qualify : '';
        $mediaAddr = trim((string) ($post[self::PREFIX . 'media_address'] ?? ''));
        $out['media_address'] = (filter_var($mediaAddr, FILTER_VALIDATE_IP) !== false || preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D', $mediaAddr) === 1) ? $mediaAddr : '';
        $rtpTimeout = trim((string) ($post[self::PREFIX . 'rtp_timeout'] ?? ''));
        $out['rtp_timeout'] = ctype_digit($rtpTimeout) && (int) $rtpTimeout <= 3600 ? (string) (int) $rtpTimeout : '';
        $rtpKeepalive = trim((string) ($post[self::PREFIX . 'rtp_keepalive'] ?? ''));
        $out['rtp_keepalive'] = ctype_digit($rtpKeepalive) && (int) $rtpKeepalive <= 3600 ? (string) (int) $rtpKeepalive : '';
        return $out;
    }

    /** @param array<string, mixed> $post */
    public static function save(string $extension, array $post): void
    {
        $values = self::fromPost($post);
        $set = [];
        $clear = [];
        foreach ($values as $name => $value) {
            if ($value === '') {
                $clear[] = self::PREFIX . $name;
            } else {
                $set[self::PREFIX . $name] = $value;
            }
        }
        SIPHelper::setSettings($extension, $set);
        SIPHelper::deleteKeys($extension, $clear);
    }

    public static function isOwnKey(string $keyword): bool
    {
        return strncmp($keyword, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Values of every extension, for the Extensions page.
     *
     * @return array<string, array<string, string>> extension => adv_name => value
     */
    public static function allStored(): array
    {
        $out = [];
        foreach (getDB()->query("SELECT id, keyword, data FROM sip WHERE keyword LIKE 'adv\\_%' OR keyword = 'max_contacts'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['id']][(string) $row['keyword']] = (string) $row['data'];
        }
        return $out;
    }

    /**
     * Lines for the extension's SIP endpoint. The stored values are checked again, as a row can come
     * from a restored backup.
     *
     * @param array<string, string> $sip the extension's row of the `sip` table
     */
    public static function endpointLines(array $sip): string
    {
        $stored = [];
        foreach ($sip as $key => $value) {
            if (self::isOwnKey((string) $key)) {
                $stored[(string) $key] = $value;
            }
        }
        $v = self::fromPost($stored);
        $conf = '';
        if ($v['transport'] !== '') {
            $conf .= "transport=transport-{$v['transport']}\n";
        }
        if ($v['allow'] !== '') {
            // allow= adds to what the template gave, so the list starts again.
            $conf .= "disallow=all\nallow={$v['allow']}\n";
        }
        foreach (['dtmf_mode', 'media_encryption', 'media_encryption_optimistic', 'force_rport', 'rewrite_contact', 'rtp_symmetric', 'direct_media', 'ice_support', 'language', 'media_address', 'rtp_timeout', 'rtp_keepalive'] as $name) {
            if ($v[$name] !== '') {
                $conf .= "{$name}={$v[$name]}\n";
            }
        }
        return $conf;
    }

    /** @param array<string, string> $sip */
    public static function qualifyFrequency(array $sip): string
    {
        return self::fromPost([self::PREFIX . 'qualify_frequency' => $sip[self::PREFIX . 'qualify_frequency'] ?? ''])['qualify_frequency'];
    }
}
