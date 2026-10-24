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

    public function testOnlySecurityLibrariesCountAsReplaced(): void
    {
        // The production mail: libudev and libsystemd were updated on a quiet weekend.
        $this->assertSame([], AsteriskHelper::replacedSecurityLibraries(['libudev.so.1.7.12', 'libsystemd.so.0.42.0', 'libcap.so.2.75']));
        $this->assertSame(['libssl.so.3', 'libc.so.6'], AsteriskHelper::replacedSecurityLibraries(['libssl.so.3', 'libsystemd.so.0', 'libc.so.6']));
    }

    public function testJitterBufferLineIsSharedByEveryDial(): void
    {
        // One helper for extension, general and outbound dials: off means no line at all.
        setSystemSetting('jitterbuffer_enabled', '0');
        $this->assertSame('', jitterBufferLine());

        setSystemSetting('jitterbuffer_enabled', '1');
        setSystemSetting('jitterbuffer_max_size', '200');
        setSystemSetting('jitterbuffer_initial_size', '100');
        $this->assertStringContainsString('Set(JITTERBUFFER(adaptive)=default)', jitterBufferLine());
        $this->assertStringContainsString('${AIPBX_FAX}', jitterBufferLine(), 'faxes are exempt');

        setSystemSetting('jitterbuffer_initial_size', '150');
        $this->assertStringContainsString('Set(JITTERBUFFER(adaptive)=200,150)', jitterBufferLine());
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

    public function testNightRestartWindowAndFailSafe(): void
    {
        $this->assertTrue(NightRestartService::inWindow('03:30', '03:00', '05:00'));
        $this->assertFalse(NightRestartService::inWindow('05:01', '03:00', '05:00'));
        $this->assertTrue(NightRestartService::inWindow('23:30', '23:00', '04:00'), 'across midnight');
        $this->assertTrue(NightRestartService::inWindow('02:00', '23:00', '04:00'));
        $this->assertFalse(NightRestartService::inWindow('12:00', '23:00', '04:00'));

        setSystemSetting('night_restart_enabled', '0');
        $this->assertFalse(NightRestartService::isDue(), 'switched off');

        // Switched on and inside the window, but nothing needs a restart (no
        // replaced libraries, and in tests the call count cannot be read):
        // never restart then.
        setSystemSetting('night_restart_enabled', '1');
        setSystemSetting('night_restart_window_start', '00:00');
        setSystemSetting('night_restart_window_end', '23:59');
        setSystemSetting('night_restart_last_date', '');
        $this->assertFalse(NightRestartService::isDue());
        $this->assertFalse(NightRestartService::executeRestart());
        $this->assertSame('', (string) getSystemSetting('night_restart_last_date', ''));
        setSystemSetting('night_restart_enabled', '0');
    }

    public function testFailedRegistrationsHealthCheck(): void
    {
        setSystemSetting('failed_reg_threshold', '50');
        [$status, $msg] = HealthChecks::failedRegistrations();
        $this->assertContains($status, ['ok', 'off'], 'no alarm (off when the log cannot be read)');
        $line = 'SecurityEvent="ChallengeResponseFailed",EventTV="' . date('Y-m-d\\TH:i:s.000O') . '"';
        $old = 'SecurityEvent="InvalidPassword",EventTV="' . date('Y-m-d\\TH:i:s.000O', time() - 3600) . '"';
        $this->assertSame(1, HealthChecks::countRecentFailures($line . "\n" . $old, time() - 900), 'only the last 15 minutes count');
    }
}
