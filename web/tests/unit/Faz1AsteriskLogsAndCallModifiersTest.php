<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/services/AsteriskLogService.php';
require_once __DIR__ . '/../../src/services/CallModifierService.php';
require_once __DIR__ . '/../../src/sync/DialplanDestinationBuilder.php';

final class Faz1AsteriskLogsAndCallModifiersTest extends TestCase
{
    public function testAsteriskLogServiceFiltersAndMasksSecrets(): void
    {
        $tmpDir = sys_get_temp_dir() . '/aipbx_log_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $logFile = $tmpDir . '/messages.log';
        file_put_contents($logFile, implode("\n", [
            '[2026-11-18 10:00:00] NOTICE[1] res_pjsip: Call started',
            '[2026-11-18 10:00:01] ERROR[1] auth: Authorization: Digest username="100", secret=SuperSecret123',
            '[2026-11-18 10:00:02] WARNING[1] chan: password=MyPass99 failed',
            '[2026-11-18 10:00:03] NOTICE[1] http: Header Bearer jwt_secret_token_999 md5_cred=deadbeef auth_pass=s3cr3t client_secret=topsecret',
        ]) . "\n");

        try {
            $res = AsteriskLogService::readTail('messages', 50, 'ERROR', 'auth', $tmpDir);
            $this->assertTrue($res['ok']);
            $this->assertCount(1, $res['lines']);
            $this->assertStringNotContainsString('SuperSecret123', $res['lines'][0]);
            $this->assertStringContainsString('Authorization: Digest ***', $res['lines'][0]);

            // Searching for masked secrets must not match (maskSecrets runs before search filter).
            $oracle = AsteriskLogService::readTail('messages', 50, '', 'MyPass99', $tmpDir);
            $this->assertTrue($oracle['ok']);
            $this->assertCount(0, $oracle['lines']);

            $res2 = AsteriskLogService::readTail('messages', 50, '', '', $tmpDir);
            $this->assertCount(4, $res2['lines']);
            $this->assertStringNotContainsString('MyPass99', $res2['lines'][2]);
            $this->assertStringNotContainsString('jwt_secret_token_999', $res2['lines'][3]);
            $this->assertStringNotContainsString('deadbeef', $res2['lines'][3]);
            $this->assertStringNotContainsString('s3cr3t', $res2['lines'][3]);
            $this->assertStringNotContainsString('topsecret', $res2['lines'][3]);
            $this->assertStringContainsString('Bearer ***', $res2['lines'][3]);
            $this->assertStringContainsString('md5_cred=***', $res2['lines'][3]);
            $this->assertStringContainsString('auth_pass=***', $res2['lines'][3]);
            $this->assertStringContainsString('client_secret=***', $res2['lines'][3]);

            $bad = AsteriskLogService::readTail('../etc/passwd', 50, '', '', $tmpDir);
            $this->assertFalse($bad['ok']);
        } finally {
            @unlink($logFile);
            @rmdir($tmpDir);
        }
    }

    public function testCallModifierSaveAndDialplanGeneration(): void
    {
        $_SESSION['csrf_token'] = 'test_csrf_cm';

        $saveLang = CallModifierService::saveModifier([
            'csrf_token' => 'test_csrf_cm',
            'modifier_id' => 0,
            'title' => 'Unit English Mod',
            'mod_type' => 'language',
            'mod_value' => 'en',
            'dest_type' => 'hangup',
            'dest_id' => '',
            'is_active' => 1,
        ]);
        $this->assertTrue($saveLang['success']);

        $savePrefix = CallModifierService::saveModifier([
            'csrf_token' => 'test_csrf_cm',
            'modifier_id' => 0,
            'title' => 'Unit VIP Prefix',
            'mod_type' => 'cid_prefix',
            'mod_value' => '[VIP] ',
            'dest_type' => 'hangup',
            'dest_id' => '',
            'is_active' => 1,
        ]);
        $this->assertTrue($savePrefix['success']);

        $db = getDB();
        $langId = (int) $db->query("SELECT id FROM pbx_call_modifiers WHERE title = 'Unit English Mod' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $prefixId = (int) $db->query("SELECT id FROM pbx_call_modifiers WHERE title = 'Unit VIP Prefix' ORDER BY id DESC LIMIT 1")->fetchColumn();

        try {
            // All affected dialplan domains must be marked dirty on save.
            $pendingStmt = $db->prepare("SELECT domain FROM sys_pending_sync WHERE entity_type = 'call_modifier' AND entity_id = ?");
            $pendingStmt->execute([(string) $langId]);
            $domains = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach (['inbound_dialplan', 'ivrs', 'time_conditions', 'ring_groups', 'general_dialplan', 'internal_numbers', 'widgets', 'ai_apps', 'ai_agents'] as $d) {
                $this->assertContains($d, $domains, "domain {$d} must be marked dirty");
            }

            // Invalid cid_prefix stripping to empty under dialplanText() must be rejected.
            $badPrefix = CallModifierService::saveModifier([
                'csrf_token' => 'test_csrf_cm',
                'modifier_id' => 0,
                'title' => 'Bad Prefix',
                'mod_type' => 'cid_prefix',
                'mod_value' => '***',
                'dest_type' => 'hangup',
                'dest_id' => '',
                'is_active' => 1,
            ]);
            $this->assertFalse($badPrefix['success']);

            // Toggle status via PBXHelper::toggleStatus('pbx_call_modifiers', ...) must succeed.
            $tog = PBXHelper::toggleStatus('pbx_call_modifiers', $prefixId, 'test_csrf_cm');
            $this->assertTrue($tog['success']);
            $togBack = PBXHelper::toggleStatus('pbx_call_modifiers', $prefixId, 'test_csrf_cm');
            $this->assertTrue($togBack['success']);

            // Multi-hop cycle (langId -> prefixId -> langId) must be rejected.
            $chain = CallModifierService::saveModifier([
                'csrf_token' => 'test_csrf_cm',
                'modifier_id' => $prefixId,
                'title' => 'Unit VIP Prefix',
                'mod_type' => 'cid_prefix',
                'mod_value' => 'VIP',
                'dest_type' => 'call_modifier',
                'dest_id' => (string) $langId,
                'is_active' => 1,
            ]);
            $this->assertTrue($chain['success']);
            $cycle = CallModifierService::saveModifier([
                'csrf_token' => 'test_csrf_cm',
                'modifier_id' => $langId,
                'title' => 'Unit English Mod',
                'mod_type' => 'language',
                'mod_value' => 'en',
                'dest_type' => 'call_modifier',
                'dest_id' => (string) $prefixId,
                'is_active' => 1,
            ]);
            $this->assertFalse($cycle['success'], 'multi-hop cycle must be rejected');

            // Reset prefixId destination before delete so usageCount() allows cleanup.
            CallModifierService::saveModifier([
                'csrf_token' => 'test_csrf_cm',
                'modifier_id' => $prefixId,
                'title' => 'Unit VIP Prefix',
                'mod_type' => 'cid_prefix',
                'mod_value' => 'VIP',
                'dest_type' => 'hangup',
                'dest_id' => '',
                'is_active' => 1,
            ]);

            $langDialplan = buildDestinationLines('call_modifier', (string) $langId);
            $this->assertStringContainsString('Set(CHANNEL(language)=en)', $langDialplan);
            $this->assertStringContainsString('Hangup()', $langDialplan);

            $prefixDialplan = buildDestinationLines('call_modifier', (string) $prefixId);
            $this->assertStringContainsString('Set(CALLERID(name)=VIP ${CALLERID(name)})', $prefixDialplan);
            $this->assertStringContainsString('Hangup()', $prefixDialplan);

            // A modifier that leads to itself ends instead of looping, and a deleted one hangs up.
            $db->prepare("UPDATE pbx_call_modifiers SET dest_type = 'call_modifier', dest_id = ? WHERE id = ?")->execute([(string) $langId, $langId]);
            $loop = buildDestinationLines('call_modifier', (string) $langId);
            $this->assertLessThanOrEqual(6, substr_count($loop, 'CHANNEL(language)'));
            $this->assertStringContainsString('Hangup()', buildDestinationLines('call_modifier', '999999'));
            $this->assertSame('call_modifier', sanitizeDestType('call_modifier'), 'saving keeps the type');
        } finally {
            CallModifierService::deleteModifier($langId, 'test_csrf_cm');
            CallModifierService::deleteModifier($prefixId, 'test_csrf_cm');
            $db->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'call_modifier' AND entity_id IN ('" . $langId . "', '" . $prefixId . "')");
        }
    }
}
