<?php

declare(strict_types=1);

/** Consumes the core's completed transcription; never summarize metadata as message content. */
return static function (string $eventName, array $envelope, array $settings): void {
    if ($eventName !== 'voicemail.transcribed') {
        return;
    }

    $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    $mailbox = (string)($data['mailbox'] ?? $data['extension'] ?? 'unknown');
    $caller = (string)($data['caller'] ?? 'unknown');
    $duration = (int)($data['duration'] ?? 0);
    $msgNum = (string)($data['msg_num'] ?? '1');
    $transcript = trim((string)($data['transcript'] ?? ''));

    $existingSummary = trim((string)($data['summary'] ?? ''));
    if ($transcript === '' && $existingSummary === '') {
        return;
    }

    // Re-delivery of the same event updates its entry instead of creating another summary.
    $itemKey = hash('sha256', $mailbox . ':' . (string)($envelope['event_id'] ??
        (($envelope['occurred_at'] ?? '') . ':' . $msgNum . ':' . $transcript . ':' . $existingSummary)));
    if (PluginManager::kvGet('ai-voicemail', 'summaries', $itemKey) !== null) {
        return;
    }

    $baseUrl = rtrim(trim((string)($settings['api_base_url'] ?? 'https://api.openai.com/v1')), '/');
    $apiKey = trim((string)($settings['api_key'] ?? ''));
    $llmModel = trim((string)($settings['llm_model'] ?? 'gpt-4o-mini'));
    $lang = trim((string)($settings['summary_language'] ?? 'tr'));

    $summary = $existingSummary;
    if ($transcript !== '' && $apiKey !== '' && $baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $baseUrl)) {
        $promptText = "Voicemail from {$caller} to mailbox {$mailbox} ({$duration}s):\n{$transcript}";

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
            $curlOpts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 5,
            ];
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $curlOpts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                $curlOpts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $curlOpts);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (is_string($resp) && $code >= 200 && $code < 300) {
                $decoded = json_decode($resp, true);
                $answer = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
                if ($answer !== '') { $summary = $answer; }
            }
        }
    }

    if ($summary === '') {
        $summary = mb_substr($transcript, 0, 200);
    }

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
