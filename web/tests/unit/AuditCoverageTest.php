<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Fixtures.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/helpers.php';

/** Changes that used to leave no trace in Security → Audit log. */
final class AuditCoverageTest extends TestCase
{
    private const CSRF = 'audit-coverage';

    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION['csrf_token'] = self::CSRF;
        $_SESSION['user_id'] = null;
    }

    private function lastAudit(): array
    {
        return getDB()->query('SELECT entity_type, entity_label, action FROM sys_audit_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testDeletingATimeGroupIsLogged(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO pbx_time_groups (title, time_start, time_end, days_of_week, is_active) VALUES ('Audit test group', '08:00', '17:00', '1,2,3', 1)");
        $id = (int) $db->lastInsertId();
        $res = TimeConditionService::deleteTimeGroup($id, self::CSRF);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');
        $this->assertSame(['entity_type' => 'time_group', 'entity_label' => 'Audit test group', 'action' => 'delete'], $this->lastAudit());
    }

    public function testGoogleSignInSettingsAreLoggedWithoutTheSecret(): void
    {
        $res = GoogleAuthService::saveSettings(['google_oauth_enabled' => '1', 'google_client_id' => 'id.apps.example', 'google_client_secret' => 'very-secret-value']);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');
        $row = $this->lastAudit();
        $this->assertSame('settings', $row['entity_type']);
        $this->assertStringContainsString('client secret changed', $row['entity_label']);
        $this->assertStringNotContainsString('very-secret-value', (string) json_encode(getDB()->query('SELECT * FROM sys_audit_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC)));
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'google_%'");
    }

    public function testRemovingAWaitingPhoneIsLogged(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO pbx_phones_waiting (mac, vendor, ip, user_agent, request_count, first_seen_at, last_seen_at) VALUES ('001122aabbcc', 'test', '192.0.2.5', 'ua', 1, NOW(), NOW())");
        $id = (int) $db->lastInsertId();
        $res = PhoneProvisionService::deleteWaiting($id, self::CSRF);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');
        $row = $this->lastAudit();
        $this->assertSame(['phone_waiting', 'delete'], [$row['entity_type'], $row['action']]);
        $this->assertStringContainsStringIgnoringCase('00:11:22:aa:bb:cc', $row['entity_label']);
    }
}
