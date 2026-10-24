<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/AsteriskSettingsService.php';
require_once dirname(__DIR__, 2) . '/src/services/NightRestartService.php';
require_once dirname(__DIR__, 2) . '/src/services/health/HealthChecks.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncTransports.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncGeneralDialplan.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';

final class PbxFineTuningTest extends TestCase
{
    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'pjsip_max_forwards', 'pjsip_timer_t1', 'pjsip_timer_b',
            'jitterbuffer_enabled', 'jitterbuffer_max_size', 'jitterbuffer_initial_size',
            'night_restart_enabled', 'night_restart_window_start', 'night_restart_window_end',
            'night_restart_last_date', 'failed_reg_threshold', 'sip_debug_until'
        ];
        foreach ($keys as $k) {
            $this->savedSettings[$k] = getSystemSetting($k, '');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $k => $v) {
            setSystemSetting($k, $v);
        }
        parent::tearDown();
    }

    public function testSipTimersAndMaxForwardsInTransportsConf(): void
    {
        setSystemSetting('pjsip_max_forwards', '85');
        setSystemSetting('pjsip_timer_t1', '650');
        setSystemSetting('pjsip_timer_b', '40000');

        syncTransports();

        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/pjsip_transports.conf');
        $this->assertStringContainsString("[system]\ntype=system\ntimer_t1=650\ntimer_b=40000\n", $conf);
        $this->assertStringContainsString("max_forwards=85\n", $conf);
    }

    public function testJitterBufferDisabledOmitsJitterbufferSetting(): void
    {
        setSystemSetting('jitterbuffer_enabled', '0');

        syncGeneralDialplan();

        $general = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $this->assertStringNotContainsString('Set(JITTERBUFFER(adaptive)', $general);
    }

    public function testJitterBufferCustomSizesAreApplied(): void
    {
        setSystemSetting('jitterbuffer_enabled', '1');
        setSystemSetting('jitterbuffer_max_size', '350');
        setSystemSetting('jitterbuffer_initial_size', '150');

        syncGeneralDialplan();

        $general = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        $this->assertStringContainsString('Set(JITTERBUFFER(adaptive)=350,150)', $general);
    }

    public function testSipDebugTenMinutesActivationAndExpiry(): void
    {
        $res = AsteriskSettingsService::enableSipDebug(600);
        $this->assertTrue($res['success']);
        $until = (int)getSystemSetting('sip_debug_until', '0');
        $this->assertGreaterThan(time(), $until);

        // Advance expiry to past
        setSystemSetting('sip_debug_until', (string)(time() - 10));
        AsteriskSettingsService::checkSipDebugExpiry();

        $untilAfter = (int)getSystemSetting('sip_debug_until', '0');
        $this->assertSame(0, $untilAfter);

        // Test manual disable
        AsteriskSettingsService::enableSipDebug(600);
        $resDisable = AsteriskSettingsService::disableSipDebug();
        $this->assertTrue($resDisable['success']);
        $this->assertSame('0', (string)getSystemSetting('sip_debug_until', '0'));
    }

    public function testRotateLogsCallsCli(): void
    {
        $res = AsteriskSettingsService::rotateLogs();
        $this->assertTrue($res['success']);
    }

    public function testNightRestartLogic(): void
    {
        setSystemSetting('night_restart_enabled', '0');
        $this->assertFalse(NightRestartService::isDue(), 'Disabled night restart is not due');

        setSystemSetting('night_restart_enabled', '1');
        // Set window to encompass current hour:minute
        $currentMin = (int)date('i');
        $startH = (int)date('H');
        $endH = ($startH + 1) % 24;
        setSystemSetting('night_restart_window_start', sprintf('%02d:00', $startH));
        setSystemSetting('night_restart_window_end', sprintf('%02d:59', $startH));
        setSystemSetting('night_restart_last_date', '');

        // In test mode, active calls are mocked to 0 via AsteriskHelper test lock
        $this->assertTrue(NightRestartService::isDue());

        $executed = NightRestartService::executeRestart();
        $this->assertTrue($executed);
        $this->assertSame(date('Y-m-d'), (string)getSystemSetting('night_restart_last_date', ''));

        // Calling again today should return false
        $this->assertFalse(NightRestartService::isDue(), 'Already restarted today');
    }

    public function testFailedRegistrationsHealthCheck(): void
    {
        setSystemSetting('failed_reg_threshold', '50');
        [$status, $msg] = HealthChecks::failedRegistrations();
        $this->assertSame('ok', $status);
        $this->assertStringContainsString('failed registration(s)', $msg);
    }
}
