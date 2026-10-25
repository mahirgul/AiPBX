<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class SyncTransportsCoverageTest extends TestCase
{
    private PDO $db;
    /** @var list<array{keyword: string, data: string, seq: int, type: int}> */
    private array $origPjsipSettings = [];
    /** @var array<string, string|null> */
    private array $origSysSettings = [];

    protected function setUp(): void
    {
        $this->db = getDB();

        // 1. Back up original pjsipsettings
        $stmt = $this->db->query('SELECT keyword, data, seq, type FROM pjsipsettings');
        $this->origPjsipSettings = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // 2. Back up original sys_settings used by SyncTransports
        $keys = ['pjsip_user_agent', 'pjsip_max_forwards', 'pjsip_timer_t1', 'pjsip_timer_b'];
        $this->origSysSettings = [];
        foreach ($keys as $k) {
            $st = $this->db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
            $st->execute([$k]);
            $val = $st->fetchColumn();
            $this->origSysSettings[$k] = $val !== false ? (string)$val : null;
        }
    }

    protected function tearDown(): void
    {
        // 1. Restore pjsipsettings table
        $this->db->exec('TRUNCATE TABLE pjsipsettings');
        if (!empty($this->origPjsipSettings)) {
            $stmt = $this->db->prepare('INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES (?, ?, ?, ?)');
            foreach ($this->origPjsipSettings as $row) {
                $stmt->execute([$row['keyword'], $row['data'], $row['seq'], $row['type']]);
            }
        }

        // 2. Restore sys_settings
        foreach ($this->origSysSettings as $k => $val) {
            if ($val === null) {
                $this->db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([$k]);
            } else {
                $this->db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                    ->execute([$k, $val]);
            }
        }

        syncTransports();
    }

    /**
     * @param array<string, string> $map
     */
    private function setPjsipSettings(array $map): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO pjsipsettings (keyword, data, seq, type)
             VALUES (?, ?, 1, 0)
             ON DUPLICATE KEY UPDATE data = VALUES(data)'
        );
        foreach ($map as $key => $val) {
            $stmt->execute([$key, $val]);
        }
    }

    public function testFullTransportsConfiguration(): void
    {
        $this->setPjsipSettings([
            'bindaddr'          => '0.0.0.0',
            'bindport'          => '5060',
            'tcp_enabled'       => '1',
            'tcp_bindaddr'      => '0.0.0.0',
            'tcp_bindport'      => '5060',
            'tls_enabled'       => '1',
            'tls_bindaddr'      => '0.0.0.0',
            'tls_bindport'      => '5061',
            'tls_cert_file'     => '/etc/ssl/certs/aipbx.crt',
            'tls_priv_key_file' => '/etc/ssl/private/aipbx.key',
            'wss_enabled'       => '1',
            'wss_bindaddr'      => '0.0.0.0',
            'wss_bindport'      => '8089',
            'ws_enabled'        => '1',
            'ws_bindaddr'       => '0.0.0.0',
            'ws_bindport'       => '8088',
            'externip_val'      => '203.0.113.15',
            'localnet_0'        => '192.168.1.0',
            'netmask_0'         => '255.255.255.0',
            'localnet_1'        => '10.0.0.0',
            'netmask_1'         => '255.0.0.0',
        ]);

        // Configure sys_settings
        $stmtSys = $this->db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmtSys->execute(['pjsip_user_agent', 'AiPBX Master PBX']);
        $stmtSys->execute(['pjsip_max_forwards', '65']);
        $stmtSys->execute(['pjsip_timer_t1', '600']);
        $stmtSys->execute(['pjsip_timer_b', '35000']);

        $this->assertTrue(syncTransports());

        $file = ASTERISK_PBX_DIR . '/pjsip_transports.conf';
        $this->assertFileExists($file);
        $conf = (string)file_get_contents($file);

        // [system] section
        $this->assertStringContainsString("[system]\ntype=system\ntimer_t1=600\ntimer_b=35000", $conf);

        // [global] section
        $this->assertStringContainsString("[global]\ntype=global\nuser_agent=AiPBX Master PBX\nmax_forwards=65", $conf);

        // [transport-udp] section
        $this->assertStringContainsString("[transport-udp]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:5060", $conf);
        $this->assertStringContainsString("local_net=192.168.1.0/255.255.255.0", $conf);
        $this->assertStringContainsString("local_net=10.0.0.0/255.0.0.0", $conf);
        $this->assertStringContainsString("external_media_address=203.0.113.15", $conf);
        $this->assertStringContainsString("external_signaling_address=203.0.113.15", $conf);

        // [transport-tcp] section
        $this->assertStringContainsString("[transport-tcp]\ntype=transport\nprotocol=tcp\nbind=0.0.0.0:5060", $conf);

        // [transport-tls] section
        $this->assertStringContainsString("[transport-tls]\ntype=transport\nprotocol=tls\nbind=0.0.0.0:5061", $conf);
        $this->assertStringContainsString("cert_file=/etc/ssl/certs/aipbx.crt", $conf);
        $this->assertStringContainsString("priv_key_file=/etc/ssl/private/aipbx.key", $conf);
        $this->assertStringContainsString("method=tlsv1_2", $conf);

        // [transport-wss] section
        $this->assertStringContainsString("[transport-wss]\ntype=transport\nprotocol=wss\nbind=0.0.0.0:8089", $conf);

        // [transport-ws] section
        $this->assertStringContainsString("[transport-ws]\ntype=transport\nprotocol=ws\nbind=0.0.0.0:8088", $conf);
    }

    public function testDisabledTransportsOmittedFromConf(): void
    {
        $this->setPjsipSettings([
            'bindaddr'     => '127.0.0.1',
            'bindport'     => '5060',
            'tcp_enabled'  => '0',
            'tls_enabled'  => '0',
            'wss_enabled'  => '0',
            'ws_enabled'   => '0',
            'externip_val' => '',
        ]);

        syncTransports();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/pjsip_transports.conf');

        $this->assertStringContainsString('[transport-udp]', $conf);
        $this->assertStringNotContainsString('[transport-tcp]', $conf);
        $this->assertStringNotContainsString('[transport-tls]', $conf);
        $this->assertStringNotContainsString('[transport-wss]', $conf);
        $this->assertStringNotContainsString('[transport-ws]', $conf);
        $this->assertStringNotContainsString('external_media_address', $conf);
    }

    public function testDefaultNetmaskFallbackWhenEmpty(): void
    {
        $this->setPjsipSettings([
            'bindaddr'   => '0.0.0.0',
            'bindport'   => '5060',
            'localnet_0' => '172.16.0.0',
            'netmask_0'  => '', // empty netmask falls back to 255.255.255.0
        ]);

        syncTransports();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/pjsip_transports.conf');
        $this->assertStringContainsString('local_net=172.16.0.0/255.255.255.0', $conf);
    }

    public function testConfigurationInjectionResistance(): void
    {
        // 1. User-agent injection
        $stmtSys = $this->db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmtSys->execute(['pjsip_user_agent', "AiPBX\nuser_agent=Evil\n[evil_section]\ntype=evil"]);

        // 2. Transport fields injection
        $this->setPjsipSettings([
            'bindaddr'          => "0.0.0.0\nprotocol=evil",
            'bindport'          => "5060\nevil=1",
            'tcp_enabled'       => '1',
            'tcp_bindaddr'      => "0.0.0.0\nevil=1",
            'tcp_bindport'      => '5060',
            'tls_enabled'       => '1',
            'tls_bindaddr'      => '0.0.0.0',
            'tls_bindport'      => '5061',
            'tls_cert_file'     => "/etc/cert.pem\nevil_directive=true",
            'tls_priv_key_file' => "/etc/key.pem\nevil_directive=true",
            'externip_val'      => "203.0.113.1\nlocal_net=evil",
        ]);

        syncTransports();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/pjsip_transports.conf');

        // Injection assertions
        $this->assertDoesNotMatchRegularExpression('/^\[evil_section\]/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*protocol=evil/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*evil=1/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*evil_directive=true/m', $conf);

        // Cleaned values assertions: newlines stripped, directives cannot break out
        $this->assertStringContainsString('bind=', $conf);
        $this->assertStringContainsString('cert_file=/etc/cert.pemevil_directive=true', $conf);
        $this->assertStringContainsString('external_media_address=203.0.113.1local_netevil', $conf);
    }
}
