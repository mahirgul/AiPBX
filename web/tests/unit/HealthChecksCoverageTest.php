<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/health/HealthChecks.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_helper.php';

final class HealthChecksCoverageTest extends TestCase
{
    private const TEST_NOTICE_KEY = 'zz_cov_notice_7905';
    private const TEST_SYNC_ENTITY = 'zz_cov_sync_7905';

    protected function tearDown(): void
    {
        $db = getDB();
        $db->prepare("DELETE FROM sys_notices WHERE notice_key = ?")->execute([self::TEST_NOTICE_KEY]);
        $db->prepare("DELETE FROM sys_pending_sync WHERE entity_id = ?")->execute([self::TEST_SYNC_ENTITY]);
        $db->exec("DELETE FROM fax_sent WHERE destination_number = '7905999'");
    }

    public function testCountRecentFailures(): void
    {
        $log = <<<'LOG'
SecurityEvent="InvalidAccountID",EventTV="2026-10-25T01:00:00.000+0200",Severity="Error"
SecurityEvent="InvalidPassword",EventTV="2026-10-25T01:10:00.000+0200",Severity="Error"
SecurityEvent="ChallengeResponseFailed",EventTV="2026-10-25T01:20:00.000+0200",Severity="Error"
Some other log line without event tv
LOG;

        $t05 = (int)strtotime('2026-10-25T01:05:00+0200');
        $t15 = (int)strtotime('2026-10-25T01:15:00+0200');
        $t25 = (int)strtotime('2026-10-25T01:25:00+0200');

        $this->assertSame(2, HealthChecks::countRecentFailures($log, $t05));
        $this->assertSame(1, HealthChecks::countRecentFailures($log, $t15));
        $this->assertSame(0, HealthChecks::countRecentFailures($log, $t25));
        $this->assertSame(0, HealthChecks::countRecentFailures('', $t05));
    }

    public function testCallRecordsStatusThresholds(): void
    {
        // 1. Asterisk uptime < 900 seconds: just started, no alarm
        [$status1, $msg1] = HealthChecks::callRecordsStatus(300, 10, 0);
        $this->assertSame('ok', $status1);
        $this->assertStringContainsString('0', $msg1);

        // 2. Asterisk uptime >= 900 seconds but processed calls < 3: no alarm
        [$status2, $msg2] = HealthChecks::callRecordsStatus(1200, 2, 0);
        $this->assertSame('ok', $status2);

        // 3. Asterisk uptime >= 900 seconds, processed >= 3, but records === 0: failure!
        [$status3, $msg3] = HealthChecks::callRecordsStatus(1200, 10, 0);
        $this->assertSame('fail', $status3);
        $this->assertStringContainsString('10', $msg3);

        // 4. Normal operation: records exist
        [$status4, $msg4] = HealthChecks::callRecordsStatus(1200, 10, 8);
        $this->assertSame('ok', $status4);
        $this->assertStringContainsString('8', $msg4);
    }

    public function testTrunkLimitsStatusThresholds(): void
    {
        $now = 1_700_000_900;

        // 1. Output without "results found" -> unread / info
        [$status1] = HealthChecks::trunkLimitsStatus('CLI error or empty', $now);
        $this->assertSame('info', $status1);

        // 2. Output with 0 results found -> ok
        [$status2, $msg2] = HealthChecks::trunkLimitsStatus("0 results found.\n", $now);
        $this->assertSame('ok', $status2);
        $this->assertSame(t('health.msg_trunk_limits_ok'), $msg2);

        // 3. Refusal within 900 seconds -> warn
        $astdbRecent = "/trunkblock/TrunkNectip : 1700000000:15:rate:1700000850\n1 results found.\n";
        [$status3, $msg3] = HealthChecks::trunkLimitsStatus($astdbRecent, $now);
        $this->assertSame('warn', $status3);
        $this->assertStringContainsString('TrunkNectip', $msg3);
        $this->assertStringContainsString('15', $msg3);

        // 4. Refusal older than 900 seconds -> ok
        $astdbOld = "/trunkblock/TrunkOld : 1700000000:15:channels:1699999000\n1 results found.\n";
        [$status4, $msg4] = HealthChecks::trunkLimitsStatus($astdbOld, $now);
        $this->assertSame('ok', $status4);
        $this->assertSame(t('health.msg_trunk_limits_ok'), $msg4);
    }

    public function testByteFormatting(): void
    {
        $this->assertSame('500 B', HealthChecks::bytes(500.0));
        $this->assertSame('1.0 KB', HealthChecks::bytes(1024.0));
        $this->assertSame('1.5 KB', HealthChecks::bytes(1536.0));
        $this->assertSame('1.0 MB', HealthChecks::bytes(1048576.0));
        $this->assertSame('1.0 GB', HealthChecks::bytes(1073741824.0));
        $this->assertSame('1.0 TB', HealthChecks::bytes(1099511627776.0));
        $this->assertSame('2.5 TB', HealthChecks::bytes(2748779069440.0));
    }

    public function testDurationFormatting(): void
    {
        $this->assertSame('0m', HealthChecks::duration(0));
        $this->assertSame('0m', HealthChecks::duration(59));
        $this->assertSame('1m', HealthChecks::duration(60));
        $this->assertSame('59m', HealthChecks::duration(3599));
        $this->assertSame('1h 0m', HealthChecks::duration(3600));
        $this->assertSame('1h 1m', HealthChecks::duration(3665));
        $this->assertSame('1d 0h', HealthChecks::duration(86400));
        $this->assertSame('2d 3h', HealthChecks::duration(183600));
    }

    public function testAsteriskHelperReplacedSecurityLibrariesFiltering(): void
    {
        $securityLibs = [
            'libssl.so.3',
            'libcrypto.so.3',
            'libgnutls.so.30',
            'libnettle.so.8',
            'libhogweed.so.6',
            'libsrtp.so.1',
            'libsrtp2.so.1',
            'libc.so.6',
            'libcurl.so.4',
            'libxml2.so.2',
            'libnghttp2.so.14',
            'libssh.so.4',
            'libjansson.so.4',
            'libpcre2-8.so.0',
            'libpcre-posix.so.3',
            'libsqlite3.so.0',
            'libz.so.1',
        ];

        $nonSecurityLibs = [
            'libpcre.so.3',
            'libudev.so.1',
            'libsystemd.so.0',
            'libvorbis.so.0',
            'libasound.so.2',
            'libspeex.so.1',
            'libogg.so.0',
            'libm.so.6',
            'custom_addon.so',
        ];

        $mixed = array_merge($securityLibs, $nonSecurityLibs);
        $filtered = AsteriskHelper::replacedSecurityLibraries($mixed);

        $this->assertSame($securityLibs, $filtered, 'Security libraries must be kept, non-security discarded');
    }

    public function testAlertsCheckFromOpenNotices(): void
    {
        $db = getDB();
        $existing = $db->query("SELECT notice_key FROM sys_notices WHERE resolved_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec("UPDATE sys_notices SET resolved_at = NOW() WHERE resolved_at IS NULL");

        try {
            // 1. Without notices -> ok
            $db->prepare("DELETE FROM sys_notices WHERE notice_key = ?")->execute([self::TEST_NOTICE_KEY]);
            [$statusOk] = HealthChecks::run('alerts');
            $this->assertSame('ok', $statusOk);

            // 2. With warning notice -> warn
            $ins = $db->prepare("INSERT INTO sys_notices (notice_key, severity, title, message, source, raised_at) VALUES (?, ?, ?, ?, 'core', NOW()) ON DUPLICATE KEY UPDATE severity = VALUES(severity), title = VALUES(title), message = VALUES(message), resolved_at = NULL");
            $ins->execute([self::TEST_NOTICE_KEY, 'warn', 'Disk Alert', 'Disk is getting full']);
            [$statusWarn, $msgWarn] = HealthChecks::run('alerts');
            $this->assertSame('warn', $statusWarn);
            $this->assertStringContainsString('Disk Alert', $msgWarn);

            // 3. With failure notice -> fail
            $ins->execute([self::TEST_NOTICE_KEY, 'fail', 'Critical Trunk Down', 'Trunk cannot register']);
            [$statusFail, $msgFail] = HealthChecks::run('alerts');
            $this->assertSame('fail', $statusFail);
            $this->assertStringContainsString('Critical Trunk Down', $msgFail);

            // Clean up
            $db->prepare("DELETE FROM sys_notices WHERE notice_key = ?")->execute([self::TEST_NOTICE_KEY]);
            [$statusAfter] = HealthChecks::run('alerts');
            $this->assertSame('ok', $statusAfter);
        } finally {
            $db->prepare("DELETE FROM sys_notices WHERE notice_key = ?")->execute([self::TEST_NOTICE_KEY]);
            if (!empty($existing)) {
                $placeholders = implode(',', array_fill(0, count($existing), '?'));
                $db->prepare("UPDATE sys_notices SET resolved_at = NULL WHERE notice_key IN ($placeholders)")->execute($existing);
            }
        }
    }

    public function testDatabaseCheckDecision(): void
    {
        [$status, $msg] = HealthChecks::run('database');
        $this->assertSame('ok', $status);
        $this->assertMatchesRegularExpression('/\d+/', $msg);
    }

    public function testPendingChangesCheck(): void
    {
        $db = getDB();

        // 1. Without pending changes -> ok
        [$status1] = HealthChecks::run('pending_changes');
        $this->assertSame('ok', $status1);

        // 2. With an old pending sync row (> 1 hour) -> warn
        $ins = $db->prepare("INSERT INTO sys_pending_sync (domain, entity_type, entity_id, entity_label, action, changed_at) VALUES ('extensions', 'extension', ?, 'Ext 7905', 'update', NOW() - INTERVAL 2 HOUR)");
        $ins->execute([self::TEST_SYNC_ENTITY]);

        [$status2, $msg2] = HealthChecks::run('pending_changes');
        $this->assertSame('warn', $status2);
        $this->assertMatchesRegularExpression('/\d+/', $msg2);

        // Clean up
        $db->prepare("DELETE FROM sys_pending_sync WHERE entity_id = ?")->execute([self::TEST_SYNC_ENTITY]);
        [$status3] = HealthChecks::run('pending_changes');
        $this->assertSame('ok', $status3);
    }

    public function testFaxQueueCheck(): void
    {
        $db = getDB();

        // 1. Without stuck faxes -> ok
        [$status1] = HealthChecks::run('fax_queue');
        $this->assertSame('ok', $status1);

        // 2. With stuck pending fax (> 1 hour old) -> warn
        $stmt = $db->prepare("INSERT INTO fax_sent (user_id, sender_extension, destination_number, pdf_path, tif_path, status, created_at) VALUES (1, '7905', '7905999', '/tmp/fake.pdf', '/tmp/fake.tif', 'PENDING', NOW() - INTERVAL 2 HOUR)");
        $stmt->execute();

        [$status2, $msg2] = HealthChecks::run('fax_queue');
        $this->assertSame('warn', $status2);
        $this->assertMatchesRegularExpression('/\d+/', $msg2);

        // Clean up
        $db->exec("DELETE FROM fax_sent WHERE destination_number = '7905999'");
        [$status3] = HealthChecks::run('fax_queue');
        $this->assertSame('ok', $status3);
    }

    public function testUnknownCheckReturnsFail(): void
    {
        [$status, $msg] = HealthChecks::run('zz_cov_nonexistent_check');
        $this->assertSame('fail', $status);
        $this->assertSame('unknown check', $msg);
    }

    public function testConstantsAndIntervalsValidity(): void
    {
        $this->assertSame(['ok', 'warn', 'fail', 'info', 'off', 'unknown'], HealthChecks::STATUSES);
        $this->assertSame([0, 5, 15, 60, 360, 1440], HealthChecks::INTERVALS);
        $this->assertCount(22, HealthChecks::DEFAULTS);

        foreach (HealthChecks::DEFAULTS as $k => $interval) {
            $this->assertContains($interval, HealthChecks::INTERVALS);
        }
    }
}
