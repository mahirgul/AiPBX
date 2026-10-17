<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * Form values that reach Asterisk's configuration must not add lines of
 * their own: a line break followed by `same => n,System(...)` in the
 * dialplan, or `[general]` + `mailcmd=` in voicemail.conf, runs a command.
 */
final class DialplanInjectionTest extends TestCase
{
    private const EXT = '7413';
    private const PAYLOAD = "1\n same => n,System(touch /tmp/pwned)";

    private PDO $db;

    protected function setUp(): void
    {
        Fixtures::load();
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT . "'");
        $this->db->exec("DELETE FROM pbx_ring_groups WHERE name = 'InjectionTest'");
    }

    public function testFaxDestinationIsOneLine(): void
    {
        $lines = buildDestinationLines('fax', self::PAYLOAD);
        $this->assertStringNotContainsString('System(', $lines);
        $this->assertSame(1, substr_count($lines, "\n") + 1, 'one dialplan line');
        $this->assertStringContainsString('Goto(from-trunk-fax,1samen', $lines);
        $this->assertStringContainsString('Goto(from-trunk-fax,default,1)', buildDestinationLines('fax', "\n"));
    }

    public function testQueueRecordFormatIsWhitelisted(): void
    {
        $this->db->exec("UPDATE pbx_queues SET record_enabled = 1, record_format = 'x)\nexten' WHERE queue_name = '" . Fixtures::QUEUE_NAME . "'");
        $id = (int) $this->db->query("SELECT id FROM pbx_queues WHERE queue_name = '" . Fixtures::QUEUE_NAME . "'")->fetchColumn();
        $lines = buildDestinationLines('queue', (string) $id);
        $this->assertStringNotContainsString("x)\nexten", $lines);
        $this->assertStringContainsString('.wav)', $lines);
    }

    public function testVoicemailEmailCannotAddSettings(): void
    {
        $this->db->prepare("INSERT INTO sys_users (username, full_name, extension, extension_type, role, is_active, voicemail_enabled, voicemail_email, voicemail_email_notify, password_hash)
                            VALUES (?, ?, ?, 'sip', 'user', 1, 1, ?, 1, '')")
            ->execute(['vminject', 'Mail, Test', self::EXT, "a@b.c\n[general]\nmailcmd=/bin/touch /tmp/pwned"]);
        syncVoicemail();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/voicemail_pbx.conf');
        $this->assertStringNotContainsString('mailcmd', $conf);
        $this->assertMatchesRegularExpression('/^' . self::EXT . ' => \d+,Mail  Test,,,/m', $conf, 'comma in the name does not shift the fields');
    }

    public function testRingGroupPrefixKeepsTheExpression(): void
    {
        $this->db->prepare("INSERT INTO pbx_ring_groups (group_number, internal_number, name, numbers_list, ring_strategy, ring_timeout, cid_prefix, fallback_dest_type, fallback_dest_id, record_call, is_active)
                            VALUES ('7499', '7499', 'InjectionTest', '7001', 'ringall', 20, ?, 'hangup', '', 0, 1)")
            ->execute(['VIP"):System(id']);
        syncRingGroups();
        $conf = '';
        foreach (glob(ASTERISK_PBX_DIR . '/*.conf') as $f) {
            if (str_contains((string) file_get_contents($f), 'InjectionTest')) {
                $conf = (string) file_get_contents($f);
            }
        }
        $this->assertNotSame('', $conf, 'ring group written');
        $this->assertStringNotContainsString('System(', $conf);
        $this->assertStringContainsString('Set(CALLERID(name)=VIPSystemid ', $conf);
    }
}
