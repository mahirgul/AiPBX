#!/usr/bin/env php
<?php
/**
 * Live call events: listens to Asterisk's manager interface (127.0.0.1 only, call and agent
 * events, it sends no commands) and queues call.ringing / call.offered / call.answered /
 * call.completed for webhooks and plugins (CallEventTracker has the rules).
 *
 * Usage: php web/bin/call_events.php --daemon [--print]   (systemd: aipbx-call-events.service)
 *        --print also writes each event as one JSON line (to watch a test call)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/WebhookService.php';
require_once dirname(__DIR__) . '/src/services/PhonebookService.php';
require_once dirname(__DIR__) . '/src/services/CallEventTracker.php';
if (is_file(dirname(__DIR__) . '/src/core/PluginManager.php')) {
    require_once dirname(__DIR__) . '/src/core/PluginManager.php';
}

/** @return list<string>|null null when the database query fails */
function callEventTrunks(): ?array
{
    try {
        return array_map('strval', getDB()->query('SELECT trunk_name FROM pbx_trunks')->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        fwrite(STDERR, 'call_events: trunk refresh failed: ' . $e->getMessage() . "\n");
        return null;
    }
}

/** Verify cached PDO connection is alive (exit on lost DB so systemd restarts with a fresh connection). */
function callEventEnsureDb(): bool
{
    try {
        getDB()->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        fwrite(STDERR, 'call_events: DB connection lost: ' . $e->getMessage() . "\n");
        return false;
    }
}

/** The address book entry of a number, small enough for an event. */
function callEventContact(string $number): ?array
{
    if (strlen(preg_replace('/\D/', '', $number) ?? '') < 5) {
        return null;
    }
    try {
        $c = PhonebookService::findByNumber($number);
    } catch (Throwable $e) {
        return null; // a database hiccup must not stop the events
    }
    return $c ? ['id' => (int) $c['id'], 'display_name' => (string) $c['display_name'], 'company' => (string) $c['company'],
        'external_id' => $c['external_id'] !== null ? (string) $c['external_id'] : null] : null;
}

/** Inbound callers not in the address book are added when the setting is on. */
function callEventAutoSave(array $data): void
{
    if (($data['direction'] ?? '') !== 'inbound' || !empty($data['caller']['contact'])
        || getSystemSetting('phonebook_auto_save_callers', '0') !== '1') {
        return;
    }
    $number = (string) ($data['caller']['number'] ?? '');
    if (strlen(preg_replace('/\D/', '', $number) ?? '') < 5 || PhonebookService::findByNumber($number)) {
        return;
    }
    $name = trim((string) ($data['caller']['name'] ?? ''));
    PhonebookService::store(0, ['display_name' => $name !== '' ? $name : $number, 'numbers' => [['label' => 'other', 'number' => $number]]]);
}

/** @return resource|null */
function callEventConnect()
{
    $fp = @fsockopen(AMI_HOST, (int) AMI_PORT, $errno, $errstr, 5);
    if (!$fp) {
        fwrite(STDERR, "call_events: AMI connect failed: {$errstr}\n");
        return null;
    }
    stream_set_timeout($fp, 30);
    $login = "Action: Login\r\nUsername: " . AMI_USER . "\r\nSecret: " . AMI_PASS . "\r\nEvents: call,agent\r\n\r\n";
    if (@fwrite($fp, $login) !== strlen($login)) {
        fwrite(STDERR, "call_events: AMI login write failed\n");
        fclose($fp);
        return null;
    }
    $msg = [];
    $lineBuf = '';
    $block = callEventRead($fp, $msg, $lineBuf);
    if ($block !== null && ($block['Response'] ?? '') !== 'Success') {
        $block = callEventRead($fp, $msg, $lineBuf); // the greeting line came first
    }
    if ($block === null || ($block['Response'] ?? '') !== 'Success') {
        fwrite(STDERR, "call_events: AMI login refused\n");
        fclose($fp);
        return null;
    }
    fwrite(STDERR, "call_events: connected to the manager interface\n");
    return $fp;
}

/**
 * One AMI message as key => value; [] on a read timeout (preserving partial frame),
 * null when the connection closed or errored.
 *
 * @param resource $fp
 * @param array<string, string> $msg
 * @return array<string, string>|null
 */
function callEventRead($fp, array &$msg = [], string &$lineBuf = ''): ?array
{
    for (;;) {
        $chunk = @fgets($fp, 8192);
        if ($chunk === false) {
            $meta = stream_get_meta_data($fp);
            return !empty($meta['timed_out']) ? [] : null;
        }
        $lineBuf .= $chunk;
        if (!str_ends_with($lineBuf, "\n")) {
            if (feof($fp)) {
                return null;
            }
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                return [];
            }
            continue;
        }
        $line = rtrim($lineBuf, "\r\n");
        $lineBuf = '';
        if ($line === '') {
            if ($msg !== []) {
                $done = $msg;
                $msg = [];
                return $done;
            }
            continue;
        }
        $p = strpos($line, ': ');
        if ($p !== false) {
            $msg[substr($line, 0, $p)] = substr($line, $p + 2);
        } elseif (str_ends_with($line, ':')) {
            $msg[substr($line, 0, -1)] = '';
        }
    }
}

$argv = $_SERVER['argv'] ?? [];
if (!in_array('--daemon', $argv, true)) {
    fwrite(STDERR, "usage: call_events.php --daemon\n");
    exit(64);
}

$print = in_array('--print', $argv, true);
$tracker = new CallEventTracker(callEventTrunks() ?? [], 'callEventContact');
$backoff = 3;
$refreshAt = time();
for (;;) {
    $fp = callEventConnect();
    if (!$fp) {
        sleep($backoff);
        $backoff = min(60, $backoff * 2);
        continue;
    }
    $backoff = 3;
    $pendingMsg = [];
    $lineBuf = '';
    $pingSentAt = 0;
    while (($ev = callEventRead($fp, $pendingMsg, $lineBuf)) !== null) {
        $now = time();
        if ($now - $refreshAt >= 60) {
            if (class_exists('PluginManager')) {
                PluginManager::resetCache();
            }
            $trunks = callEventTrunks();
            if ($trunks !== null) {
                $tracker->setTrunks($trunks); // a trunk added or renamed on the portal
            } elseif (!callEventEnsureDb()) {
                fclose($fp);
                exit(1);
            }
            $refreshAt = $now;
        }
        if ($ev === []) {
            $tracker->purgeStale($now);
            if ($pingSentAt > 0 && ($now - $pingSentAt) >= 25) {
                fwrite(STDERR, "call_events: AMI ping timed out; reconnecting\n");
                break;
            }
            if ($pingSentAt === 0) {
                $ping = "Action: Ping\r\n\r\n";
                if (@fwrite($fp, $ping) !== strlen($ping)) {
                    fwrite(STDERR, "call_events: AMI ping write failed; reconnecting\n");
                    break;
                }
                $pingSentAt = $now;
            }
            continue;
        }
        $pingSentAt = 0;
        foreach ($tracker->handle($ev, $now) as [$name, $data]) {
            WebhookService::emit($name, $data);
            if ($print) {
                echo json_encode(['event' => $name, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            }
            if ($name === 'call.completed') {
                try {
                    callEventAutoSave($data);
                } catch (Throwable $e) {
                    if (!callEventEnsureDb()) {
                        fclose($fp);
                        exit(1);
                    }
                }
            }
        }
    }
    fclose($fp);
    $tracker->reset();
    sleep(2); // Asterisk restarted: reconnect; cleared stale calls from previous session
}
