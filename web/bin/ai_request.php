#!/usr/bin/env php
<?php
/**
 * Started by the dialplan of a voice-requests AI application after the call
 * has left the AI service (src/sync/SyncAiApps.php), in the background:
 * reads the call's result from the local AI service, writes it to the request
 * log (ai_requests) and, when the recognised request has an e-mail address,
 * sends the "AI request" mail with the caller's recording attached.
 *
 * Usage: ai_request.php <app id> <call uuid> <dialplan key> [<caller name, URI-encoded>]
 * (The dialplan passes the name through URIENCODE, so nothing reaches the
 * shell of System() but letters, digits and %.)
 * Runs as the asterisk user, which cannot read the API token: the service
 * accepts the dialplan key for this call's details.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ai/AiAppService.php';
require_once dirname(__DIR__) . '/src/services/MailTemplateService.php';

if (php_sapi_name() !== 'cli') {
    exit(1);
}
$appId = (int) ($argv[1] ?? 0);
$uuid = strtolower((string) ($argv[2] ?? ''));
$key = strtolower((string) ($argv[3] ?? ''));
if ($appId <= 0 || !preg_match('/^[0-9a-f-]{36}$/', $uuid) || !preg_match('/^[0-9a-f]{64}$/', $key)) {
    fwrite(STDERR, "usage: ai_request.php <app id> <uuid> <key>\n");
    exit(2);
}

$get = function (string $path) use ($key): array {
    $ch = curl_init('http://127.0.0.1:8790' . $path . (str_contains($path, '?') ? '&' : '?') . 'key=' . $key);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3]);
    $body = curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_string($body) ? $body : ''];
};

try {
    $app = AiAppService::get($appId);
    if ($app === null) {
        exit(0);
    }
    [$status, $body] = $get('/v1/calls/' . $uuid);
    $call = $status === 200 ? json_decode($body, true) : null;
    if (!is_array($call)) {
        fwrite(STDERR, "ai_request: no result for {$uuid} (HTTP {$status})\n");
        exit(0);
    }
    $intentId = (string) ($call['intent'] ?? '');
    $intent = null;
    foreach (AiAppService::config($app)['intents'] ?? [] as $i) {
        if ($i['id'] === $intentId) {
            $intent = $i;
        }
    }
    $transcript = implode(' / ', array_filter(array_map('strval', (array) ($call['transcripts'] ?? []))));
    $caller = mb_substr((string) ($call['caller'] ?? ''), 0, 40);
    $callerName = mb_substr(trim(rawurldecode((string) ($argv[4] ?? ''))), 0, 80);
    getDB()->prepare('INSERT IGNORE INTO ai_requests (app_id, call_uuid, caller, caller_name, intent_id, intent_name, transcript, score)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$appId, $uuid, $caller, $callerName, $intent ? $intentId : 'none',
                   $intent['name'] ?? '', $transcript, (float) ($call['score'] ?? 0)]);

    if ($intent && ($intent['email'] ?? '') !== '') {
        $attachments = [];
        $tmp = null;
        if (!empty($call['audio'])) {
            [$st, $wav] = $get('/v1/calls/' . $uuid . '/audio');
            if ($st === 200 && str_starts_with($wav, 'RIFF')) {
                $tmp = tempnam(sys_get_temp_dir(), 'aireq');
                file_put_contents($tmp, $wav);
                $attachments[] = ['path' => $tmp, 'name' => 'request-' . date('Ymd-His') . '.wav', 'type' => 'audio/wav'];
            }
        }
        $lang = MailTemplateService::systemLanguage();
        $domain = getSystemSetting('pjsip_external_domain', '') ?: portalEnv('PORTAL_DOMAIN', gethostname() ?: 'localhost');
        $res = MailTemplateService::send('ai_request', $intent['email'], $lang, [
            'request' => $intent['name'], 'caller' => trim($callerName . ' ' . $caller), 'caller_name' => $callerName,
            'caller_number' => $caller, 'transcript' => $transcript !== '' ? $transcript : '—', 'app' => (string) $app['title'],
            'date' => date('d.m.Y H:i'), 'portal_link' => 'https://' . $domain . '/ai-apps',
        ], [], $attachments);
        if ($tmp) {
            @unlink($tmp);
        }
        if (!empty($res['success'])) {
            getDB()->prepare('UPDATE ai_requests SET mail_sent = 1 WHERE call_uuid = ?')->execute([$uuid]);
        } else {
            fwrite(STDERR, 'ai_request: mail failed: ' . ($res['error'] ?? '?') . "\n");
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ai_request: ' . $e->getMessage() . "\n");
}
