<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/PbxToolRegistry.php';

final class PbxToolRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->beginTransaction();
    }

    protected function tearDown(): void
    {
        getDB()->rollBack();
    }

    public function testCatalogAndFiltering(): void
    {
        $all = PbxToolRegistry::all();
        $names = array_column($all, 'name');
        $this->assertSame(['find_person', 'extension_state', 'transfer_call', 'leave_message', 'reminder_create', 'wakeup_call', 'reminder_list', 'reminder_cancel', 'set_dnd', 'set_forward', 'queue_pause', 'my_missed_calls', 'my_voicemails', 'send_dtmf', 'collect_dtmf', 'dial_and_navigate'], $names);

        $sub = PbxToolRegistry::forEnabled(['find_person', 'leave_message', 'nonexistent']);
        $this->assertSame(['find_person', 'leave_message'], array_column($sub, 'name'));
    }

    public function testFindPersonCompanySubExtensionAndDtmfTools(): void
    {
        $db = getDB();
        [$cid, $err] = PhonebookService::store(0, [
            'display_name' => 'Mehmet Bey',
            'company' => 'AFM Şirketi',
            'sub_extension' => '1000',
            'phone_work' => '0212 555 10 00',
        ]);
        $this->assertSame('', $err);
        $this->assertGreaterThan(0, $cid);

        $found = PbxToolRegistry::execute('find_person', ['query' => 'AFM Mehmet']);
        $this->assertTrue($found['ok']);
        $this->assertStringContainsString('Mehmet Bey', $found['text']);
        $this->assertStringContainsString('AFM Şirketi', $found['text']);
        $this->assertStringContainsString('1000', $found['text']);

        $coll = PbxToolRegistry::execute('collect_dtmf', ['max_digits' => 11, 'timeout_sec' => 6, 'terminator' => '#']);
        $this->assertTrue($coll['ok']);
        $this->assertStringContainsString('max_digits=11', $coll['text']);

        $badDtmf = PbxToolRegistry::execute('send_dtmf', ['digits' => ''], ['call_uuid' => 'u1']);
        $this->assertFalse($badDtmf['ok']);

        $badNav = PbxToolRegistry::execute('dial_and_navigate', ['target_number' => '1']);
        $this->assertFalse($badNav['ok']);
    }

    public function testFindPersonExtensionStateTransferAndLeaveMessage(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO sys_users (username, password_hash, full_name, extension, role, is_active, dnd_enabled)
                   VALUES ('zz_ahmet', 'x', 'Ahmet Yilmaz', '7891', 'user', 1, 1)");

        $fp = PbxToolRegistry::execute('find_person', ['query' => 'Ahmet']);
        $this->assertTrue($fp['ok']);
        $this->assertStringContainsString('7891', $fp['text']);

        $st = PbxToolRegistry::execute('extension_state', ['extension' => '7891']);
        $this->assertTrue($st['ok']);
        $this->assertStringContainsString('DND', $st['text']);

        $tr = PbxToolRegistry::execute('transfer_call', ['target' => '7891', 'announce' => 'Ali arıyor']);
        $this->assertTrue($tr['ok']);
        $this->assertSame('PJSIP/7891', $tr['action']['endpoint'] ?? null);

        $unconfirmed = PbxToolRegistry::execute('leave_message', ['recipient' => '7891', 'message' => 'Beni ara', 'confirmed' => false]);
        $this->assertFalse($unconfirmed['ok']);

        $confirmed = PbxToolRegistry::execute('leave_message', [
            'recipient' => '7891',
            'message' => 'Lütfen geri arayın',
            'confirmed' => true,
        ], ['app_id' => 1, 'caller' => '0532', 'caller_name' => 'Veli']);
        $this->assertTrue($confirmed['ok']);
        $saved = (int) $db->query("SELECT COUNT(*) FROM ai_requests WHERE intent_id = 'leave_message' AND transcript LIKE '%Lütfen geri%'")->fetchColumn();
        $this->assertSame(1, $saved);

        $dnd = PbxToolRegistry::execute('set_dnd', ['extension' => '7891', 'enabled' => false, 'confirmed' => true], ['caller' => '7891']);
        $this->assertTrue($dnd['ok']);
        $this->assertSame(0, (int) $db->query("SELECT dnd_enabled FROM sys_users WHERE extension = '7891'")->fetchColumn());

        $cf = PbxToolRegistry::execute('set_forward', ['extension' => '7891', 'target' => '7892', 'confirmed' => true], ['caller' => '7891']);
        $this->assertTrue($cf['ok']);
        $this->assertSame('7892', $db->query("SELECT call_forward_number FROM sys_users WHERE extension = '7891'")->fetchColumn());

        $vm = PbxToolRegistry::execute('my_voicemails', ['extension' => '7891'], ['caller' => '7891']);
        $this->assertTrue($vm['ok']);

        $anonDnd = PbxToolRegistry::execute('set_dnd', ['extension' => '7891', 'enabled' => true, 'confirmed' => true]);
        $this->assertFalse($anonDnd['ok']);
        $this->assertStringContainsString('Permission denied', $anonDnd['text']);

        $deniedDnd = PbxToolRegistry::execute('set_dnd', ['extension' => '102', 'enabled' => true, 'confirmed' => true], ['caller' => '101']);
        $this->assertFalse($deniedDnd['ok']);
        $this->assertStringContainsString('Permission denied', $deniedDnd['text']);

        $rem = ReminderService::create([
            'extension' => '7891',
            'kind' => 'reminder',
            'remind_at' => date('Y-m-d H:i:s', time() + 3600),
            'message' => 'Test meeting',
        ]);
        $this->assertTrue($rem['ok']);
        $anonCancel = PbxToolRegistry::execute('reminder_cancel', ['id' => (int) $rem['id'], 'confirmed' => true]);
        $this->assertFalse($anonCancel['ok']);
        $this->assertStringContainsString('Permission denied', $anonCancel['text']);

        $deniedCancel = PbxToolRegistry::execute('reminder_cancel', ['id' => (int) $rem['id'], 'confirmed' => true], ['caller' => '999']);
        $this->assertFalse($deniedCancel['ok']);
        $this->assertStringContainsString('Permission denied', $deniedCancel['text']);
    }
}
