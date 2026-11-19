<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/services/TimeConditionService.php';
require_once __DIR__ . '/../../src/sync/SyncTimeConditions.php';
require_once __DIR__ . '/../../src/services/GoogleAuthService.php';

final class TimeConditionTimezoneAndMobileNonceTest extends TestCase
{
    public function testMobileNonceIssueVerifyAndSingleUse(): void
    {
        $issued = GoogleAuthService::issueMobileNonce();
        $nonce = $issued['nonce'];
        $this->assertStringStartsWith(GoogleAuthService::MOBILE_NONCE_PREFIX, $nonce);
        $this->assertSame(600, $issued['expires_in']);

        // Matching raw nonce succeeds once, then replay is rejected.
        $this->assertTrue(GoogleAuthService::verifyMobileNonce($nonce, $nonce));
        $this->assertFalse(GoogleAuthService::verifyMobileNonce($nonce, $nonce));

        // SHA-256 hashed nonce in ID token claim also works once.
        $issued2 = GoogleAuthService::issueMobileNonce();
        $nonce2 = $issued2['nonce'];
        $this->assertTrue(GoogleAuthService::verifyMobileNonce(hash('sha256', $nonce2), $nonce2));
        $this->assertFalse(GoogleAuthService::verifyMobileNonce(hash('sha256', $nonce2), $nonce2));

        // Server-issued apx_ nonce in token without expectedNonce is still single-use.
        $issued3 = GoogleAuthService::issueMobileNonce();
        $nonce3 = $issued3['nonce'];
        $this->assertTrue(GoogleAuthService::verifyMobileNonce($nonce3));
        $this->assertFalse(GoogleAuthService::verifyMobileNonce($nonce3));

        // Expected nonce mismatch fails.
        $issued4 = GoogleAuthService::issueMobileNonce();
        $this->assertFalse(GoogleAuthService::verifyMobileNonce('apx_wrong', $issued4['nonce']));
        // Clean up unused nonce4.
        $this->assertTrue(GoogleAuthService::verifyMobileNonce($issued4['nonce'], $issued4['nonce']));
    }

    public function testTimeConditionRejectsInvalidTimezone(): void
    {
        $_SESSION['csrf_token'] = 'test_csrf_tc_tz';
        $res = TimeConditionService::saveTimeCondition([
            'csrf_token' => 'test_csrf_tc_tz',
            'tc_id' => 0,
            'title' => 'Invalid TZ Test',
            'timezone' => 'Mars/Olympus',
            'nomatch_dest_type' => 'hangup',
            'nomatch_dest_id' => '',
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('timezone', strtolower((string) ($res['error'] ?? '')));
    }

    public function testTimeConditionSyncEmitsTimezoneInGotoIfTimeAndStrftime(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO pbx_time_groups (title, time_start, time_end, days_of_week, holidays_json) VALUES (?, ?, ?, ?, ?)")
            ->execute(['Unit TZ Group', '09:00:00', '18:00:00', 'mon-fri', '["2026-12-25"]']);
        $tgId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO pbx_time_conditions (title, time_group_id, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, timezone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute(['Unit TZ TC', $tgId, 'hangup', '', 'hangup', '', 'Europe/Istanbul']);
        $tcId = (int) $db->lastInsertId();

        $pbxDir = getenv('ASTERISK_PBX_DIR') ?: '/etc/asterisk';
        $confFile = rtrim($pbxDir, '/') . "/extensions_timecondition_{$tcId}.conf";
        try {
            syncTimeCondition($tcId);
            $this->assertFileExists($confFile);
            $conf = (string) file_get_contents($confFile);
            $this->assertStringContainsString('STRFTIME(${EPOCH},Europe/Istanbul,%Y-%m-%d)', $conf);
            $this->assertStringContainsString('GotoIfTime(09:00-18:00,*,*,*,Europe/Istanbul?tc_rule_0)', $conf);
        } finally {
            @unlink($confFile);
            $db->prepare("DELETE FROM pbx_time_conditions WHERE id = ?")->execute([$tcId]);
            $db->prepare("DELETE FROM pbx_time_groups WHERE id = ?")->execute([$tgId]);
        }
    }
}
