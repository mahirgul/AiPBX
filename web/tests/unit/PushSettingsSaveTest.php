<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/PushSettingsService.php';

/**
 * Push ayarı değiştiğinde markPendingSync('dialplan') çağrılıyordu: var olmayan
 * domain + eksik argüman → ArgumentCountError, ayar kaydı ölüyordu.
 */
final class PushSettingsSaveTest extends TestCase
{
    public function testChangingSettingsMarksGeneralDialplanForSync(): void
    {
        $db = getDB();
        $_SESSION['user_id'] = null;
        $before = (string) getSystemSetting('push_wait_seconds', '8');
        $new = $before === '9' ? 10 : 9;

        $res = PushSettingsService::saveSettings(['push_provider' => 'none', 'push_wait_seconds' => $new]);
        $this->assertTrue($res['success'], $res['error'] ?? '');

        $n = $db->query("SELECT COUNT(*) FROM sys_pending_sync WHERE domain = 'general_dialplan' AND entity_id = 'push_settings'")->fetchColumn();
        $this->assertGreaterThan(0, (int) $n);

        PushSettingsService::saveSettings(['push_provider' => 'none', 'push_wait_seconds' => (int) $before]);
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_id = 'push_settings'");
    }
}
