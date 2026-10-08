<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/services/LampService.php';
require_once __DIR__ . '/../../src/sync/SyncGeneralDialplan.php';

/** BLF lamps for do-not-disturb, call forwarding and queue login (roadmap 8). */
final class LampServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_users WHERE extension IN ('7021', '7022')");
        $this->db->exec("DELETE FROM pbx_lamp_states");
        $this->db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES ('user', 'User', 0)");
        $ins = $this->db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active, role, dnd_enabled, call_forward_number)
                                   VALUES (?, '', ?, ?, 'sip', 1, 'user', ?, ?)");
        $ins->execute(['lamp1', 'Lamp One', '7021', 1, null]);
        $ins->execute(['lamp2', 'Lamp Two', '7022', 0, '05551234567']);
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE extension IN ('7021', '7022')");
        $this->db->exec("DELETE FROM pbx_lamp_states");
    }

    public function testDesiredStates(): void
    {
        $d = LampService::desired();
        $this->assertSame('INUSE', $d['DND7021']);
        $this->assertSame('NOT_INUSE', $d['CF7021']);
        $this->assertSame('NOT_INUSE', $d['DND7022']);
        $this->assertSame('INUSE', $d['CF7022']);
        $this->assertSame('NOT_INUSE', $d['QUEUE7021']);
    }

    public function testQueueShowParsing(): void
    {
        $show = "sales has 0 calls (max unlimited) in 'ringall' strategy\n"
            . "   Members: \n"
            . "      Agent 7021 (Local/7021@from-internal-pbx/n from hint:7021@from-internal-pbx) (ringinuse disabled) (dynamic) (Not in use) has taken no calls yet\n"
            . "      Agent 7022 (Local/7022@from-internal-pbx/n from hint:7022@from-internal-pbx) (ringinuse disabled) (dynamic) (paused:Lunch was 12 secs ago) (Not in use) has taken no calls yet\n";
        $this->assertSame(['7021' => true], LampService::loggedInToQueues($show));
    }

    public function testOnlyChangedLampsAreSent(): void
    {
        $first = LampService::refresh();
        $this->assertGreaterThanOrEqual(6, $first, 'first run sends every lamp');
        $this->assertSame(0, LampService::refresh(), 'nothing changed');

        $this->db->exec("UPDATE sys_users SET dnd_enabled = 0 WHERE extension = '7021'");
        $this->assertSame(1, LampService::refresh());
        $this->assertSame('NOT_INUSE', $this->db->query("SELECT state FROM pbx_lamp_states WHERE name = 'DND7021'")->fetchColumn());

        $this->assertGreaterThanOrEqual(6, LampService::refresh(true), 'force sends all');
    }

    public function testDialplanHasLampHintsAndOwnerOnlyToggle(): void
    {
        syncGeneralDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        foreach (['DND', 'CF', 'QUEUE'] as $k) {
            $this->assertStringContainsString("exten => {$k}7021,hint,Custom:{$k}7021", $conf);
            $this->assertStringContainsString("exten => {$k}7021,1,Gosub(aipbx-lamp-toggle,s,1(" . strtolower($k) . ",7021))", $conf);
        }
        $this->assertStringContainsString('[aipbx-lamp-toggle]', $conf);
        $this->assertStringContainsString('GotoIf($["${CUT(CHANNEL(endpoint),-,1)}" != "${ARG2}"]?deny)', $conf);
    }
}
