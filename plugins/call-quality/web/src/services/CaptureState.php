<?php
/**
 * Is Asterisk sending RTCP to *this* server's capture daemon?
 *
 * Call quality can only be computed from rows that land in
 * `pbx_signaling_events`, and whether they land is decided outside the portal:
 * Asterisk's `hep.conf` says where every HEP datagram goes. Point it at a
 * HOMER or VoIPmonitor collector and those reports are stored there, not here
 * — reading nothing and calling the lines healthy would be a lie.
 *
 * So this asks the file, which is the only source that knows:
 *
 *   [general]
 *   enabled = yes
 *   capture_address = 127.0.0.1:9061
 *
 * Local means: capture is on, the address is loopback, and the port is the one
 * `aipbx-signaling` listens on (`AIPBX_SIGNALING_LISTEN`, default
 * 127.0.0.1:9061). A collector on loopback at another port still receives the
 * datagrams instead of our daemon, so the port is part of the answer.
 *
 * Why the file and not a settings class: the fork this package came from had a
 * `HepSettingsService` that *writes* this same file, so reading it works on
 * both builds and there is no class to depend on. Asterisk's own syntax is
 * followed far enough to stay honest — comments, `#include`/`#tryinclude`,
 * quoted values — and nothing beyond it.
 */
final class CaptureState
{
    /** `aipbx-signaling`'s default listener (conf/systemd/aipbx-signaling.service). */
    public const DAEMON_HOST = '127.0.0.1';
    public const DAEMON_PORT = 9061;

    /** Where `hep.conf` lives: the portal constant, with an env override for tests. */
    public static function confPath(): string
    {
        $override = getenv('AIPBX_HEP_CONF');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $dir = defined('ASTERISK_CONF_DIR') ? (string) ASTERISK_CONF_DIR : '/etc/asterisk';
        return rtrim($dir, '/') . '/hep.conf';
    }

    /**
     * Everything a page, a notice or an operator needs to explain the answer.
     *
     * @return array{mode: string, enabled: ?bool, address: string, host: string,
     *               port: int, listener: string, local: bool, path: string,
     *               readable: bool, includes: array<int, string>, detail: string}
     */
    public static function snapshot(?string $path = null): array
    {
        $path = $path ?? self::confPath();
        $parsed = self::readConf($path);
        $enabled = self::bool($parsed['enabled']);
        $address = $parsed['capture_address'];
        [$host, $port] = self::splitAddress($address);
        $listener = self::listener();
        $local = $enabled === true && self::isLoopback($host) && $port === $listener['port'];

        $mode = $enabled === null ? 'unknown' : ($enabled === false ? 'off' : ($local ? 'builtin' : 'external'));

        $snapshot = [
            'mode' => $mode,
            'enabled' => $enabled,
            'address' => $address,
            'host' => $host,
            'port' => $port,
            'listener' => $listener['host'] . ':' . $listener['port'],
            'local' => $local,
            'path' => $path,
            'readable' => $parsed['readable'],
            'includes' => $parsed['includes'],
        ];
        $snapshot['detail'] = self::detail($snapshot);

        return $snapshot;
    }

    /** True when RTCP rows reach this server's own daemon. */
    public static function local(?string $path = null): bool
    {
        return self::snapshot($path)['local'];
    }

    /** One English sentence for a page, a notice or a CLI line. */
    public static function describe(?string $path = null): string
    {
        return self::snapshot($path)['detail'];
    }

    /**
     * @param array{mode: string, enabled: ?bool, address: string, host: string,
     *              port: int, listener: string, path: string, readable: bool} $s
     */
    private static function detail(array $s): string
    {
        if (!$s['readable']) {
            return sprintf(
                'No readable HEP configuration at %s, so whether RTCP reaches this server is unknown.',
                $s['path']
            );
        }
        return match ($s['mode']) {
            'builtin' => sprintf(
                'Asterisk sends HEP to %s, this server\'s own capture daemon, so RTCP counters are stored locally.',
                $s['address'] !== '' ? $s['address'] : $s['listener']
            ),
            'external' => sprintf(
                'Asterisk sends HEP to %s; the portal\'s daemon does not receive a copy, so there are no local RTCP counters to score. Capture is on — just not here.',
                $s['address'] !== '' ? $s['address'] : '(no capture_address)'
            ),
            'off' => sprintf(
                'HEP capture is switched off in %s (enabled = no), so no RTCP reports are stored.',
                $s['path']
            ),
            'unknown' => sprintf(
                'HEP capture is configured in %s but its enabled value is missing or not yes/no, so whether RTCP reaches this server is unknown.',
                $s['path']
            ),
            default => sprintf(
                'HEP capture is switched on in %s but the destination is not this server\'s daemon (%s while %s listens), so local RTCP counters are not expected.',
                $s['path'],
                $s['address'] !== '' ? $s['address'] : '(no capture_address)',
                $s['listener']
            ),
        };
    }

    /**
     * The address the bundled daemon listens on. Mirrors the daemon's own
     * `parse_listen()`: a bare port means loopback, a bare host means 9061.
     *
     * @return array{host: string, port: int}
     */
    private static function listener(): array
    {
        $value = (string) (getenv('AIPBX_SIGNALING_LISTEN') ?: '');
        if ($value === '') {
            return ['host' => self::DAEMON_HOST, 'port' => self::DAEMON_PORT];
        }
        if (strpos($value, ':') === false) {
            return ctype_digit($value)
                ? ['host' => self::DAEMON_HOST, 'port' => (int) $value]
                : ['host' => $value, 'port' => self::DAEMON_PORT];
        }
        $pos = strrpos($value, ':');
        $host = substr($value, 0, (int) $pos);
        $port = (int) substr($value, (int) $pos + 1);
        return [
            'host' => $host !== '' ? $host : self::DAEMON_HOST,
            'port' => $port > 0 ? $port : self::DAEMON_PORT,
        ];
    }

    /** "1.2.3.4:9061" or "[2001:db8::1]:9061" -> host and port. */
    private static function splitAddress(string $address): array
    {
        $address = trim($address);
        if ($address === '') {
            return ['', 0];
        }
        if ($address[0] === '[') {
            $end = strpos($address, ']');
            if ($end === false) {
                return [$address, 0];
            }
            $host = substr($address, 1, $end - 1);
            $rest = ltrim(substr($address, $end + 1), ':');
            return [$host, ctype_digit($rest) ? (int) $rest : 0];
        }
        if (substr_count($address, ':') === 1) {
            [$host, $port] = explode(':', $address, 2);
            return [$host, ctype_digit($port) ? (int) $port : 0];
        }
        // A bare host, or a bare IPv6 address we will not guess a port for.
        return [$address, 0];
    }

    private static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || str_starts_with($host, '127.');
    }

    private static function bool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));
        if (in_array($value, ['yes', 'true', '1', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['no', 'false', '0', 'off'], true)) {
            return false;
        }
        return null;
    }

    /**
     * The `[general]` values of one Asterisk config file, includes followed.
     *
     * Asterisk reads `#include` (missing file is fatal) and `#tryinclude`
     * (missing file is fine). Later values win, so an included file overrides
     * the one that pulled it in — which is why they are followed rather than
     * ignored.
     *
     * @return array{enabled: ?string, capture_address: string, readable: bool, includes: array<int, string>}
     */
    private static function readConf(string $path, int $depth = 0, array &$seen = []): array
    {
        $out = ['enabled' => null, 'capture_address' => '', 'readable' => false, 'includes' => []];
        $real = realpath($path);
        if ($real === false || !is_readable($real) || isset($seen[$real])) {
            return $out;
        }
        $seen[$real] = true;
        $out['readable'] = true;

        $section = '';
        foreach ((array) @file($real, FILE_IGNORE_NEW_LINES) as $raw) {
            $line = trim((string) $raw);
            if ($line === '' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^#(?:try)?include\s+"?([^"\\s]+)"?/i', $line, $m) === 1) {
                foreach (self::expandInclude(dirname($real), $m[1]) as $included) {
                    $out['includes'][] = $included;
                    if ($depth < 8) {
                        $sub = self::readConf($included, $depth + 1, $seen);
                        if ($sub['enabled'] !== null) {
                            $out['enabled'] = $sub['enabled'];
                        }
                        if ($sub['capture_address'] !== '') {
                            $out['capture_address'] = $sub['capture_address'];
                        }
                    }
                }
                continue;
            }
            // Any other '#' line is a comment in Asterisk's config format.
            if ($line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m) === 1) {
                $section = strtolower(trim($m[1]));
                continue;
            }
            if ($section !== 'general' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = strtolower(trim($key));
            $value = trim((string) preg_replace('/\s*;.*$/', '', $value));
            $value = trim($value, " \t\"'");
            if ($key === 'enabled' || $key === 'capture_address') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @return array<int, string> absolute paths a directive matches */
    private static function expandInclude(string $base, string $pattern): array
    {
        $pattern = trim($pattern, "\"'");
        if ($pattern === '') {
            return [];
        }
        if ($pattern[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $pattern)) {
            $pattern = rtrim($base, '/') . '/' . $pattern;
        }
        if (strpbrk($pattern, '*?[') === false) {
            return is_file($pattern) ? [$pattern] : [];
        }
        return array_values(array_filter((array) glob($pattern), 'is_file'));
    }
}
