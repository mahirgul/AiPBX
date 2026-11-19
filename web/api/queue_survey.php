<?php

declare(strict_types=1);

/**
 * Loopback endpoint for [sub-queue-survey]: Asterisk stores the score (1-5) a caller gave after
 * a queue agent hung up. Answers "OK" or "NO".
 */
// Asterisk on this server only; a request that came through a proxy is not it.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)
    || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
    || !empty($_SERVER['HTTP_X_FORWARDED_HOST'])
    || !empty($_SERVER['HTTP_FORWARDED'])
    || !empty($_SERVER['HTTP_X_REAL_IP'])
    || !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'NO';
    exit(0);
}

define('AIPBX_NO_SESSION', true);
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
$queue = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['q'] ?? ''));
$score = (int) ($_GET['s'] ?? 0);
if ($queue === '' || $score < 1 || $score > 5) {
    echo 'NO';
    exit(0);
}
try {
    // The caller is kept so a privacy request (or the retention job) can clear it with the call data.
    getDB()->prepare('INSERT IGNORE INTO queue_survey_results (queue_name, agent, caller, score, uniqueid) VALUES (?, ?, ?, ?, ?)')->execute([
        $queue,
        substr((string) preg_replace('/[^0-9]/', '', (string) ($_GET['a'] ?? '')), 0, 20),
        substr((string) preg_replace('/[^0-9]/', '', (string) ($_GET['c'] ?? '')), 0, 32),
        $score,
        substr((string) preg_replace('/[^0-9.]/', '', (string) ($_GET['u'] ?? '')), 0, 64),
    ]);
    echo 'OK';
} catch (\Throwable $e) {
    error_log('[queue_survey] ' . $e->getMessage());
    echo 'NO';
}
