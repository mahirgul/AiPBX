<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/PrivacyService.php';
require_once dirname(__DIR__, 2) . '/src/services/ai/CloudAiService.php';

final class PrivacyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('tests must run only on asterisk_test');
        }
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'privacy_%'");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'privacy'");
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'privacy'");
    }

    public function testSaveRetentionAndRunPurge(): void
    {
        $res = PrivacyService::saveRetention([
            'retention_enabled'   => '1',
            'ret_recordings_days' => '90',
            'ret_voicemail_days'  => '60',
            'ret_fax_days'        => '120',
            'ret_chat_days'       => '180',
            'ret_cdr_days'        => '365',
            'ret_cdr_action'      => 'anonymize',
        ]);
        $this->assertTrue($res['success']);
        $this->assertArrayHasKey('message', $res);

        $badCsrf = PrivacyService::saveRetention([
            'csrf_token'          => 'invalid-token',
            'retention_enabled'   => '0',
        ]);
        $this->assertFalse($badCsrf['success']);
        $this->assertArrayHasKey('error', $badCsrf);

        $cfg = PrivacyService::settings();
        $this->assertTrue($cfg['retention_enabled']);
        $this->assertSame(90, $cfg['ret_recordings_days']);
        $this->assertSame(365, $cfg['ret_cdr_days']);
        $this->assertSame('anonymize', $cfg['ret_cdr_action']);

        $purge = PrivacyService::runRetentionPurge();
        $this->assertArrayHasKey('counts', $purge);
        $this->assertNotEmpty(PrivacyService::settings()['last_purge_at']);
    }

    public function testApplyGlobalJurisdictionPresetsAndPeriodicBeep(): void
    {
        $res = PrivacyService::applyProfile(['jurisdiction_profile' => 'us_two_party']);
        $this->assertArrayHasKey('message', $res);
        $cfg = PrivacyService::settings();
        $this->assertSame('us_two_party', $cfg['jurisdiction_profile']);
        $this->assertSame(15, $cfg['recording_beep_sec']);
        $this->assertSame(',B(15)', PrivacyService::mixMonitorOptionSuffix());

        $hipaa = PrivacyService::applyProfile(['jurisdiction_profile' => 'hipaa']);
        $this->assertArrayHasKey('message', $hipaa);
        $this->assertTrue(PrivacyService::settings()['block_cloud_ai']);
        $this->assertFalse(CloudAiService::configured('openai'));
    }

    public function testSubjectLookupAndRightToErasureAnonymizesCdrs(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM asteriskcdr WHERE uniqueid = 'test-privacy-sar-1'");
        $stmt = $db->prepare(
            "INSERT INTO asteriskcdr (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, duration, billsec, disposition, amaflags, accountcode, uniqueid, userfield)
             VALUES (NOW(), '\"Test Caller\" <05329998877>', '05329998877', '1000', 'from-trunk-inbound', 'PJSIP/trunk-1', 'PJSIP/1000-1', 'Dial', 'PJSIP/1000', 45, 40, 'ANSWERED', 3, '', 'test-privacy-sar-1', '')"
        );
        $stmt->execute();

        $lookup = PrivacyService::lookupSubject('05329998877');
        $this->assertNotNull($lookup);
        $this->assertGreaterThanOrEqual(1, $lookup['cdr_count']);

        $erased = PrivacyService::eraseSubject('05329998877');
        $this->assertArrayHasKey('message', $erased);

        $row = $db->query("SELECT src, clid FROM asteriskcdr WHERE uniqueid = 'test-privacy-sar-1'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('REDACTED', $row['src']);
        $this->assertSame('REDACTED', $row['clid']);

        $db->exec("DELETE FROM asteriskcdr WHERE uniqueid = 'test-privacy-sar-1'");
    }

    public function testCrossBorderCloudAiLockBlocksCloudProviders(): void
    {
        PrivacyService::saveCompliance(['block_cloud_ai' => '1']);
        $this->assertTrue(PrivacyService::settings()['block_cloud_ai']);
        $this->assertFalse(CloudAiService::configured('openai'));

        PrivacyService::saveCompliance([]);
        $this->assertFalse(PrivacyService::settings()['block_cloud_ai']);
    }

    public function testAllRulesOffByDefaultAndTranscriptRedaction(): void
    {
        $cfg = PrivacyService::settings();
        $this->assertSame('off', $cfg['jurisdiction_profile']);
        $this->assertSame('off', $cfg['notice_mode']);
        $this->assertFalse($cfg['pci_pause_enabled']);
        $this->assertFalse($cfg['retention_enabled']);
        $this->assertFalse($cfg['block_cloud_ai']);
        $this->assertSame(0, $cfg['recording_beep_sec']);

        $raw = 'My card is 4532 0151 1283 0366, TCKN 12345678901, IBAN TR330006100519786457841326 and email test@example.com.';
        // When profile is 'off' and pci_pause_enabled is false, transcript is untouched
        $this->assertSame($raw, PrivacyService::redactTranscriptIfEnabled($raw));

        // Enable KVKK or PCI-DSS -> transcript is automatically redacted
        PrivacyService::applyProfile(['jurisdiction_profile' => 'kvkk']);
        $redacted = PrivacyService::redactTranscriptIfEnabled($raw);
        $this->assertStringContainsString('[REDACTED-CARD]', $redacted);
        $this->assertStringContainsString('[REDACTED-ID]', $redacted);
        $this->assertStringContainsString('[REDACTED-IBAN]', $redacted);
        $this->assertStringContainsString('[REDACTED-EMAIL]', $redacted);
        $this->assertStringNotContainsString('4532 0151 1283 0366', $redacted);
    }

    public function testDialplanNoticeConfigAndComplianceOverviewUsePbxAnnouncementsAndTwoFactorEnabled(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_announcements WHERE title = 'Privacy Test Notice'");
        $stmt = $db->prepare("INSERT INTO pbx_announcements (title, audio_file, is_active) VALUES (?, ?, 1)");
        $stmt->execute(['Privacy Test Notice', 'custom/privacy_test_notice']);
        $soundId = (int)$db->lastInsertId();

        $res = PrivacyService::saveNotice([
            'notice_mode'      => 'optout',
            'notice_sound_id'  => (string)$soundId,
            'optout_digit'     => '9',
            'optout_timeout'   => '4',
            'recording_beep_sec' => '15',
            'pci_pause_enabled'  => '1',
        ]);
        $this->assertTrue($res['success']);

        $cfg = PrivacyService::dialplanNoticeConfig();
        $this->assertNotNull($cfg);
        $this->assertSame('optout', $cfg['mode']);
        $this->assertSame('custom/privacy_test_notice', $cfg['sound_path']);
        $this->assertSame('9', $cfg['optout_digit']);
        $this->assertSame(4, $cfg['optout_timeout']);

        $db->exec("DELETE FROM sys_users WHERE username = 'privacy_test_admin'");
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, is_active, two_factor_enabled) VALUES ('privacy_test_admin', 'x', 'Privacy Admin', 'admin', 1, 1)")->execute();

        $overview = PrivacyService::complianceOverview();
        $this->assertGreaterThanOrEqual(1, count($overview['sounds']));
        $this->assertGreaterThanOrEqual(1, $overview['admin_total_count']);
        $this->assertGreaterThanOrEqual(1, $overview['admin_2fa_count']);

        $db->exec("DELETE FROM sys_users WHERE username = 'privacy_test_admin'");
        $db->prepare("DELETE FROM pbx_announcements WHERE id = ?")->execute([$soundId]);
    }
}
