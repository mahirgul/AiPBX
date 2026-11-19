<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once __DIR__ . '/../../src/services/ai/AssistantChatService.php';
require_once __DIR__ . '/../../src/repositories/RoleRepository.php';

final class AssistantChatTest extends TestCase
{
    private static bool $schemaReady = false;
    private ?string $prevRolesSetting = null;

    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();

        if (!self::$schemaReady) {
            $db->exec("CREATE TABLE IF NOT EXISTS assistant_chats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(200) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_updated (user_id, updated_at),
                INDEX idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->exec("CREATE TABLE IF NOT EXISTS assistant_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chat_id INT NOT NULL,
                role VARCHAR(20) NOT NULL,
                text TEXT NOT NULL,
                kind VARCHAR(30) NOT NULL DEFAULT 'text',
                plan_id INT NULL,
                payload_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_chat_id (chat_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::$schemaReady = true;
        }

        $db->exec('DELETE FROM assistant_messages');
        $db->exec('DELETE FROM assistant_chats');
        $db->exec('DELETE FROM pbx_ai_changes');
        $db->exec('DELETE FROM sys_users WHERE username IN ("asst_admin", "asst_101", "asst_102", "asst_noext", "ext101", "ext102", "ext203")');

        $st = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        $st->execute(['assistant.roles']);
        $val = $st->fetchColumn();
        $this->prevRolesSetting = $val === false ? null : (string) $val;
    }

    protected function tearDown(): void
    {
        $db = getDB();
        if ($this->prevRolesSetting === null) {
            $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute(['assistant.roles']);
        } else {
            $db->prepare('REPLACE INTO sys_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['assistant.roles', $this->prevRolesSetting]);
        }
    }

    private function createUser(string $username, string $role, string $ext = ''): array
    {
        $db = getDB();
        if (!$db->query("SELECT COUNT(*) FROM sys_roles WHERE role_key = " . $db->quote($role))->fetchColumn()) {
            $db->prepare("INSERT IGNORE INTO sys_roles (role_key, role_name, description, is_system) VALUES (?, ?, '', 1)")
                ->execute([$role, ucfirst($role)]);
        }
        $st = $db->prepare(
            'INSERT INTO sys_users (username, password_hash, full_name, email, role, extension,
                                    sip_password, sip_auth_digest, extension_type, is_active, voicemail_enabled,
                                    call_forward_number, created_at)
             VALUES (?, "", ?, "", ?, ?, "", 1, "sip", 1, 0, ?, NOW())'
        );
        $st->execute([$username, $username, $role, $ext, $ext === '101' ? '999' : '']);
        return [
            'id' => (int) $db->lastInsertId(),
            'role' => $role,
            'extension' => $ext,
        ];
    }

    private function setRolesConfig(array $map): void
    {
        $db = getDB();
        $db->prepare('REPLACE INTO sys_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['assistant.roles', json_encode($map)]);
    }

    private function sampleContext(): array
    {
        return AssistantPlanner::context(
            [
                ['extension' => '101', 'name' => 'Alice'],
                ['extension' => '102', 'name' => 'Bob'],
                ['extension' => '203', 'name' => 'Reception'],
            ],
            [
                ['id' => 1, 'title' => 'Sales', 'members' => ['101', '102']],
            ],
            [
                ['id' => 1, 'title' => 'Support', 'members' => ['101']],
            ]
        );
    }

    public function testClassifierPipelineRulesEchoNavModelAndUnknown(): void
    {
        $admin = $this->createUser('asst_admin', 'admin', '101');
        $ctx = $this->sampleContext();

        // 1. Rules -> plan
        $r1 = AssistantChatService::ask('forward 101 to 203', 0, $admin, null, $ctx);
        $this->assertSame('plan', $r1['message']['kind']);
        $this->assertSame('extension.forward', $r1['message']['payload']['plan']['action']);
        $chatId = $r1['chat_id'];

        // 2. Echo -> info
        $r2 = AssistantChatService::ask('bugün kaç çağrı var', $chatId, $admin, static fn() => null, $ctx);
        $this->assertSame('info', $r2['message']['kind']);
        $this->assertSame('calls.today', $r2['message']['payload']['query']);

        // 3. Menu navigation -> nav
        $r3 = AssistantChatService::ask('kuyruk ayarları nerede', $chatId, $admin, static fn() => null, $ctx);
        $this->assertSame('nav', $r3['message']['kind']);
        $this->assertNotEmpty($r3['message']['payload']['links']);
        $this->assertSame('/queues', $r3['message']['payload']['links'][0]['href']);

        // 4. Unknown -> unknown (no model reply)
        $r4 = AssistantChatService::ask('hava durumu nasıl', $chatId, $admin, static fn() => null, $ctx);
        $this->assertSame('unknown', $r4['message']['kind']);
        $this->assertNotEmpty($r4['message']['payload']['askable']);

        // 5. Model fallback receives up to 6 prior messages
        $capturedPrompt = '';
        $r5 = AssistantChatService::ask(
            'could you put 101 into quiet mode for a bit',
            $chatId,
            $admin,
            static function (string $prompt) use (&$capturedPrompt): string {
                $capturedPrompt = $prompt;
                return json_encode(['action' => 'extension.dnd', 'arguments' => ['extension' => '101', 'enabled' => true]]);
            },
            $ctx
        );
        $this->assertSame('plan', $r5['message']['kind']);
        $this->assertSame('extension.dnd', $r5['message']['payload']['plan']['action']);
        $this->assertStringContainsString('Recent conversation', $capturedPrompt);
        $this->assertStringContainsString('hava durumu', $capturedPrompt);
    }

    public function testRoleAskWithoutChangeCannotProposeChanges(): void
    {
        $this->setRolesConfig([
            'operator' => ['ask' => true, 'change' => false],
        ]);
        $op = $this->createUser('asst_101', 'operator', '101');
        $ctx = $this->sampleContext();

        // Can ask read-only Echo question
        $info = AssistantChatService::ask('101 şu an nereye yönlendirilmiş?', 0, $op, static fn() => null, $ctx);
        $this->assertSame('info', $info['message']['kind']);
        $this->assertSame('extension.forwarding', $info['message']['payload']['query']);
        $this->assertCount(1, $info['message']['payload']['rows']);
        $this->assertSame('999', $info['message']['payload']['rows'][0]['call_forward_number']);

        // Cannot propose a change
        $denied = AssistantChatService::ask('forward 101 to 203', $info['chat_id'], $op, null, $ctx);
        $this->assertSame('error', $denied['message']['kind']);
    }

    public function testNonAdminOwnExtensionScopeForChangesAndEcho(): void
    {
        $this->setRolesConfig([
            'user' => ['ask' => true, 'change' => true],
        ]);
        $u101 = $this->createUser('asst_101', 'user', '101');
        $u102 = $this->createUser('asst_102', 'user', '102');
        $uNoExt = $this->createUser('asst_noext', 'user', '');
        $ctx = $this->sampleContext();

        // Own extension change allowed
        $ok = AssistantChatService::ask('forward 101 to 203', 0, $u101, null, $ctx);
        $this->assertSame('plan', $ok['message']['kind']);

        // Other extension change refused
        $other = AssistantChatService::ask('forward 102 to 203', 0, $u101, null, $ctx);
        $this->assertSame('error', $other['message']['kind']);

        // Ring group change refused for non-admin
        $rg = AssistantChatService::ask('set ring group Sales timeout to 20', 0, $u101, null, $ctx);
        $this->assertSame('error', $rg['message']['kind']);

        // User without extension refused for change
        $noExt = AssistantChatService::ask('forward 101 to 203', 0, $uNoExt, null, $ctx);
        $this->assertSame('error', $noExt['message']['kind']);

        // Echo forwarding query scoped: u102 cannot read u101's forwarding
        $echoOther = EchoReporter::ask('101 şu an nereye yönlendirilmiş?', ['is_admin' => false, 'extension' => '102']);
        $this->assertSame('extension.forwarding', $echoOther['query']);
        $this->assertSame([], $echoOther['rows']);

        // Echo signaling query blocked for non-admin
        $echoSig = EchoReporter::ask('signaling errors', ['is_admin' => false, 'extension' => '101']);
        $this->assertSame([], $echoSig['rows']);
    }

    public function testChatHistoryDeleteAndPruneOldChats(): void
    {
        $u1 = $this->createUser('asst_101', 'admin', '101');
        $u2 = $this->createUser('asst_102', 'admin', '102');

        $oldChatId = AssistantChatService::createChat($u1['id'], 'Old chat');
        $newChatId = AssistantChatService::createChat($u1['id'], 'New chat');

        getDB()->prepare('UPDATE assistant_chats SET updated_at = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE id = ?')
            ->execute([$oldChatId]);

        // Ownership: u2 cannot delete u1's chat
        $this->assertFalse(AssistantChatService::deleteChat($newChatId, $u2['id']));
        $this->assertNull(AssistantChatService::getChat($newChatId, $u2['id']));

        // Prune older than 10 days deletes oldChatId only
        $pruned = AssistantChatService::pruneOldChats(10);
        $this->assertSame(1, $pruned);
        $this->assertNull(AssistantChatService::getChat($oldChatId, $u1['id']));
        $this->assertNotNull(AssistantChatService::getChat($newChatId, $u1['id']));

        // Owner can delete
        $this->assertTrue(AssistantChatService::deleteChat($newChatId, $u1['id']));
        $this->assertNull(AssistantChatService::getChat($newChatId, $u1['id']));
    }

    public function testApplyAndRollbackRestoresExtension(): void
    {
        $this->createUser('asst_101', 'admin', '101');
        $plan = AssistantPlanner::build('extension.forward', ['extension' => '101', 'target' => '203', 'enabled' => true], $this->sampleContext(), 'confirmed');
        $id = AssistantPlanService::apply($plan, 'forward 101 to 203');

        $fwd = getDB()->query("SELECT call_forward_number FROM sys_users WHERE extension = '101'")->fetchColumn();
        $this->assertSame('203', $fwd);

        AssistantPlanService::rollback($id);
        $restored = getDB()->query("SELECT call_forward_number FROM sys_users WHERE extension = '101'")->fetchColumn();
        $this->assertSame('999', $restored);
    }

    public function testRoleRepositoryAssistantNotAdminOnly(): void
    {
        $mods = RoleRepository::modulesDefinition();
        $this->assertArrayHasKey('assistant', $mods);
        $this->assertArrayNotHasKey('admin_only', $mods['assistant']);
    }

    public function testDisabledHistoryNeverWritesMessagesAndClearsOldHistory(): void
    {
        $oldDays = getSystemSetting('assistant.history_days', '10');
        try {
            setSystemSetting('assistant.history_days', '10');
            $user = $this->createUser('asst_admin', 'admin', '101');
            $chat = AssistantChatService::createChat($user['id'], 'Existing');
            AssistantChatService::addMessage($chat, 'user', 'Saved before disabling');
            setSystemSetting('assistant.history_days', '0');
            AssistantChatService::pruneOldChats();
            $r = AssistantChatService::ask('forward 101 to 203', 0, $user, null, $this->sampleContext());
            $this->assertSame('plan', $r['message']['kind']);
            $this->assertSame(0, $r['chat_id']);
            $this->assertSame(0, (int) getDB()->query('SELECT COUNT(*) FROM assistant_messages')->fetchColumn());
            $this->assertSame(0, (int) getDB()->query('SELECT COUNT(*) FROM assistant_chats')->fetchColumn());
            $this->assertSame([], AssistantChatService::listChats($user['id']));
        } finally {
            setSystemSetting('assistant.history_days', $oldDays);
        }
    }

    public function testPruningExpiresMessagesInActiveChats(): void
    {
        $u = $this->createUser('asst_admin', 'admin', '101');
        $chat = AssistantChatService::createChat($u['id'], 'Active chat');
        $old = AssistantChatService::addMessage($chat, 'user', 'Old message');
        AssistantChatService::addMessage($chat, 'assistant', 'Recent reply');
        getDB()->prepare('UPDATE assistant_messages SET created_at = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE id = ?')->execute([$old]);
        AssistantChatService::pruneOldChats(10);
        $this->assertCount(1, AssistantChatService::getChat($chat, $u['id'])['messages']);
    }

}
