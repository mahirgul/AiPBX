<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ai/AssistantChatService.php';
require_once dirname(__DIR__, 2) . '/src/services/ai/AiAppService.php';

/** The knowledge base where it is used: the Assistant and voice application conversations. */
final class AiKnowledgeUseTest extends TestCase
{
    private ?int $wasEnabled = null;
    private string $builtIn = '';
    private string $builtInSha = '';

    protected function setUp(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM ai_knowledge_chunks');
        $db->exec('DELETE FROM ai_knowledge_docs');
        $db->exec('DELETE FROM ai_knowledge_collections');
        $v = $db->query("SELECT enabled FROM sys_plugins WHERE slug = 'ai-knowledge'")->fetchColumn();
        $this->wasEnabled = $v === false ? null : (int) $v;
        $this->builtIn = (string) getSystemSetting('ai_knowledge_builtin', '');
        $this->builtInSha = (string) getSystemSetting('ai_knowledge_builtin_sha', '');
        setSystemSetting('ai_knowledge_builtin', (string) json_encode(array_keys(AiKnowledgeService::BUILT_IN)));
        AiKnowledgeService::$embedder = static fn(string $b, string $t): array => ['embedding' => [], 'dimensions' => 0, 'resolved_model_ref' => $b];
        AiKnowledgeService::$chatter = static fn(string $b, string $p): string => 'Mesai 09:00-18:00 arasidir [1].';
    }

    protected function tearDown(): void
    {
        AiKnowledgeService::$embedder = null;
        AiKnowledgeService::$chatter = null;
        $db = getDB();
        $db->exec('DELETE FROM ai_knowledge_chunks');
        $db->exec('DELETE FROM ai_knowledge_docs');
        $db->exec('DELETE FROM ai_knowledge_collections');
        $this->setPlugin($this->wasEnabled);
        setSystemSetting('ai_knowledge_builtin', $this->builtIn);
        setSystemSetting('ai_knowledge_builtin_sha', $this->builtInSha);
    }

    private function setPlugin(?int $enabled): void
    {
        $db = getDB();
        if ($enabled === null) {
            $db->exec("DELETE FROM sys_plugins WHERE slug = 'ai-knowledge'");
        } elseif ($db->query("SELECT COUNT(*) FROM sys_plugins WHERE slug = 'ai-knowledge'")->fetchColumn()) {
            $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = 'ai-knowledge'")->execute([$enabled]);
        } else {
            $db->prepare("INSERT INTO sys_plugins (slug, name, version, enabled) VALUES ('ai-knowledge', 'Knowledge', '1.0.0', ?)")->execute([$enabled]);
        }
        PluginManager::resetCache();
    }

    public function testAssistantAnswersFromPermittedDocumentsWithSources(): void
    {
        $col = AiKnowledgeService::saveCollection(0, 'SSS', '', ['user'], '', (string) getUserLanguage());
        AiKnowledgeService::saveCollection(0, 'Not for the Assistant', '', ['user'], '');
        AiKnowledgeService::addDocument($col, 'Mesai Saatleri', 'mesai.txt', 'Destek hattimiz mesai saatleri 09:00-18:00 arasidir.');

        $this->setPlugin(0);
        $this->assertNull(AssistantChatService::knowledgeAnswer('mesai saatleri nedir', 'user'));

        $this->setPlugin(1);
        $res = AssistantChatService::knowledgeAnswer('mesai saatleri nedir', 'user');
        $this->assertNotNull($res);
        $this->assertStringContainsString('[1]', $res['text']);
        $this->assertSame([['title' => 'Mesai Saatleri', 'collection' => 'SSS']], $res['payload']['sources']);

        // A role without access gets nothing, and an unknown topic is not answered.
        $this->assertNull(AssistantChatService::knowledgeAnswer('mesai saatleri nedir', 'cc_agent'));
        $this->assertNull(AssistantChatService::knowledgeAnswer('uzay gemisi motoru', 'user'));
    }

    public function testBuiltInGuidesAreSeededOnceAndStayDeleted(): void
    {
        setSystemSetting('ai_knowledge_builtin', '[]');
        setSystemSetting('ai_knowledge_builtin_sha', '{}');
        AiKnowledgeService::ensureBuiltIn();
        AiKnowledgeService::ensureBuiltIn();
        $rows = getDB()->query("SELECT c.slug, c.assistant_language, c.allowed_roles_json, COUNT(d.id) docs FROM ai_knowledge_collections c
                                LEFT JOIN ai_knowledge_docs d ON d.collection_id = c.id GROUP BY c.id ORDER BY c.slug")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['slug' => 'aipbx-help-en', 'assistant_language' => 'en', 'allowed_roles_json' => '["*"]', 'docs' => 1],
                           ['slug' => 'aipbx-help-tr', 'assistant_language' => 'tr', 'allowed_roles_json' => '["*"]', 'docs' => 1]],
            array_map(fn($r) => ['slug' => $r['slug'], 'assistant_language' => $r['assistant_language'], 'allowed_roles_json' => $r['allowed_roles_json'], 'docs' => (int) $r['docs']], $rows));
        $this->assertSame(AiKnowledgeService::assistantCollections('tr'), AiKnowledgeService::assistantCollections('tr'));
        $this->assertNotSame([], AiKnowledgeService::assistantCollections('de'), 'a language without a collection uses English');

        // Chosen on the Assistant settings page: a German collection, the English guide switched off.
        $de = AiKnowledgeService::saveCollection(0, 'Hilfe', '', ['*'], '');
        $en = AiKnowledgeService::assistantCollections('en')[0];
        AiKnowledgeService::setAssistantLanguages([$de => 'de', $en => '', 999999 => 'xx']);
        $this->assertSame([$de], AiKnowledgeService::assistantCollections('de'));
        $this->assertSame([], AiKnowledgeService::assistantCollections('en'));
        AiKnowledgeService::saveCollection($de, 'Hilfe 2', '', ['*'], '');
        $this->assertSame([$de], AiKnowledgeService::assistantCollections('de'), 'editing a collection keeps its Assistant language');
        AiKnowledgeService::setAssistantLanguages([$en => 'en']);
        AiKnowledgeService::deleteCollection($de);

        $tr = AiKnowledgeService::assistantCollections('tr')[0];
        $docId = (int) getDB()->query("SELECT id FROM ai_knowledge_docs WHERE collection_id = $tr")->fetchColumn();
        // An administrator's edit survives; an unedited guide takes a newly shipped version.
        AiKnowledgeService::addDocument($tr, 'Kendi rehberim', 'aipbx-help-tr.txt', "S: Test?\nC: Bizim cevabımız.", $docId);
        AiKnowledgeService::ensureBuiltIn();
        $this->assertSame("S: Test?\nC: Bizim cevabımız.", AiKnowledgeService::document($docId)['content_text']);
        $shas = json_decode((string) getSystemSetting('ai_knowledge_builtin_sha', '{}'), true);
        $shas['aipbx-help-tr'] = AiKnowledgeService::document($docId)['sha256'];   // as if an older version had been shipped
        setSystemSetting('ai_knowledge_builtin_sha', (string) json_encode($shas));
        AiKnowledgeService::ensureBuiltIn();
        $this->assertStringContainsString('Bekleyenler', AiKnowledgeService::document($docId)['content_text']);

        AiKnowledgeService::deleteCollection($tr);
        AiKnowledgeService::ensureBuiltIn();
        $this->assertSame(1, (int) getDB()->query('SELECT COUNT(*) FROM ai_knowledge_collections')->fetchColumn());
        $hit = AiKnowledgeService::search('How do I add an extension', 'user', AiKnowledgeService::assistantCollections('en'));
        $this->assertTrue($hit['grounded'], 'the English guide answers an English question for any role');
    }

    public function testVoiceAppConversationChoosesAnExistingCollection(): void
    {
        $col = AiKnowledgeService::saveCollection(0, 'SSS', '', [], '');
        $c = AiAppService::validateConversation(['conversation' => 1, 'knowledge_collection' => $col], 'cloud:openai');
        $this->assertSame($col, $c['knowledge_collection']);
        $this->assertSame(0, AiAppService::validateConversation(['conversation' => 1], 'cloud:openai')['knowledge_collection']);

        foreach ([['knowledge_collection' => $col + 999],
                  ['knowledge_collection' => $col, 'tools' => 'knowledge | docs | https://crm.example.com/k']] as $bad) {
            try {
                AiAppService::validateConversation(['conversation' => 1] + $bad, 'cloud:openai');
                $this->fail('expected a refusal');
            } catch (\Exception $e) {
                $this->assertSame(t('ai_apps.err_knowledge'), $e->getMessage());
            }
        }
    }

    public function testVoiceAppLookupRefusesBadRequests(): void
    {
        foreach ([[0, 'x'], [1, ''], [1, str_repeat('a', 301)]] as [$app, $q]) {
            try {
                AiGateway::knowledgeForApp($app, $q);
                $this->fail('expected a refusal');
            } catch (AiGatewayException $e) {
                $this->assertSame('invalid_request', $e->errorCode);
            }
        }
    }

    public function testModelTextReplyIsShownOnlyForTheReplyAction(): void
    {
        $this->assertSame('Merhaba!', AssistantPlanner::replyText('{"action": "reply", "text": " Merhaba! "}'));
        $this->assertNull(AssistantPlanner::replyText('{"action": "none"}'));
        $this->assertNull(AssistantPlanner::replyText('{"action": "reply", "text": ""}'));
        $this->assertNull(AssistantPlanner::replyText(null));
    }

    public function testVoiceKnowledgePrincipalMayOnlyEmbed(): void
    {
        try {
            AiGateway::execute('chat', 'local:x', 'hello', 'voice_knowledge');
            $this->fail('expected a refusal');
        } catch (AiGatewayException $e) {
            $this->assertSame('permission_denied', $e->errorCode);
        }
    }
}
