<?php
require_once __DIR__ . '/../priv_helper.php';

/**
 * Certificates page: the TLS certificate shared by the portal (Apache),
 * TURNS (coturn) and SIP-TLS/WSS (Asterisk).
 *
 * Root work (certbot, installing files, reloading services) is done by
 * /usr/local/sbin/aipbx-cert (source: conf/sbin/aipbx-cert) through
 * `aipbx-priv cert ...`. Reading is done here: the certificates themselves
 * are public and world-readable; private keys are never read back.
 */
class CertificateService
{
    public const ACTIVE_CRT = '/etc/ssl/aipbx/active.crt';
    public const MODE_FILE = '/etc/ssl/aipbx/mode';
    public const COTURN_CRT = '/etc/coturn/aipbx.crt';
    public const ASTERISK_CRT = '/etc/asterisk/keys/fullchain.pem';
    public const STAGE_DIR = '/var/lib/aipbx/cert-stage';
    public const STATUS_FILE = '/var/lib/aipbx/cert-status.json';
    public const CERTBOT = '/usr/bin/certbot';
    /** Let's Encrypt renews 30 days before expiry; warn when that did not happen. */
    public const WARN_DAYS = 21;
    public const MAX_UPLOAD = 65536;

    public static function domain(): string
    {
        return strtolower(trim((string) portalEnv('PORTAL_DOMAIN', '')));
    }

    /** Let's Encrypt only issues for public DNS names. */
    public static function letsEncryptPossible(string $domain): bool
    {
        return $domain !== ''
            && !str_ends_with($domain, '.local')
            && filter_var($domain, FILTER_VALIDATE_IP) === false
            && str_contains($domain, '.');
    }

    // ------------------------------------------------------------------
    // Parsing (pure, unit tested)
    // ------------------------------------------------------------------

    /** @return string[] PEM blocks of every certificate in $pem, in file order */
    public static function pemBlocks(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+?-----END CERTIFICATE-----/', $pem, $m);
        return $m[0];
    }

    /**
     * One entry per readable certificate, in file order (leaf first in a chain).
     * @return list<array<string, mixed>>
     */
    public static function parseChain(string $pem): array
    {
        $out = [];
        foreach (self::pemBlocks($pem) as $block) {
            $x = @openssl_x509_read($block);
            if ($x === false) {
                continue;
            }
            $p = openssl_x509_parse($x);
            if (!is_array($p)) {
                continue;
            }
            $san = [];
            foreach (explode(',', (string) ($p['extensions']['subjectAltName'] ?? '')) as $entry) {
                $entry = trim($entry);
                if (preg_match('/^(DNS|IP Address):(.+)$/', $entry, $m)) {
                    $san[] = ['type' => $m[1] === 'DNS' ? 'dns' : 'ip', 'value' => strtolower(trim($m[2]))];
                }
            }
            $subject = self::dn($p['subject'] ?? []);
            $issuer = self::dn($p['issuer'] ?? []);
            $out[] = [
                'subject_cn' => self::first($p['subject']['CN'] ?? ''),
                'subject_o' => self::first($p['subject']['O'] ?? ''),
                'issuer_cn' => self::first($p['issuer']['CN'] ?? ''),
                'issuer_o' => self::first($p['issuer']['O'] ?? ''),
                'subject' => $subject,
                'issuer' => $issuer,
                'self_signed' => $subject === $issuer,
                'san' => $san,
                'not_before' => (int) ($p['validFrom_time_t'] ?? 0),
                'not_after' => (int) ($p['validTo_time_t'] ?? 0),
                'fingerprint' => (string) openssl_x509_fingerprint($x, 'sha256'),
                'pem' => $block,
            ];
        }
        return $out;
    }

    /** RFC 6125 style match: SAN entries (one-label wildcards), CN only without SAN. */
    public static function hostMatches(array $cert, string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return false;
        }
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $names = $cert['san'];
        if (!$names && ($cert['subject_cn'] ?? '') !== '') {
            $names = [['type' => 'dns', 'value' => strtolower($cert['subject_cn'])]];
        }
        foreach ($names as $n) {
            if ($isIp) {
                if ($n['type'] === 'ip' && inet_pton($n['value']) === inet_pton($host)) {
                    return true;
                }
                continue;
            }
            if ($n['type'] !== 'dns') {
                continue;
            }
            $pattern = rtrim($n['value'], '.');
            if ($pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);   // ".example.com"
                $label = substr($host, 0, -strlen($suffix));
                if (str_ends_with($host, $suffix) && $label !== '' && !str_contains($label, '.')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * What the page shows about a chain: validity, domain, trust problems.
     * $issues are translation key suffixes (certificates.issue_*).
     *
     * @param list<array<string, mixed>> $chain
     * @return array<string, mixed>
     */
    public static function summarize(array $chain, string $domain, ?int $now = null): array
    {
        $now ??= time();
        if (!$chain) {
            return ['present' => false, 'level' => 'danger', 'issues' => ['missing'], 'days_left' => null];
        }
        $leaf = $chain[0];
        $daysLeft = (int) floor(($leaf['not_after'] - $now) / 86400);
        $issues = [];
        if ($leaf['not_after'] <= $now) {
            $issues[] = 'expired';
        } elseif ($daysLeft < self::WARN_DAYS) {
            $issues[] = 'expires_soon';
        }
        if ($leaf['not_before'] > $now) {
            $issues[] = 'not_yet_valid';
        }
        if ($domain !== '' && !self::hostMatches($leaf, $domain)) {
            $issues[] = 'domain_mismatch';
        }
        if ($leaf['self_signed']) {
            $issues[] = 'self_signed';
        } elseif (!self::chainReachesIssuer($chain)) {
            $issues[] = 'chain_incomplete';
        }
        $danger = array_intersect($issues, ['expired', 'not_yet_valid', 'domain_mismatch', 'missing']);
        return [
            'present' => true,
            'leaf' => $leaf,
            'chain_length' => count($chain),
            'days_left' => $daysLeft,
            'issues' => $issues,
            'level' => $danger ? 'danger' : ($issues ? 'warning' : 'ok'),
        ];
    }

    /** A CA-issued leaf needs its issuer in the chain, otherwise clients without it in cache fail. */
    private static function chainReachesIssuer(array $chain): bool
    {
        if (count($chain) < 2) {
            return false;
        }
        foreach (array_slice($chain, 1) as $c) {
            if ($c['subject'] === $chain[0]['issuer']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Turns uploaded material into the files aipbx-cert installs: a PEM chain
     * (leaf first, issuers after it) and an unencrypted PEM key.
     *
     * @param string $certPem   certificate, may already contain the chain
     * @param string $chainPem  optional intermediates
     * @param string $keyPem    private key (may be encrypted with $password)
     * @param string $pfx       PKCS#12 bundle instead of the three above
     * @return array{ok: bool, errors: string[], warnings: string[], fullchain: string, key: string, leaf: ?array}
     */
    public static function buildBundle(string $certPem, string $chainPem, string $keyPem, string $pfx, string $password, string $domain, ?int $now = null): array
    {
        $now ??= time();
        $res = ['ok' => false, 'errors' => [], 'warnings' => [], 'fullchain' => '', 'key' => '', 'leaf' => null];

        $certs = [];
        $key = false;
        if ($pfx !== '') {
            $p12 = [];
            if (!@openssl_pkcs12_read($pfx, $p12, $password)) {
                $res['errors'][] = 'pfx_unreadable';
                return $res;
            }
            $certs[] = (string) $p12['cert'];
            foreach ((array) ($p12['extracerts'] ?? []) as $extra) {
                $certs[] = (string) $extra;
            }
            $key = @openssl_pkey_get_private((string) $p12['pkey']);
        } else {
            $certs = array_merge(self::pemBlocks($certPem), self::pemBlocks($chainPem));
            if ($keyPem !== '') {
                $key = @openssl_pkey_get_private($keyPem, $password);
            }
        }
        if (!$certs) {
            $res['errors'][] = 'no_certificate';
            return $res;
        }
        if ($key === false) {
            $res['errors'][] = 'key_unreadable';
            return $res;
        }

        // Normalise every certificate through OpenSSL (drops "Bag Attributes"
        // and other text around the PEM blocks).
        $parsed = [];
        foreach ($certs as $c) {
            $x = @openssl_x509_read($c);
            if ($x === false || !openssl_x509_export($x, $clean)) {
                $res['errors'][] = 'certificate_unreadable';
                return $res;
            }
            $parsed[] = ['x509' => $x, 'pem' => $clean, 'info' => self::parseChain($clean)[0] ?? null];
        }

        // The leaf is the certificate the key belongs to, wherever it is in the file.
        $leafIdx = null;
        foreach ($parsed as $i => $p) {
            if (openssl_x509_check_private_key($p['x509'], $key)) {
                $leafIdx = $i;
                break;
            }
        }
        if ($leafIdx === null) {
            $res['errors'][] = 'key_mismatch';
            return $res;
        }

        // Leaf first, then each issuer in order; self-signed roots are left
        // out (clients have their own), unrelated certificates are dropped.
        $ordered = [$parsed[$leafIdx]];
        $rest = $parsed;
        unset($rest[$leafIdx]);
        $current = $parsed[$leafIdx]['info'];
        while (!$current['self_signed']) {
            $next = null;
            foreach ($rest as $i => $p) {
                if ($p['info']['subject'] === $current['issuer'] && !$p['info']['self_signed']) {
                    $next = $i;
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $ordered[] = $rest[$next];
            $current = $rest[$next]['info'];
            unset($rest[$next]);
        }

        $leaf = $parsed[$leafIdx]['info'];
        $res['leaf'] = $leaf;
        if ($leaf['not_after'] <= $now) {
            $res['errors'][] = 'expired';
            return $res;
        }
        if ($leaf['not_before'] > $now) {
            $res['warnings'][] = 'not_yet_valid';
        }
        if (!self::hostMatches($leaf, $domain)) {
            $res['warnings'][] = 'domain_mismatch';
        }
        if ($leaf['self_signed']) {
            $res['warnings'][] = 'self_signed';
        } elseif (count($ordered) < 2) {
            $res['warnings'][] = 'chain_incomplete';
        }

        if (!openssl_pkey_export($key, $keyOut)) {
            $res['errors'][] = 'key_unreadable';
            return $res;
        }
        $res['fullchain'] = implode('', array_column($ordered, 'pem'));
        $res['key'] = $keyOut;
        $res['ok'] = true;
        return $res;
    }

    private static function dn(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . (is_array($v) ? implode('+', $v) : $v);
        }
        return implode(', ', $out);
    }

    private static function first($v): string
    {
        return is_array($v) ? (string) end($v) : (string) $v;
    }

    // ------------------------------------------------------------------
    // State of this server
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $domain = self::domain();
        $active = self::summarize(self::parseChain(self::readFile(self::ACTIVE_CRT)), $domain);

        // coturn and Asterisk read copies; a stale copy is exactly how TURNS
        // broke silently before.
        $copies = [];
        foreach (['coturn' => self::COTURN_CRT, 'asterisk' => self::ASTERISK_CRT] as $name => $file) {
            $chain = self::parseChain(self::readFile($file));
            $copies[$name] = [
                'readable' => $chain !== [],
                'same' => $chain && $active['present']
                    && $chain[0]['fingerprint'] === $active['leaf']['fingerprint']
                    && count($chain) === $active['chain_length'],
            ];
        }

        $mode = trim(self::readFile(self::MODE_FILE));
        if (!in_array($mode, ['letsencrypt', 'custom', 'selfsigned'], true)) {
            $mode = '';
        }

        return [
            'domain' => $domain,
            'mode' => $mode,
            'active' => $active,
            'copies' => $copies,
            'le_possible' => self::letsEncryptPossible($domain),
            'certbot' => is_file(self::CERTBOT),
            'le_lineage' => $domain !== '' && is_file('/etc/letsencrypt/renewal/' . $domain . '.conf'),
            'next_renewal_check' => self::timerNext(),
            'last' => self::lastResult(),
        ];
    }

    public static function lastResult(): ?array
    {
        $data = json_decode(self::readFile(self::STATUS_FILE), true);
        return is_array($data) ? $data : null;
    }

    /** Next run of certbot's own renewal timer (installed by the certbot package). */
    private static function timerNext(): ?int
    {
        $out = @shell_exec('systemctl show certbot.timer -p NextElapseUSecRealtime --value 2>/dev/null');
        $ts = $out ? strtotime(trim($out)) : false;
        return $ts ?: null;
    }

    private static function readFile(string $file): string
    {
        return is_readable($file) ? (string) file_get_contents($file, false, null, 0, 262144) : '';
    }

    // ------------------------------------------------------------------
    // Actions (admin, CSRF checked by the controller)
    // ------------------------------------------------------------------

    /** Let's Encrypt: test (staging) or get a certificate for the portal domain. */
    public static function letsEncrypt(bool $dryRun, string $email): array
    {
        $domain = self::domain();
        if (!self::letsEncryptPossible($domain)) {
            return ['success' => false, 'error' => t('certificates.le_not_possible')];
        }
        $args = ['cert', 'letsencrypt'];
        if ($dryRun) {
            $args[] = '--dry-run';
        }
        $email = trim($email);
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'error' => t('certificates.invalid_email')];
            }
            array_push($args, '--email', $email);
        }
        return self::runPriv($args, $dryRun ? null : 'Let\'s Encrypt certificate requested');
    }

    public static function renew(bool $dryRun): array
    {
        return self::runPriv($dryRun ? ['cert', 'renew', '--dry-run'] : ['cert', 'renew'], $dryRun ? null : 'Let\'s Encrypt certificate renewed');
    }

    public static function selfSigned(): array
    {
        return self::runPriv(['cert', 'selfsigned'], 'Switched to a self-signed certificate');
    }

    /**
     * Uploaded certificate. Validated here (clear messages) and again as root
     * by aipbx-cert before anything is installed.
     *
     * @param array<string, array<string, mixed>> $files $_FILES entries: cert, chain, key, pfx
     */
    public static function installCustom(array $files, string $password, bool $allowMismatch): array
    {
        $read = function (string $field) use ($files): ?string {
            $f = $files[$field] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return '';
            }
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > self::MAX_UPLOAD || !is_uploaded_file($f['tmp_name'])) {
                return null;
            }
            return (string) file_get_contents($f['tmp_name']);
        };
        $cert = $read('cert');
        $chain = $read('chain');
        $key = $read('key');
        $pfx = $read('pfx');
        if ($cert === null || $chain === null || $key === null || $pfx === null) {
            return ['success' => false, 'error' => t('certificates.upload_failed')];
        }
        if ($pfx === '' && ($cert === '' || $key === '')) {
            return ['success' => false, 'error' => t('certificates.upload_missing')];
        }

        $bundle = self::buildBundle($cert, $chain, $key, $pfx, $password, self::domain());
        if (!$bundle['ok']) {
            return ['success' => false, 'error' => t('certificates.err_' . $bundle['errors'][0])];
        }
        if (in_array('domain_mismatch', $bundle['warnings'], true) && !$allowMismatch) {
            return ['success' => false, 'error' => sprintf(t('certificates.err_domain_mismatch'), self::domain())];
        }

        // The stage is ours (www-data); aipbx-cert reads it without following
        // symlinks and deletes it.
        if (!is_dir(self::STAGE_DIR) && !@mkdir(self::STAGE_DIR, 0700, true)) {
            return ['success' => false, 'error' => t('certificates.upload_failed')];
        }
        @chmod(self::STAGE_DIR, 0700);
        $old = umask(0077);
        $okCert = file_put_contents(self::STAGE_DIR . '/cert.pem', $bundle['fullchain']) !== false;
        $okKey = file_put_contents(self::STAGE_DIR . '/key.pem', $bundle['key']) !== false;
        umask($old);
        if (!$okCert || !$okKey) {
            self::clearStage();
            return ['success' => false, 'error' => t('certificates.upload_failed')];
        }

        $res = self::runPriv($allowMismatch ? ['cert', 'custom', '--allow-mismatch'] : ['cert', 'custom'], 'Uploaded certificate installed');
        self::clearStage();
        if ($res['success'] && $bundle['warnings']) {
            $res['warnings'] = $bundle['warnings'];
        }
        return $res;
    }

    private static function clearStage(): void
    {
        @unlink(self::STAGE_DIR . '/cert.pem');
        @unlink(self::STAGE_DIR . '/key.pem');
    }

    private static function runPriv(array $args, ?string $audit): array
    {
        $res = PrivHelper::run($args);
        $output = trim(preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $res['output']));
        if (!$res['success']) {
            // aipbx-cert records a short reason; use it only if it is from this run.
            $last = self::lastResult();
            $fresh = $last && empty($last['ok']) && strtotime((string) ($last['at'] ?? '')) >= ($_SERVER['REQUEST_TIME'] ?? time()) - 1;
            $msg = $fresh ? (string) $last['message'] : $output;
            return ['success' => false, 'error' => $msg !== '' ? $msg : t('certificates.action_failed'), 'output' => $output];
        }
        if ($audit !== null) {
            writeAuditLog(null, 'system', 'certificate', $audit . ' (' . self::domain() . ')', 'update', $_SESSION['user_id'] ?? null);
        }
        return ['success' => true, 'output' => $output];
    }
}
