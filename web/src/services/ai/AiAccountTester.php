<?php
/**
 * The connection test of an account (the Test button). It calls the AI gateway, which in turn asks CloudAiService
 * for the account's connection, so it sits above both and is not part of the CloudAiService facade.
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';
require_once dirname(__DIR__) . '/AiTtsService.php';
require_once __DIR__ . '/AiAccountStore.php';
require_once __DIR__ . '/AiProviderClient.php';

final class AiAccountTester
{
    /**
     * A cheap authenticated call; throws with the provider's own message.
     * $post is the provider form as typed: fields not yet saved are what gets
     * tested. The old behaviour read only stored settings, so hitting Test
     * with a freshly pasted key sent no key at all and the provider's own
     * "no credentials" error read like a bad key.
     */
    public static function test(string $id, array $post = [], bool $isNew = false): string
    {
        $cfg = $isNew ? [] : AiAccountStore::config($id);
        $k = $cfg['api_key'] ?? '';
        $typedKey = trim((string) ($post['api_key'] ?? ''));
        if ($typedKey !== '') {
            $k = $typedKey;
        }
        switch (AiAccountStore::typeOf($id)) {
            case 'google_ai':
                $d = AiProviderClient::json(TtsHttp::request('GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=50', ['x-goog-api-key' => $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_models'), count($d['models'] ?? []));
            case 'openai':
                $d = AiProviderClient::json(TtsHttp::request('GET', 'https://api.openai.com/v1/models', ['Authorization' => 'Bearer ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_models'), count($d['data'] ?? []));
            case 'groq':
                $d = AiProviderClient::json(TtsHttp::request('GET', 'https://api.groq.com/openai/v1/models', ['Authorization' => 'Bearer ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_models'), count($d['data'] ?? []));
            case 'openrouter':
                $d = AiProviderClient::json(TtsHttp::request('GET', 'https://openrouter.ai/api/v1/key', ['Authorization' => 'Bearer ' . $k], null, 20), $id);
                return t('ai_cloud.test_ok') . (isset($d['data']['limit_remaining']) ? ' · ' . $d['data']['limit_remaining'] : '');
            case 'nvidia':
            case 'nvidia_local':
            case 'qwen':
            case 'ollama':
            case 'claude':
            case 'mistral':
            case 'cohere':
            case 'openai_compat':
                return self::testChat($id, $post, $isNew);
            case 'deepgram':
                $d = AiProviderClient::json(TtsHttp::request('GET', 'https://api.deepgram.com/v1/projects', ['Authorization' => 'Token ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_projects'), count($d['projects'] ?? []));
            default:
                // Speech providers: listing the voices proves the key.
                $count = AiTtsService::testConfig($id, $post);
                return sprintf(t('ai_tts.test_ok'), $count);
        }
    }

    /**
     * One tiny chat request: proves address, key and model together
     * (listing models would not show a wrong model name).
     */
    private static function testChat(string $id, array $post = [], bool $isNew = false): string
    {
        require_once __DIR__ . '/AiGateway.php';
        $started = microtime(true);
        $draft = $post + ($isNew ? ['_is_new' => true] : []);
        AiGateway::chat('cloud:' . $id, 'Reply with OK.', 'panel', 300, $draft);
        $cfg = AiProviderClient::gatewayConfig($id, $draft);
        return sprintf(t('ai_cloud.test_chat'), $cfg['model'], (int) round((microtime(true) - $started) * 1000));
    }
}
