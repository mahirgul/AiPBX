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
require_once __DIR__ . '/../LocalAiService.php';

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
        $local = self::localChat();
        if ($local !== null) {
            $out[] = $local;
        }
        $cloud = self::cloudChat();
        if ($cloud !== null) {
            $out[] = $cloud;
        }
        return $out;
    }

    /**
     * The local runtime (aipbx_ai), when it is up and carries a chat model.
     * Endpoint: POST http://127.0.0.1:8790/v1/chat — the same bearer token
     * file the Apps service uses.
     */
    private static function localChat(): ?callable
    {
        try {
            if (method_exists('LocalAiService', 'status')) {
                $status = LocalAiService::status();
            } else {
                return null;
            }
            $running = is_array($status) && ($status['running'] ?? false);
            if (!$running || empty($status['models'])) {
                return null;
            }
            $token = self::localToken();
            if ($token === null) {
                return null;
            }
            return function (string $prompt) use ($token): ?string {
                $body = json_encode(['messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 300]);
                $reply = self::http('http://127.0.0.1:8790/v1/chat', $body, ['Authorization: Bearer ' . $token]);
                return $reply;
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

    private static function localToken(): ?string
    {
        foreach (['/etc/aipbx/ai.token'] as $path) {
            if (is_readable($path)) {
                $token = trim((string) file_get_contents($path));
                if ($token !== '') {
                    return $token;
                }
            }
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
        curl_close($ch);
        if ($code !== 200 || !is_string($out) || $out === '') {
            return null;
        }
        $d = json_decode($out, true);
        $text = is_array($d) ? ($d['choices'][0]['message']['content'] ?? ($d['content'] ?? ($d['text'] ?? null))) : null;
        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
