<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';
require_once __DIR__ . '/../../src/sync/SyncVoicemail.php';

/**
 * My Phone: call settings and voicemail settings are saved separately. The
 * call settings used to rewrite the voicemail columns with defaults, so a DND
 * or forwarding change (portal or mobile app) turned the attachment off and
 * the forward-to-voicemail options back off.
 */
final class MyPhoneVoicemailSettingsTest extends TestCase
{
    private PDO $db;
    private int $id;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_users WHERE extension = '7402'");
        $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, is_active,
                voicemail_enabled, voicemail_pin, voicemail_email, voicemail_attach_audio, vm_on_busy)
            VALUES ('vmtest7402', '', 'Voicemail Test', 'account@example.com', 'user', '7402', 'sip', 1, 1, '4321', '', 0, 1)")->execute();
        $this->id = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension = '7402'");
    }

    private function row(): array
    {
        return $this->db->query("SELECT * FROM sys_users WHERE id = {$this->id}")->fetch(PDO::FETCH_ASSOC);
    }

    public function testCallSettingsLeaveVoicemailAlone(): void
    {
        MyPhoneRepository::updatePhoneSettings($this->id, 1, '', 'web');
        $row = $this->row();
        $this->assertSame(1, (int) $row['dnd_enabled']);
        $this->assertSame(0, (int) $row['voicemail_attach_audio']);
        $this->assertSame(1, (int) $row['vm_on_busy']);
    }

    public function testVoicemailSettingsAreStored(): void
    {
        MyPhoneRepository::updateVoicemailSettings($this->id, [
            'voicemail_enabled' => 1, 'voicemail_pin' => '', 'voicemail_email' => 'vm@example.com',
            'voicemail_email_notify' => 0, 'voicemail_attach_audio' => 1, 'vm_always' => 1,
        ]);
        $row = $this->row();
        $this->assertSame('vm@example.com', $row['voicemail_email']);
        $this->assertSame(0, (int) $row['voicemail_email_notify']);
        $this->assertSame(1, (int) $row['voicemail_attach_audio']);
        $this->assertSame(0, (int) $row['vm_on_busy']);
        $this->assertSame(1, (int) $row['vm_always']);
        $this->assertSame('4321', (string) $row['voicemail_pin'], 'an empty PIN keeps the stored one');
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MyPhoneRepository::updateVoicemailSettings($this->id, ['voicemail_enabled' => 1, 'voicemail_email' => 'not-an-address']);
    }

    public function testNotificationSwitchControlsMailboxAddress(): void
    {
        $conf = fn() => file_get_contents(ASTERISK_PBX_DIR . '/voicemail_pbx.conf');
        syncVoicemail();
        $this->assertMatchesRegularExpression('/^7402 => [^,]*,[^,]*,account@example\.com,/m', $conf());

        $this->db->exec("UPDATE sys_users SET voicemail_email_notify = 0 WHERE id = {$this->id}");
        syncVoicemail();
        $this->assertMatchesRegularExpression('/^7402 => [^,]*,[^,]*,,/m', $conf());
    }
}
