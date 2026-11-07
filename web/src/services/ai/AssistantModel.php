<?php
/**
 * AI → Assistant: the model behind the Dialplan editor.
 *
 * "Ask" chain, cheap to expensive, in AiPBX's own idiom (the rules of
 * AssistantPlanner always run first; a model is only consulted for what the
 * rules could not resolve):
 *
 *   1. AssistantPlanner's rule engine      — free, instant, offline.
 *   2. The local AI runtime, when the box runs one (ai/aipbx_ai's
 *      /v1/chat, the same service the Apps page uses) — offline.
 *   3. A cloud provider of CloudAiService — Gemini, NVIDIA, Groq, OpenRouter,
 *      Qwen and any OpenAI-compatible address, which is exactly how a local
 *      Ollama on 127.0.0.1:11434/v1 plugs in without new code.
 *
 * The first usable layer answers; none is a single point of failure. A
 * refusal says so; nothing is defaulted.
 */

require_once __DIR__ . '/AssistantPlanner.php';
require_once __DIR__ . '/CloudAiService.php';

class AssistantModel
{
    /**
     * The ask() for AssistantPlanner::plan(). Returns the model's reply as
     * one JSON object string, or null when no model layer is reachable —
     * the caller then falls back to the planner's own refusal, which is the
     * correct answer for "I did not understand".
     */
    public static function ask(string $prompt): ?string
    {
        foreach (self::layers() as $layer) {
            $reply = $layer($prompt);
            if (is_string($reply) && trim($reply) !== '') {
                return $reply;
            }
        }
        return null;
    }

    /** @return array<int, callable> the model layers, cheap to expensive */
    private static function layers(): array
    {
        $out = [];
        $plugin = self::pluginChat();
        if ($plugin !== null) {
            $out[] = $plugin;
        }
        $cloud = self::cloudChat();
        if ($cloud !== null) {
            $out[] = $cloud;
        }
        return $out;
    }

    /**
     * Model endpoint configured directly in the `ai-assistant` plugin settings
     * (OpenAI, Groq, OpenRouter, or self-hosted Ollama/vLLM).
     */
    private static function pluginChat(): ?callable
    {
        try {
            require_once dirname(__DIR__, 2) . '/core/PluginManager.php';
            if (!PluginManager::isEnabled('ai-assistant')) {
                return null;
            }
            $cfg = PluginManager::getAllSettings('ai-assistant', false);
            $baseUrl = rtrim(trim((string)($cfg['api_base_url'] ?? '')), '/');
            $apiKey = trim((string)($cfg['api_key'] ?? ''));
            $model = trim((string)($cfg['model'] ?? 'gpt-4o-mini'));
            if ($baseUrl === '' || $apiKey === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                return null;
            }
            return function (string $prompt) use ($baseUrl, $apiKey, $model): ?string {
                $body = json_encode([
                    'model' => $model !== '' ? $model : 'gpt-4o-mini',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.1,
                    'max_tokens' => 300,
                ]);
                if (!is_string($body)) {
                    return null;
                }
                return self::http($baseUrl . '/chat/completions', $body, ['Authorization: Bearer ' . $apiKey]);
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** First configured CloudAiService LLM account, or null. */
    private static function cloudChat(): ?callable
    {
        $ids = CloudAiService::withCap('llm');
        foreach ($ids as $id) {
            try {
                if (!CloudAiService::configured($id)) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            return function (string $prompt) use ($id): ?string {
                try {
                    return CloudAiService::chat($id, $prompt, 300);
                } catch (\Throwable $e) {
                    return null;
                }
            };
        }
        return null;
    }

    /** Small POST helper: returns the body string, or null on any failure. */
    private static function http(string $url, string $body, array $headers): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code !== 200 || !is_string($out) || $out === '') {
            return null;
        }
        $d = json_decode($out, true);
        $text = is_array($d) ? ($d['choices'][0]['message']['content'] ?? ($d['content'] ?? ($d['text'] ?? null))) : null;
        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
