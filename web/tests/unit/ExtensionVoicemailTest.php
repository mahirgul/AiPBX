<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ExtensionService.php';

/**
 * Dahili formundaki "ulaşılamıyorsa sesli mesaj" (vm_on_unavail) kutusu SIP
 * dahililerinde hiç okunmuyordu: değişken yalnızca faks dalında tanımlıydı.
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
}
