<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';

require_once __DIR__ . '/../../src/services/ai/AssistantPlanner.php';
require_once __DIR__ . '/../../src/services/ai/AssistantPlanService.php';
require_once __DIR__ . '/../../src/services/ai/EchoReporter.php';

/**
 * AI → Assistant: a plan is only as good as what applying it does.
 *
 * These tests run against the isolated asterisk_test database (see
 * tests/bootstrap.php: nothing here can reach a real PBX or a model).
 * AssistantPlanService applies through real SQL — the sys_users update and
 * the audit row are the contract; the pending-sync marks are also written
 * (the sync itself reads them elsewhere).
 */
final class AssistantPlanServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        getDB()->exec('DELETE FROM sys_users WHERE username LIKE "ext1__" OR username LIKE "ext2__" OR username LIKE "ext2___"');
        getDB()->exec("DELETE FROM pbx_ai_changes");
    }

    /** One SIP extension to point at (role must exist in sys_roles for the FK). */
    private function extension(string $ext = '101'): int
    {
        $db = getDB();
        if (!$db->query("SELECT COUNT(*) FROM sys_roles WHERE role_key = 'user'")->fetchColumn()) {
            $db->exec("INSERT IGNORE INTO sys_roles (role_key, role_name, description, is_system) VALUES ('user', 'User', 'Extensions', 1)");
        }
        $st = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension,
                                 sip_password, sip_auth_digest, extension_type, is_active, voicemail_enabled,
                                 created_at) VALUES (?, "", "Front desk", "", "user", ?, "", 1, "sip", 1, 0, NOW())');
        $st->execute(['ext' . $ext, $ext]);
        return (int) $db->lastInsertId();
    }

    private function planForward(bool $enabled = true, string $target = '203'): array
    {
        return [
            'ok' => true, 'action' => 'extension.forward', 'source' => 'rules',
            'arguments' => ['extension' => '101', 'target' => $target, 'enabled' => $enabled],
        ];
    }

    public function testForwardAppliesThroughSysUsersAndRecordsAudit(): void
    {
        $this->extension('101');
        $id = AssistantPlanService::apply($this->planForward(), 'Forward 101 to 203');

        $st = getDB()->prepare('SELECT call_forward_number FROM sys_users WHERE extension = "101"');
        $st->execute();
        $this->assertSame('203', $st->fetchColumn());

        $row = AssistantPlanService::get($id);
        $this->assertSame('extension.forward', $row['action']);
        $this->assertSame('applied', $row['status']);
        $this->assertSame('rules', $row['source']);
        $this->assertStringContainsString('101', $row['summary']);
        $this->assertNotSame(null, $row['before_json']);
    }

    public function testForwardOffClearsAndRollbackRestores(): void
    {
        $this->extension('101');
        // First turn it on (the "before" of the next one is on→203).
        AssistantPlanService::apply($this->planForward(true, '203'), 'Forward 101 to 203');

        // Now turn it off: before_json must carry the ON state for undo.
        $id = AssistantPlanService::apply($this->planForward(false), 'turn off forward for 101');
        $st = getDB()->prepare('SELECT call_forward_number FROM sys_users WHERE extension = "101"');
        $st->execute();
        $this->assertSame(null, $st->fetchColumn());

        $before = json_decode(AssistantPlanService::get($id)['before_json'], true);
        $this->assertTrue($before['arguments']['enabled']);
        $this->assertSame('203', $before['arguments']['target']);

        $summary = AssistantPlanService::rollback($id);
        $st = getDB()->prepare('SELECT call_forward_number FROM sys_users WHERE extension = "101"');
        $st->execute();
        $this->assertSame('203', $st->fetchColumn());
        $row = AssistantPlanService::get($id);
        $this->assertSame('rolled_back', $row['status']);
        $this->assertStringContainsString('Forward', $summary);
    }

    public function testVoicemailAppliesAndRollbacks(): void
    {
        $uid = $this->extension('101');
        $plan = ['ok' => true, 'action' => 'extension.voicemail', 'source' => 'rules',
                 'arguments' => ['extension' => '101', 'enabled' => true]];
        $id = AssistantPlanService::apply($plan, 'voicemail on for 101');
        $st = getDB()->prepare('SELECT voicemail_enabled FROM sys_users WHERE extension = "101"');
        $st->execute();
        $this->assertSame(1, (int) $st->fetchColumn());
        AssistantPlanService::rollback($id);
        $st = getDB()->prepare('SELECT voicemail_enabled FROM sys_users WHERE extension = "101"');
        $st->execute();
        $this->assertSame(0, (int) $st->fetchColumn());
    }

    /**
     * Rename has no prior state to restore: the apply row must carry no
     * before_json (the UI's undoable flag), so no Undo is ever offered —
     * and rollback refuses it instead of failing on a click.
     */
    public function testRenameIsRecordedButNotUndoable(): void
    {
        $this->extension('101');
        $plan = ['ok' => true, 'action' => 'extension.rename', 'source' => 'rules',
                 'arguments' => ['extension' => '101', 'name' => 'Front desk 2']];
        $id = AssistantPlanService::apply($plan, 'rename 101 to Front desk 2');
        $row = AssistantPlanService::get($id);
        $this->assertSame('extension.rename', $row['action']);
        $this->assertNull($row['before_json']);

        $this->expectException(RuntimeException::class);
        AssistantPlanService::rollback($id);
    }

    /**
     * Voicemail's Undo restores voicemail_enabled — not call forwarding.
     * The review found captureBefore() recorded forwarding for every
     * extension.* action, so undoing voicemail overwrote the forward state.
     */
    public function testVoicemailUndoRestoresVoicemailWithoutTouchingForwarding(): void
    {
        $this->extension('101');
        AssistantPlanService::apply($this->planForward(true, '203'), 'Forward 101 to 203');

        $plan = ['ok' => true, 'action' => 'extension.voicemail', 'source' => 'rules',
                 'arguments' => ['extension' => '101', 'enabled' => true]];
        $id = AssistantPlanService::apply($plan, 'voicemail on for 101');

        $before = json_decode(AssistantPlanService::get($id)['before_json'], true);
        $this->assertSame('extension.voicemail', $before['action']);
        $this->assertFalse($before['arguments']['enabled']);

        AssistantPlanService::rollback($id);
        $st = getDB()->prepare('SELECT voicemail_enabled, call_forward_number FROM sys_users WHERE extension = "101"');
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['voicemail_enabled'], 'undo must restore voicemail_enabled');
        $this->assertSame('203', $row['call_forward_number'], 'undo must not clobber forwarding');
    }

    /**
     * Per-extension voicemail boxes and their dialplan routing come from the
     * 'extensions' and 'general_dialplan' sync domains; 'voicemail' only
     * regenerates general voicemail.conf.
     */
    public function testVoicemailQueuesExtensionAndDialplanSyncDomains(): void
    {
        $this->extension('101');
        getDB()->exec('DELETE FROM sys_pending_sync');
        $plan = ['ok' => true, 'action' => 'extension.voicemail', 'source' => 'rules',
                 'arguments' => ['extension' => '101', 'enabled' => true]];
        AssistantPlanService::apply($plan, 'voicemail on for 101');

        $domains = getDB()->query('SELECT DISTINCT domain FROM sys_pending_sync')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('extensions', $domains);
        $this->assertContains('general_dialplan', $domains);
        $this->assertNotContains('voicemail', $domains);
    }

    public function testRefusesAPlanThatIsNotOk(): void
    {
        $this->expectException(RuntimeException::class);
        AssistantPlanService::apply(['ok' => false, 'message_key' => 'ai_assistant.err_understood'], 'gibberish');
    }

    public function testEchoReturnsTheAskableListOnUnknownQuestion(): void
    {
        $answer = EchoReporter::ask('what is the meaning of life');
        $this->assertSame('', $answer['query']);
        $this->assertStringContainsString('Calls today', $answer['askable']);
    }

    public function testEchoAnswersCallsTodayFromCdrs(): void
    {
        $db = getDB();
        // cdrs is a VIEW over asteriskcdr (the raw Asterisk CDR table); the
        // test inserts the raw rows the same way a real call would, from a
        // clean table (Fixtures does not clear asteriskcdr).
        try {
            $db->exec('DELETE FROM asteriskcdr');
            $st = $db->prepare("INSERT INTO asteriskcdr (calldate, clid, src, dst, did, lastapp, duration, billsec, disposition)
                                VALUES (NOW(), '5551112222 <5551112222>', '5551112222', '101', '', 'Dial', 70, 60, 'ANSWERED'),
                                       (NOW(), '5551113333 <5551113333>', '5551113333', '102', '', 'Dial', 5, 0, 'NO ANSWER')");
            $st->execute();
        } catch (Throwable $e) {
            $this->markTestSkipped('asteriskcdr insert failed: ' . $e->getMessage());
        }
        $answer = EchoReporter::ask('how many calls today?');
        $this->assertSame('calls.today', $answer['query']);
        $this->assertSame(2, (int) ($answer['rows'][0]['calls'] ?? 0));

        $noAns = EchoReporter::ask('no answer calls');
        $this->assertSame('noanswer.hour', $noAns['query']);

        $ansRate = EchoReporter::ask('answered rate');
        $this->assertSame('answered.rate', $ansRate['query']);
    }
}
