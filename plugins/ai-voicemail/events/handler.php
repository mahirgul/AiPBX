<?php

declare(strict_types=1);

/**
 * Event handler for `ai-voicemail` plugin (`voicemail.received`).
 *
 * Connects to the configured OpenAI-compatible endpoint to transcribe/summarize
 * the voicemail and stores the structured result in the plugin KV store.
 */
return static function (string $eventName, array $envelope, array $settings): void {
    if ($eventName !== 'voicemail.received') {
        return;
    }

    $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    $mailbox = (string)($data['mailbox'] ?? 'unknown');
    $caller = (string)($data['caller'] ?? 'unknown');
    $duration = (int)($data['duration'] ?? 0);
    $msgNum = (string)($data['msg_num'] ?? '1');
    $transcript = trim((string)($data['transcript'] ?? ''));

    $baseUrl = rtrim(trim((string)($settings['api_base_url'] ?? 'https://api.openai.com/v1')), '/');
    $apiKey = trim((string)($settings['api_key'] ?? ''));
    $llmModel = trim((string)($settings['llm_model'] ?? 'gpt-4o-mini'));
    $lang = trim((string)($settings['summary_language'] ?? 'tr'));

    $summary = '';
    if ($apiKey !== '' && $baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $promptText = $transcript !== ''
            ? "Voicemail from {$caller} to mailbox {$mailbox} ({$duration}s):\n{$transcript}"
            : "New voicemail left by {$caller} for mailbox {$mailbox}, duration {$duration} seconds.";

        $langInstruction = $lang === 'tr'
            ? 'Özet ve aksiyon maddesini kısa ve net Türkçe olarak yaz.'
            : ($lang === 'en' ? 'Write a concise summary and action item in English.' : 'Summarize concisely in the same language as the message.');

        $payload = json_encode([
            'model' => $llmModel !== '' ? $llmModel : 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => "You are an executive PBX voicemail assistant. {$langInstruction}"],
                ['role' => 'user', 'content' => $promptText],
            ],
            'max_tokens' => 250,
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($baseUrl . '/chat/completions');
        if ($ch !== false && is_string($payload)) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (is_string($resp) && $code >= 200 && $code < 300) {
                $decoded = json_decode($resp, true);
                $summary = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
            }
        }
    }

    if ($summary === '') {
        $summary = $transcript !== ''
            ? mb_substr($transcript, 0, 200)
            : sprintf('Mailbox %s — %s (%ds)', $mailbox, $caller, $duration);
    }

    $itemKey = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $mailbox . '_' . $msgNum);
    PluginManager::kvSet('ai-voicemail', 'summaries', $itemKey, [
        'mailbox'     => $mailbox,
        'caller'      => $caller,
        'duration'    => $duration,
        'msg_num'     => $msgNum,
        'transcript'  => $transcript,
        'summary'     => $summary,
        'occurred_at' => $envelope['occurred_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
    ]);
};
