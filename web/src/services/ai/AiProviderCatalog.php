<?php
/**
 * The providers an account can be of: title, what each can do, the settings fields of its form, where to sign up.
 * What the AI service and the portal both need (addresses, default models) is read from AiProviderManifest.
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';
require_once dirname(__DIR__) . '/tts/TtsProviders.php';
require_once __DIR__ . '/AiProviderManifest.php';

final class AiProviderCatalog
{
    public const CAPS = ['tts', 'stt', 'llm', 'embed'];
    /** Accounts whose Model / address fields are for their language model (OpenAI's and ElevenLabs' Model is their speech model). */
    public const LLM_FIELDS_OWN = ['nvidia', 'qwen', 'openai_compat', 'ollama', 'nvidia_local', 'claude', 'mistral', 'cohere'];

    /**
     * Providers: title, capabilities, settings fields, sign-up page.
     * TTS-capable ones also have a class on the Cloud TTS page (same keys).
     */
    public static function providers(): array
    {
        $tts = fn(string $cls) => $cls::fields();
        $key = [['key' => 'api_key', 'label' => 'API key', 'secret' => true]];
        $model = fn(string $default) => ['key' => 'model', 'label' => 'Model', 'secret' => false, 'optional' => true, 'default' => $default];
        $providers = [
            'google_ai' => ['title' => 'Google AI Studio (Gemini, Gemma)', 'caps' => ['stt', 'llm', 'embed'], 'fields' => $key,
                            'url' => 'https://aistudio.google.com/apikey', 'free' => true],
            'openai' => ['title' => 'OpenAI', 'caps' => ['tts', 'stt', 'llm', 'embed'], 'fields' => $tts(OpenAiTts::class),
                         'url' => 'https://platform.openai.com/api-keys', 'free' => false],
            'azure' => ['title' => 'Microsoft Azure AI Speech', 'caps' => ['tts', 'stt'], 'fields' => $tts(AzureTts::class),
                        'url' => 'https://portal.azure.com/', 'free' => true],
            'google' => ['title' => 'Google Cloud Text-to-Speech', 'caps' => ['tts'], 'fields' => $tts(GoogleTts::class),
                         'url' => 'https://console.cloud.google.com/apis/credentials', 'free' => true],
            'polly' => ['title' => 'Amazon Polly', 'caps' => ['tts'], 'fields' => $tts(PollyTts::class),
                        'url' => 'https://console.aws.amazon.com/iam/', 'free' => true],
            'elevenlabs' => ['title' => 'ElevenLabs', 'caps' => ['tts', 'stt'], 'fields' => $tts(ElevenLabsTts::class),
                             'url' => 'https://elevenlabs.io/app/settings/api-keys', 'free' => true],
            'deepgram' => ['title' => 'Deepgram', 'caps' => ['stt'], 'fields' => $key,
                           'url' => 'https://console.deepgram.com/', 'free' => true],
            'groq' => ['title' => 'Groq', 'caps' => ['stt', 'llm'], 'fields' => $key,
                       'url' => 'https://console.groq.com/keys', 'free' => true],
            'openrouter' => ['title' => 'OpenRouter', 'caps' => ['llm'], 'fields' => $key,
                             'url' => 'https://openrouter.ai/keys', 'free' => true],
            'claude' => ['title' => 'Anthropic Claude', 'caps' => ['llm'],
                         'fields' => [$key[0], $model(AiProviderManifest::llmModel('claude'))],
                         'url' => 'https://console.anthropic.com/settings/keys', 'free' => false],
            'mistral' => ['title' => 'Mistral AI', 'caps' => ['llm', 'embed'],
                          'fields' => [$key[0], $model(AiProviderManifest::llmModel('mistral'))],
                          'url' => 'https://console.mistral.ai/api-keys', 'free' => true],
            'cohere' => ['title' => 'Cohere', 'caps' => ['llm', 'embed'],
                         'fields' => [$key[0], $model(AiProviderManifest::llmModel('cohere'))],
                         'url' => 'https://dashboard.cohere.com/api-keys', 'free' => true],
            // OpenAI-compatible chat APIs (cloud_llm.py in the AI service talks to them).
            'nvidia' => ['title' => 'NVIDIA NIM (build.nvidia.com)', 'caps' => ['llm'],
                         'fields' => [$key[0], $model(AiProviderManifest::llmModel('nvidia'))],
                         'url' => 'https://build.nvidia.com/settings/api-keys', 'free' => true],
            'qwen' => ['title' => 'Qwen (Alibaba Cloud Model Studio)', 'caps' => ['llm'],
                       'fields' => [$key[0], $model(AiProviderManifest::llmModel('qwen')),
                                    ['key' => 'base_url', 'label' => 'Endpoint', 'secret' => false, 'optional' => true,
                                     'default' => AiProviderManifest::llmBase('qwen')]],
                       'url' => 'https://modelstudio.console.alibabacloud.com/', 'free' => true],
            'ollama'        => ['title' => 'Ollama (Local or Cloud API)', 'caps' => ['llm', 'embed'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false, 'default' => AiProviderManifest::llmBase('ollama')],
                                             ['key' => 'model', 'label' => 'Model', 'secret' => false, 'default' => AiProviderManifest::llmModel('ollama')],
                                             ['key' => 'api_key', 'label' => 'API key (optional for local)', 'secret' => true, 'optional' => true]],
                                'url' => 'https://ollama.com/', 'free' => true],
            'nvidia_local'  => ['title' => 'NVIDIA NIM (Local Container)', 'caps' => ['llm', 'embed'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false, 'default' => AiProviderManifest::llmBase('nvidia_local')],
                                             ['key' => 'model', 'label' => 'Model', 'secret' => false, 'default' => AiProviderManifest::llmModel('nvidia_local')],
                                             ['key' => 'api_key', 'label' => 'API key (optional)', 'secret' => true, 'optional' => true]],
                                'url' => 'https://build.nvidia.com/', 'free' => false],
            'openai_compat' => ['title' => 'OpenAI-compatible (Cloud or Remote Server)', 'caps' => ['stt', 'llm', 'embed', 'tts'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false],
                                             ['key' => 'model', 'label' => 'LLM Model', 'secret' => false],
                                             ['key' => 'stt_model', 'label' => 'STT / Whisper Model', 'secret' => false, 'optional' => true, 'default' => 'whisper-1'],
                                             ['key' => 'tts_model', 'label' => 'TTS Model', 'secret' => false, 'optional' => true, 'default' => 'tts-1'],
                                             ['key' => 'tts_voice', 'label' => 'TTS Voice', 'secret' => false, 'optional' => true, 'default' => 'alloy'],
                                             ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'optional' => true]],
                                'url' => 'https://platform.openai.com/docs/api-reference/chat', 'free' => false],
        ];
        // The settings of one account that most people never change (marked advanced: the dialog folds them).
        foreach ($providers as $type => &$p) {
            $own = array_column($p['fields'], 'key');
            if (in_array('llm', $p['caps'], true)) {
                if (!in_array($type, self::LLM_FIELDS_OWN, true)) {
                    $p['fields'][] = ['key' => 'llm_model', 'label' => 'LLM Model', 'secret' => false, 'optional' => true, 'advanced' => true];
                }
                if ($type !== 'claude') {   // current Claude models refuse temperature
                    $p['fields'][] = ['key' => 'llm_temperature', 'label' => 'Temperature (0-2)', 'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => '0'];
                }
                $p['fields'][] = ['key' => 'llm_max_tokens', 'label' => 'Max tokens', 'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => '512'];
            }
            if (in_array('stt', $p['caps'], true) && !in_array('stt_model', $own, true)) {
                $p['fields'][] = ['key' => 'stt_model', 'label' => 'STT Model', 'secret' => false, 'optional' => true, 'advanced' => true,
                                  'placeholder' => AiProviderManifest::sttModel($type)];
            }
        }
        unset($p);
        foreach (AiProviderManifest::realtimeModels() as $type => $modelName) {
            $providers[$type]['fields'][] = [
                'key' => 'realtime_model', 'label' => t('ai_cloud.realtime_model'),
                'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => $modelName,
            ];
            // The Realtime session of this account: a different voice or detection is a second account ("Copy").
            $rt = static fn (string $key, string $label, string $placeholder, array $extra = []): array =>
                ['key' => $key, 'label' => t($label), 'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => $placeholder] + $extra;
            $providers[$type]['fields'][] = $rt('realtime_voice', 'ai_cloud.realtime_voice', $type === 'google_ai' ? 'Kore' : 'alloy');
            $providers[$type]['fields'][] = $rt('realtime_vad_threshold', 'builtin_ai_agent.rt_vad_threshold', '0.5');
            $providers[$type]['fields'][] = $rt('realtime_silence_ms', 'builtin_ai_agent.rt_vad_silence_ms', '500');
            $providers[$type]['fields'][] = $rt('realtime_temperature', 'builtin_ai_agent.rt_temperature', '');
            if ($type === 'openai' || $type === 'openai_compat') {
                $providers[$type]['fields'][] = $rt('realtime_noise_reduction', 'builtin_ai_agent.rt_noise_reduction', '', ['options' => [
                    '' => t('builtin_ai_agent.nr_off'), 'near_field' => t('builtin_ai_agent.nr_near_field'), 'far_field' => t('builtin_ai_agent.nr_far_field')]]);
            }
        }
        $providers['openai_compat']['fields'][] = [
            'key' => 'realtime_protocol', 'label' => t('ai_cloud.realtime_protocol'),
            'secret' => false, 'optional' => true, 'advanced' => true,
            'options' => [
                '' => t('ai_cloud.realtime_automatic'),
                'openai_ga' => 'OpenAI Realtime GA',
                'openai_beta' => 'OpenAI Realtime Beta',
            ],
        ];
        $providers['openai_compat']['fields'][] = [
            'key' => 'realtime_ws_url', 'label' => t('ai_cloud.realtime_ws_url'),
            'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => 'wss://example.com/v1/realtime',
        ];
        return $providers;
    }


    /**
     * The providers an account can be added for (the Add dialog): fields with their defaults.
     * @return list<array<string, mixed>>
     */
    public static function typesForPage(): array
    {
        $out = [];
        foreach (self::providers() as $type => $p) {
            $out[] = ['type' => $type, 'title' => $p['title'], 'caps' => $p['caps'], 'url' => $p['url'], 'free' => $p['free'],
                      'models' => (object) (AiProviderManifest::knownModels($type)),
                      'fields' => array_map(fn($f) => $f + ['value' => $f['secret'] ? '' : (string) ($f['default'] ?? ''), 'masked' => ''], $p['fields'])];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['title'], $b['title']));      // the "Add a provider account" list: by name
        return $out;
    }
}
