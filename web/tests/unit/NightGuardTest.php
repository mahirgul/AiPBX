<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/NightGuardService.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';

/** Dial permissions → Night dialling protection. */
final class NightGuardTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'night_guard_%'");
        $_SESSION['csrf_token'] = 'ng';
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'night_guard_%'");
    }

    public function testOffByDefault(): void
    {
        $this->assertSame('', NightGuardService::dialplan());
        syncPermissions();
        $this->assertStringNotContainsString('sub-night-guard', (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_permissions.conf'));
    }

    public function testBlockAcrossMidnightAndAllWeekend(): void
    {
        $res = NightGuardService::save(['csrf_token' => 'ng', 'night_guard_enabled' => '1', 'night_guard_from' => '20:00', 'night_guard_to' => '08:00',
            'night_guard_weekend' => '1', 'night_guard_prefixes' => '00, 0900;junk', 'night_guard_action' => 'block']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $d = NightGuardService::dialplan();
        $this->assertStringContainsString('GotoIfTime(20:00-23:59,*,*,*?night)', $d);
        $this->assertStringContainsString('GotoIfTime(00:00-08:00,*,*,*?night)', $d);
        $this->assertStringContainsString('GotoIfTime(*,sat-sun,*,*?night)', $d);
        $this->assertStringContainsString('GotoIf($["${ARG1:0:2}" = "00"]?guard)', $d);
        $this->assertStringContainsString('GotoIf($["${ARG1:0:4}" = "0900"]?guard)', $d);
        $this->assertStringContainsString("Progress()\n same => n,Playback(cannot-complete-as-dialed,noanswer)", $d);
        $this->assertStringContainsString('GotoIf($["${AIPBX_FAX}" = "1"]?ok)', $d, 'portal faxes are exempt');

        syncPermissions();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_permissions.conf');
        $this->assertStringContainsString('Gosub(sub-night-guard,s,1(${ARG2}))', $conf);
    }

    public function testPinNeedsFourDigitsAndIsKeptWhenLeftEmpty(): void
    {
        $base = ['csrf_token' => 'ng', 'night_guard_enabled' => '1', 'night_guard_prefixes' => '00', 'night_guard_action' => 'pin'];
        $this->assertFalse(NightGuardService::save($base + ['night_guard_pin' => '12'])['success']);
        $this->assertTrue(NightGuardService::save($base + ['night_guard_pin' => '4711'])['success']);
        $this->assertTrue(NightGuardService::save($base + ['night_guard_pin' => ''])['success']);
        $this->assertStringContainsString('Authenticate(4711)', NightGuardService::dialplan());
    }

    public function testDaytimeRangeWithoutWeekend(): void
    {
        NightGuardService::save(['csrf_token' => 'ng', 'night_guard_enabled' => '1', 'night_guard_from' => '12:00', 'night_guard_to' => '13:00',
            'night_guard_weekend' => '0', 'night_guard_prefixes' => '00']);
        $d = NightGuardService::dialplan();
        $this->assertStringContainsString('GotoIfTime(12:00-13:00,*,*,*?night)', $d);
        $this->assertStringNotContainsString('sat-sun', $d);
    }
}
