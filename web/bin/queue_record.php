#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Queue's post-answer AGI, on the caller channel; no database or shell commands. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
// Drain the AGI environment before sending protocol commands.
while (($line = fgets(STDIN)) !== false && trim($line) !== '') {}

/** @phpstan-impure AGI replies depend on the running call. */
function queueRecordCommand(string $command): ?string
{
    fwrite(STDOUT, $command . "\n");
    fflush(STDOUT);
    $answer = fgets(STDIN);
    return $answer === false ? null : trim($answer);
}
/** @phpstan-impure Channel variables change when MixMonitor starts. */
function queueRecordVariable(string $name): string
{
    $reply = queueRecordCommand('GET VARIABLE ' . $name);
    return $reply !== null && preg_match('/^200 result=1 \((.*)\)$/', $reply, $m) ? $m[1] : '';
}
// Keep caller opt-out and an existing mandatory inbound recording intact.
if (queueRecordVariable('PRIVACY_NO_RECORD') === '1' || queueRecordVariable('MIXMONITOR_FILENAME') !== '') {
    exit;
}
$file = queueRecordVariable('AIPBX_QUEUE_REC_FILE');
$options = queueRecordVariable('AIPBX_QUEUE_REC_OPTIONS');
if (!preg_match('#^/var/spool/asterisk/monitor/inbound_[A-Za-z0-9_+.-]+\.(?:wav|wav49|WAV|gsm|ulaw|alaw|sln|g722)$#D', $file)
    || ($options !== '' && !preg_match('/^B\((?:[5-9]|[1-5][0-9]|60)\)$/D', $options))) {
    exit;
}
$result = queueRecordCommand('EXEC MixMonitor ' . $file . ($options !== '' ? ',' . $options : ''));
if ($result === null || !preg_match('/^200 result=0(?: |$)/', $result)) {
    exit;
}
// MixMonitor reports the real file name (including Asterisk's .WAV conversions).
$actual = queueRecordVariable('MIXMONITOR_FILENAME');
if ($actual !== '' && preg_match('#^/var/spool/asterisk/monitor/inbound_[A-Za-z0-9_+.-]+\.(?:wav|wav49|WAV|gsm|ulaw|alaw|sln|g722)$#D', $actual)) {
    queueRecordCommand('SET VARIABLE CDR(userfield) "' . $actual . '"');
}
