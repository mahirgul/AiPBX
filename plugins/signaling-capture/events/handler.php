<?php
/**
 * Event Hook for `signaling-capture` plugin.
 * Records recent call signaling events in the plugin KV store and optionally
 * forwards them to the configured HTTP collector.
 */
return function (string $eventName, array $envelope, array $settings): void {
    $eventId = (string)($envelope['event_id'] ?? uniqid('sig_', true));
    PluginManager::kvSet('signaling-capture', 'recent_events', $eventId, [
        'event'       => $eventName,
        'occurred_at' => $envelope['occurred_at'] ?? date(DATE_ATOM),
        'data'        => $envelope['data'] ?? [],
    ]);

    $collectorUrl = trim((string)($settings['collector_webhook_url'] ?? ''));
    if ($collectorUrl !== '' && filter_var($collectorUrl, FILTER_VALIDATE_URL)) {
        $headers = ['Content-Type: application/json'];
        $apiKey = trim((string)($settings['collector_api_key'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $ch = curl_init($collectorUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($envelope, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
};
