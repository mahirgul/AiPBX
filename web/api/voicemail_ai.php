<?php
/**
 * Voicemail as text for the voicemail scripts (bin/voicemail_mail.php,
 * bin/voicemail_notify.php), which run as the asterisk user and cannot read
 * the AI keys: they ask here, on this server only, signed (X-AiPBX-Internal,
 * VoicemailAiService::internalToken). Answers {"transcript", "summary"} of the
 * requested queue job or newest INBOX message of the mailbox, or {} when unavailable.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/services/VoicemailAiService.php';

header('Content-Type: application/json');
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_X_FORWARDED_HOST']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$local
    || !hash_equals(VoicemailAiService::internalToken(), (string) ($_SERVER['HTTP_X_AIPBX_INTERNAL'] ?? ''))) {
    http_response_code(403);
    echo '{}';
    exit;
}
$mailbox = preg_replace('/[^0-9]/', '', (string) ($_POST['mailbox'] ?? ''));
$jobId = max(0, (int) ($_POST['job_id'] ?? 0));
$r = $mailbox !== '' ? VoicemailAiService::forNewest($mailbox, $jobId) : null;
echo json_encode($r ?? new stdClass(), JSON_UNESCAPED_UNICODE);
