<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * The TLS transport is the one phones register against when the provider
 * publishes sip-tls.<domain>. Two settings decide what it looks like:
 *
 *   tls_domain  — written as `domain=` so contacts and AORs addressed to that
 *                 name resolve onto THIS transport instead of whichever UDP
 *                 transport happens to answer first. Absent by default: the
 *                 generated file stays byte-identical to what shipped before.
 *   tls_method  — the negotiated method list, default tlsv1_2 so an upgrade
 *                 never silently changes what existing phones negotiate.
 *
 * SAFETY: bootstrap.php points ASTERISK_PBX_DIR at a temp directory and sets
 * AIPBX_NO_ASTERISK=1, so nothing here touches /etc/asterisk or the live
 * Asterisk.
 */
final class PjsipTransportTest extends TestCase
{
    private PDO $db;

    /** @var list<array{keyword: string, data: string, seq: int, type: int}> */
    private array $origSettings = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $stmt = $this->db->query('SELECT keyword, data, seq, type FROM pjsipsettings');
        $this->origSettings = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    protected function tearDown(): void
    {
        $this->db->exec('TRUNCATE TABLE pjsipsettings');
        if ($this->origSettings !== []) {
            $ins = $this->db->prepare('INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES (?, ?, ?, ?)');
            foreach ($this->origSettings as $row) {
                $ins->execute([$row['keyword'], $row['data'], $row['seq'], $row['type']]);
            }
        }
        // Leave pjsip_transports.conf describing the restored settings, not these.
        syncTransports();
    }

    /**
     * Generates pjsip_transports.conf with a working TLS transport plus the
     * given settings on top, and returns the file content.
     *
     * @param array<string, string|null> $settings null removes the row
     */
    private function generate(array $settings): string
    {
        $this->db->exec('TRUNCATE TABLE pjsipsettings');
        $ins = $this->db->prepare('INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES (?, ?, 1, 0)');
        $base = [
            'tls_enabled'       => '1',
            'tls_bindaddr'      => '0.0.0.0',
            'tls_bindport'      => '5061',
            'tls_cert_file'     => '/etc/asterisk/keys/aipbx.crt',
            'tls_priv_key_file' => '/etc/asterisk/keys/aipbx.key',
        ];
        foreach (array_merge($base, $settings) as $key => $value) {
            if ($value !== null) {
                $ins->execute([$key, $value]);
            }
        }

        $this->assertTrue(syncTransports());

        $path = ASTERISK_PBX_DIR . '/pjsip_transports.conf';
        $this->assertFileExists($path, 'the transport config was not generated');
        return (string) file_get_contents($path);
    }

    /** The text of one generated [section], without the next one. */
    private function block(string $conf, string $section): string
    {
        $start = strpos($conf, "[{$section}]\n");
        $this->assertNotFalse($start, "no [{$section}] section was generated");
        $rest = substr($conf, $start + strlen($section) + 3);
        $end = strpos($rest, "\n[");
        return $end === false ? $rest : substr($rest, 0, $end);
    }

    public function testNothingIsWrittenToTheLiveConfigDirectory(): void
    {
        $this->assertStringStartsWith(sys_get_temp_dir(), ASTERISK_PBX_DIR,
            'SAFETY: this test would write into the live /etc/asterisk');
    }

    public function testTlsDomainIsWrittenToTheTlsTransport(): void
    {
        $conf = $this->generate(['tls_domain' => 'sip-tls.example.test']);
        $tls = $this->block($conf, 'transport-tls');

        $this->assertStringContainsString('protocol=tls', $tls);
        $this->assertStringContainsString("domain=sip-tls.example.test\n", $tls,
            'domain= must sit inside [transport-tls], or a URI for that name lands on another transport');
    }

    public function testNoDomainLineWithoutTheSetting(): void
    {
        $conf = $this->generate([]);
        $tls = $this->block($conf, 'transport-tls');

        $this->assertStringNotContainsString('domain=', $tls,
            'an upgrade must not change the generated file for a box that never set tls_domain');
    }

    public function testTlsMethodDefaultsToTlsv12(): void
    {
        $conf = $this->generate([]);
        $this->assertStringContainsString("method=tlsv1_2\n", $this->block($conf, 'transport-tls'));
    }

    public function testTlsMethodIsConfigurable(): void
    {
        $conf = $this->generate(['tls_method' => 'tlsv1_2,tlsv1_3']);
        $this->assertStringContainsString("method=tlsv1_2,tlsv1_3\n", $this->block($conf, 'transport-tls'));
    }

    public function testDomainAppliesToTlsOnly(): void
    {
        $conf = $this->generate([
            'bindaddr'   => '0.0.0.0',
            'bindport'   => '5060',
            'tls_domain' => 'sip-tls.example.test',
        ]);

        $this->assertStringNotContainsString('domain=', $this->block($conf, 'transport-udp'),
            'domain= on the UDP transport would pull every URI onto the wrong transport');
        $this->assertStringContainsString("domain=sip-tls.example.test\n", $this->block($conf, 'transport-tls'));
    }

    /**
     * The TLS block only exists while tls_enabled is on and bound: a domain
     * setting alone must not conjure a transport Asterisk cannot listen on.
     */
    public function testNoTlsBlockWhenTheTransportIsOff(): void
    {
        $conf = $this->generate(['tls_enabled' => '0', 'tls_domain' => 'sip-tls.example.test']);
        $this->assertStringNotContainsString('[transport-tls]', $conf);
        $this->assertStringNotContainsString('domain=sip-tls.example.test', $conf);
    }

    /**
     * A value pasted out of a document can carry a line break; the setting is
     * written straight into the config, so a break must not become a second
     * directive inside [transport-tls].
     */
    public function testADomainCannotSmuggleInASecondDirective(): void
    {
        $tls = $this->block($this->generate(['tls_domain' => "sip-tls.example.test\r\nevildirective=1"]), 'transport-tls');

        $this->assertSame(1, substr_count($tls, 'domain='), 'one domain= line, whatever was pasted');
        $this->assertDoesNotMatchRegularExpression('/^evildirective=/m', $tls);
    }
}
