<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ExtensionService.php';

/**
 * The "voicemail when unreachable" (vm_on_unavail) box on the extension form
 * was never read for SIP extensions: the variable was defined only in the fax branch.
 */
final class ExtensionVoicemailTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->db->exec("DELETE FROM sys_users WHERE extension = '7401'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension = '7401'");
    }

    private function save(array $extra): array
    {
        return ExtensionService::saveExtension($extra + [
            'csrf_token' => 'test-token',
            'extension' => '7401',
            'full_name' => 'Sesli Mesaj Testi',
            'sip_password' => 'S' . bin2hex(random_bytes(8)),
            'extension_type' => 'sip',
            'is_active' => '1',
            'voicemail_enabled' => '1',
        ]);
    }

    public function testUnavailableVoicemailOptionIsStored(): void
    {
        $res = $this->save(['vm_on_unavail' => '1', 'vm_on_busy' => '1']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $row = $this->db->query("SELECT vm_on_unavail, vm_on_busy FROM sys_users WHERE extension = '7401'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['vm_on_unavail']);
        $this->assertSame(1, (int) $row['vm_on_busy']);
    }

    public function testUncheckedOptionIsStoredAsZero(): void
    {
        $res = $this->save([]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame(0, (int) $this->db->query("SELECT vm_on_unavail FROM sys_users WHERE extension = '7401'")->fetchColumn());
    }

    private function row(): array
    {
        return $this->db->query("SELECT id, voicemail_enabled, voicemail_email_notify, voicemail_attach_audio FROM sys_users WHERE extension = '7401'")->fetch(PDO::FETCH_ASSOC);
    }

    /** #2: an unticked "voicemail box enabled" arrives as the hidden 0 and must stick. */
    public function testVoicemailCanBeSwitchedOff(): void
    {
        $this->assertTrue($this->save([])['success']);
        $id = (int) $this->row()['id'];
        $res = $this->save(['user_id' => $id, 'voicemail_enabled' => '0', 'voicemail_email_notify' => '0', 'voicemail_attach_audio' => '0']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $row = $this->row();
        $this->assertSame(0, (int) $row['voicemail_enabled']);
        $this->assertSame(0, (int) $row['voicemail_email_notify']);
        $this->assertSame(0, (int) $row['voicemail_attach_audio']);
    }

    /** A caller that does not send a switch keeps the stored value instead of turning it back on. */
    public function testMissingSwitchKeepsStoredValue(): void
    {
        $this->assertTrue($this->save(['voicemail_attach_audio' => '0', 'voicemail_email_notify' => '0'])['success']);
        $id = (int) $this->row()['id'];
        $this->assertTrue($this->save(['user_id' => $id])['success']);
        $row = $this->row();
        $this->assertSame(0, (int) $row['voicemail_attach_audio']);
        $this->assertSame(0, (int) $row['voicemail_email_notify']);
    }

    /** New extension without the switches: everything on, as before. */
    public function testNewExtensionDefaultsOn(): void
    {
        $data = ['csrf_token' => 'test-token', 'extension' => '7401', 'full_name' => 'Sesli Mesaj Testi',
                 'sip_password' => 'S' . bin2hex(random_bytes(8)), 'extension_type' => 'sip', 'is_active' => '1'];
        $this->assertTrue(ExtensionService::saveExtension($data)['success']);
        $row = $this->row();
        $this->assertSame(1, (int) $row['voicemail_enabled']);
        $this->assertSame(1, (int) $row['voicemail_email_notify']);
        $this->assertSame(1, (int) $row['voicemail_attach_audio']);
    }
}
