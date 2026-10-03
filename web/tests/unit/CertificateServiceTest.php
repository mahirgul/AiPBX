<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/CertificateService.php';

/**
 * Certificates page: chain parsing, host matching and the checks an uploaded
 * certificate goes through before aipbx-cert installs it. Certificates are
 * generated on the fly: root CA → intermediate → leaf.
 */
final class CertificateServiceTest extends TestCase
{
    private static string $cnf;
    /** @var array<string, array{cert: string, key: OpenSSLAsymmetricKey}> */
    private static array $pki = [];

    public static function setUpBeforeClass(): void
    {
        self::$cnf = tempnam(sys_get_temp_dir(), 'aipbx-cnf');
        file_put_contents(self::$cnf, <<<CNF
[req]
distinguished_name = dn
[dn]
[ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[leaf]
basicConstraints = CA:FALSE
subjectAltName = DNS:pbx.example.com,DNS:*.voice.example.com,IP:192.0.2.10
[nosan]
basicConstraints = CA:FALSE
CNF);
        $root = self::issue(['CN' => 'Test Root', 'O' => 'Test CA'], null, 'ca');
        $inter = self::issue(['CN' => 'Test Intermediate', 'O' => 'Test CA'], $root, 'ca');
        self::$pki = [
            'root' => $root,
            'inter' => $inter,
            'leaf' => self::issue(['CN' => 'pbx.example.com'], $inter, 'leaf'),
            'self' => self::issue(['CN' => 'pbx.example.com'], null, 'leaf'),
            'nosan' => self::issue(['CN' => 'legacy.example.com'], $inter, 'nosan'),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$cnf);
    }

    /** @return array{cert: string, key: OpenSSLAsymmetricKey} */
    private static function issue(array $dn, ?array $issuer, string $ext): array
    {
        $opts = ['config' => self::$cnf, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => $ext];
        $key = openssl_pkey_new($opts);
        $csr = openssl_csr_new($dn, $key, $opts);
        $x = openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, 365, $opts, random_int(1, PHP_INT_MAX));
        openssl_x509_export($x, $pem);
        return ['cert' => $pem, 'key' => $key];
    }

    private static function keyPem(string $name, ?string $pass = null): string
    {
        openssl_pkey_export(self::$pki[$name]['key'], $out, $pass, ['config' => self::$cnf]);
        return $out;
    }

    private static function leaf(string $name): array
    {
        return CertificateService::parseChain(self::$pki[$name]['cert'])[0];
    }

    public function testHostMatching(): void
    {
        $leaf = self::leaf('leaf');
        $this->assertTrue(CertificateService::hostMatches($leaf, 'pbx.example.com'));
        $this->assertTrue(CertificateService::hostMatches($leaf, 'PBX.Example.com.'));
        $this->assertTrue(CertificateService::hostMatches($leaf, 'sip.voice.example.com'), 'one-label wildcard');
        $this->assertFalse(CertificateService::hostMatches($leaf, 'a.b.voice.example.com'), 'wildcard covers one label only');
        $this->assertFalse(CertificateService::hostMatches($leaf, 'voice.example.com'));
        $this->assertTrue(CertificateService::hostMatches($leaf, '192.0.2.10'));
        $this->assertFalse(CertificateService::hostMatches($leaf, '192.0.2.11'));
        $this->assertFalse(CertificateService::hostMatches($leaf, 'example.com'));
        // Without subjectAltName the CN is the only name.
        $this->assertTrue(CertificateService::hostMatches(self::leaf('nosan'), 'legacy.example.com'));
    }

    public function testSummaryOfAValidChain(): void
    {
        $chain = CertificateService::parseChain(self::$pki['leaf']['cert'] . self::$pki['inter']['cert']);
        $s = CertificateService::summarize($chain, 'pbx.example.com');
        $this->assertSame('ok', $s['level'], implode(',', $s['issues']));
        $this->assertSame(2, $s['chain_length']);
        $this->assertGreaterThan(300, $s['days_left']);
    }

    public function testSummaryFlagsProblems(): void
    {
        $leafOnly = CertificateService::parseChain(self::$pki['leaf']['cert']);
        $this->assertContains('chain_incomplete', CertificateService::summarize($leafOnly, 'pbx.example.com')['issues']);

        $self = CertificateService::summarize(CertificateService::parseChain(self::$pki['self']['cert']), 'pbx.example.com');
        $this->assertContains('self_signed', $self['issues']);
        $this->assertSame('warning', $self['level']);

        $chain = CertificateService::parseChain(self::$pki['leaf']['cert'] . self::$pki['inter']['cert']);
        $mismatch = CertificateService::summarize($chain, 'other.example.org');
        $this->assertContains('domain_mismatch', $mismatch['issues']);
        $this->assertSame('danger', $mismatch['level']);

        $soon = CertificateService::summarize($chain, 'pbx.example.com', time() + 355 * 86400);
        $this->assertContains('expires_soon', $soon['issues']);
        $expired = CertificateService::summarize($chain, 'pbx.example.com', time() + 400 * 86400);
        $this->assertContains('expired', $expired['issues']);
        $this->assertSame('danger', $expired['level']);

        $this->assertSame(['missing'], CertificateService::summarize([], 'pbx.example.com')['issues']);
    }

    public function testBundleIsOrderedLeafFirstWithoutTheRoot(): void
    {
        // Chain pasted in the wrong order, root included, plus an unrelated certificate.
        $cert = self::$pki['root']['cert'] . self::$pki['self']['cert'] . self::$pki['inter']['cert'] . self::$pki['leaf']['cert'];
        $b = CertificateService::buildBundle($cert, '', self::keyPem('leaf'), '', '', 'pbx.example.com');
        $this->assertTrue($b['ok'], implode(',', $b['errors']));
        $this->assertSame([], $b['warnings']);
        $chain = CertificateService::parseChain($b['fullchain']);
        $this->assertSame(['pbx.example.com', 'Test Intermediate'], array_column($chain, 'subject_cn'));
        $this->assertStringContainsString('PRIVATE KEY', $b['key']);
        $this->assertStringNotContainsString('ENCRYPTED', $b['key']);
    }

    public function testBundleFromSeparateChainFileAndEncryptedKey(): void
    {
        $b = CertificateService::buildBundle(self::$pki['leaf']['cert'], self::$pki['inter']['cert'], self::keyPem('leaf', 'secret'), '', 'secret', 'pbx.example.com');
        $this->assertTrue($b['ok'], implode(',', $b['errors']));
        $this->assertCount(2, CertificateService::parseChain($b['fullchain']));
        $this->assertStringNotContainsString('ENCRYPTED', $b['key'], 'aipbx-cert installs an unencrypted key');

        $wrong = CertificateService::buildBundle(self::$pki['leaf']['cert'], '', self::keyPem('leaf', 'secret'), '', 'nope', 'pbx.example.com');
        $this->assertSame(['key_unreadable'], $wrong['errors']);
    }

    public function testBundleFromPfx(): void
    {
        openssl_pkcs12_export(self::$pki['leaf']['cert'], $pfx, self::$pki['leaf']['key'], 'pfxpass', ['extracerts' => [self::$pki['inter']['cert'], self::$pki['root']['cert']]]);
        $b = CertificateService::buildBundle('', '', '', $pfx, 'pfxpass', 'pbx.example.com');
        $this->assertTrue($b['ok'], implode(',', $b['errors']));
        $this->assertSame(['pbx.example.com', 'Test Intermediate'], array_column(CertificateService::parseChain($b['fullchain']), 'subject_cn'));

        $this->assertSame(['pfx_unreadable'], CertificateService::buildBundle('', '', '', $pfx, 'wrong', 'pbx.example.com')['errors']);
    }

    public function testBundleRejectsBadInput(): void
    {
        $this->assertSame(['key_mismatch'], CertificateService::buildBundle(self::$pki['leaf']['cert'], '', self::keyPem('self'), '', '', 'pbx.example.com')['errors']);
        $this->assertSame(['no_certificate'], CertificateService::buildBundle('not a certificate', '', self::keyPem('leaf'), '', '', 'pbx.example.com')['errors']);
        $this->assertSame(['key_unreadable'], CertificateService::buildBundle(self::$pki['leaf']['cert'], '', 'garbage', '', '', 'pbx.example.com')['errors']);
        $expired = CertificateService::buildBundle(self::$pki['leaf']['cert'], '', self::keyPem('leaf'), '', '', 'pbx.example.com', time() + 400 * 86400);
        $this->assertSame(['expired'], $expired['errors']);
    }

    public function testBundleWarnings(): void
    {
        $mismatch = CertificateService::buildBundle(self::$pki['leaf']['cert'] . self::$pki['inter']['cert'], '', self::keyPem('leaf'), '', '', 'other.example.org');
        $this->assertTrue($mismatch['ok']);
        $this->assertSame(['domain_mismatch'], $mismatch['warnings']);

        $noChain = CertificateService::buildBundle(self::$pki['leaf']['cert'], '', self::keyPem('leaf'), '', '', 'pbx.example.com');
        $this->assertSame(['chain_incomplete'], $noChain['warnings']);

        $self = CertificateService::buildBundle(self::$pki['self']['cert'], '', self::keyPem('self'), '', '', 'pbx.example.com');
        $this->assertSame(['self_signed'], $self['warnings']);
    }

    public function testLetsEncryptNeedsAPublicName(): void
    {
        $this->assertTrue(CertificateService::letsEncryptPossible('pbx.example.com'));
        $this->assertFalse(CertificateService::letsEncryptPossible('aipbx.local'));
        $this->assertFalse(CertificateService::letsEncryptPossible('192.0.2.10'));
        $this->assertFalse(CertificateService::letsEncryptPossible('localhost'));
        $this->assertFalse(CertificateService::letsEncryptPossible(''));
    }
}
