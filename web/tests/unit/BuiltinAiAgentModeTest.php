<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/BuiltinAiAgentService.php';

final class BuiltinAiAgentModeTest extends TestCase
{
    public function testAnAgentIsReadyOnlyWhenItsModeHasWhatItNeeds(): void
    {
        $this->assertFalse(BuiltinAiAgentService::ready(['conversation' => true]));
        $this->assertFalse(BuiltinAiAgentService::ready(['conversation' => true, 'mode' => 'realtime']));
        $this->assertTrue(BuiltinAiAgentService::ready(['conversation' => true, 'mode' => 'realtime', 'realtime_cloud' => ['provider' => 'openai']]));
        // Cascade needs its three parts; a Realtime account does not make it ready and there is no fallback between modes.
        $this->assertFalse(BuiltinAiAgentService::ready(['conversation' => true, 'mode' => 'cascade', 'realtime_cloud' => ['provider' => 'openai']]));
        $this->assertTrue(BuiltinAiAgentService::ready(['conversation' => true, 'mode' => 'cascade', 'tts_model' => 'ema-lightning',
            'stt_model' => 'cloud:groq', 'llm_cloud' => ['provider' => 'groq']]));
    }

    public function testATransferWhoseTargetWasDeletedIsLeftOutAlone(): void
    {
        $config = [
            'transfers' => [['id' => 'gone', 'type' => 'queue', 'target' => 'no_such_queue_' . bin2hex(random_bytes(3)), 'usage' => 'Orders']],
            'http_tools' => 'status | Order status | https://example.com/api?o={order}',
            'greetings' => ['Hello', 'Welcome'], 'waiting_phrases' => ['One moment'],
        ];
        $this->assertSame(['gone'], BuiltinAiAgentService::brokenTransfers($config));
        $tools = BuiltinAiAgentService::toolsFromConfig($config);
        $this->assertSame([], $tools['intents'], 'the broken transfer is left out');
        $this->assertSame('status', $tools['tools'][0]['name'], 'the other tools still work');
        $this->assertSame('Hello', $tools['greeting']);
    }

    public function testInstructionsMayBeLong(): void
    {
        $this->assertSame(60000, BuiltinAiAgentService::MAX_INSTRUCTIONS);
    }

    public function testASavedAgentTransfersThroughTheDialplanAndProtectsItsTarget(): void
    {
        require_once __DIR__ . '/../../src/sync/SyncAiApps.php';
        $extensions = array_keys(BuiltinAiAgentService::targets()['extension'] ?? []);
        if ($extensions === []) {
            $this->markTestSkipped('no extension in the test database');
        }
        $ext = (string) $extensions[0];
        // what validate() stores (the models are not needed for the tools: the test database has no AI accounts)
        $parsed = BuiltinAiAgentService::parseInstructions("Ask for a person: {transfer, operator, {$ext}}.");
        $this->assertSame([['id' => 'operator', 'type' => 'extension', 'target' => $ext, 'usage' => 'Ask for a person: operator.']],
            $parsed['transfers']);
        // a Realtime agent with an OpenAI account is ready, so its dialplan has the transfer routes
        SecretBox::useKey(str_repeat('k', 32));
        getDB()->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = 'aicloud'");
        PluginManager::resetCache();
        CloudAiService::save('openai', ['api_key' => 'sk-mode-test-123']);
        $v = ['agent_config' => ['mode' => 'realtime', 'conversation' => true, 'realtime_account' => 'cloud:openai', 'transfers' => $parsed['transfers']]];
        $db = getDB();
        $before = \PBX\Destinations\DestinationRegistry::usageCount('extension', $ext);
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO pbx_ai_apps (title, app_type, model_id, text_template, dest_type, dest_id, is_active, config)
                          VALUES ('Mode test', 'builtin_agent', 'x', 'Hi', 'hangup', '', 1, ?)")->execute([json_encode($v['agent_config'])]);
            $id = (int) $db->lastInsertId();
            $this->assertSame($before + 1, \PBX\Destinations\DestinationRegistry::usageCount('extension', $ext), 'deleting the target is refused');
            $app = AiAppService::get($id);
            $app['agent_tools'] = BuiltinAiAgentService::toolsFromConfig(AiAppService::config($app));
            $conf = buildAiAppsDialplan([$app], str_repeat('ab', 32));
            $this->assertStringContainsString('request Ask for a person operator.', $conf, 'the transfer becomes a dialplan route');
            $this->assertStringContainsString("DIALPLAN_EXISTS(from-internal-pbx-ortak,{$ext},1)", $conf);
            $this->assertStringContainsString('&lang=${URIENCODE(${CHANNEL(language)})}', $conf, 'the call language goes to the AI service');
        } finally {
            $db->rollBack();
            $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.openai.%'");
            SecretBox::useKey(null);
        }
    }
}
