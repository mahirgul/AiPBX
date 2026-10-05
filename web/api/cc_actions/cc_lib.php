<?php
// Shared helpers for the call-center API actions (loaded by the dispatcher)
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

function parseAsteriskQueuesOutput($raw_output) {
    $queues = [];
    $current_queue = null;
    if (!is_array($raw_output)) return $queues;

    foreach ($raw_output as $line) {
        $clean_line = preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", "", $line);
        $trimmed = trim($clean_line);

        if (preg_match('/^([a-zA-Z0-9_-]+)\s+has\s+\d+\s+calls/i', $trimmed, $m)) {
            $current_queue = $m[1];
            if (!isset($queues[$current_queue])) {
                $queues[$current_queue] = [
                    'members' => [],
                    'callers' => []
                ];
            }
            continue;
        }

        if (!$current_queue) continue;

        // Waiting-caller lines ("1. PJSIP/trunk-0000002a (wait: …") are not
        // members — they used to be counted as members and inflated the
        // "logged-in/available agent" count on the board.
        if (preg_match('/^\d+\.\s/', $trimmed)) continue;

        if (preg_match('/(?:PJSIP|Local)\/([0-9a-zA-Z_-]+)/i', $trimmed, $mm)) {
            $ext = $mm[1];
            $is_paused = (bool) preg_match('/\(paused\b/i', $trimmed);
            $is_unavailable = (stripos($trimmed, '(Unavailable)') !== false || stripos($trimmed, '(Invalid)') !== false);
            // A ringing phone alone does not count as a call (separate: is_ringing).
            $is_busy = (bool) preg_match('/\((In use|Busy|Ring\+Inuse|On Hold)\)/i', $trimmed);
            $is_ringing = !$is_busy && stripos($trimmed, '(Ringing)') !== false;

            // MEMBERSHIP ≠ DEVICE STATE: a line in the queue member list means
            // the agent IS a member of the queue, even when the device (WebRTC/
            // SIP registration) is offline.
            $queues[$current_queue]['members'][$ext] = [
                'extension' => $ext,
                'in_queue' => true,
                'is_paused' => $is_paused,
                'is_unavailable' => $is_unavailable,
                'is_busy' => $is_busy,
                'is_ringing' => $is_ringing,
                'raw_line' => $trimmed
            ];
        }
    }

    return $queues;
}

/**
 * Finds the REAL active channel names of an extension in the dual-endpoint
 * architecture. There is NO "PJSIP/<ext>" channel — the real names look like
 * PJSIP/<ext>-sip-XXXXXXXX, PJSIP/<ext>-webrtc-XXXXXXXX or Local/<ext>@....
 * Note: the AMI CoreShowChannels action is NOT in the manager user's
 * restricted privilege class (read=originate,call) (it returns "Permission
 * denied") — instead of widening the privileges, the CLI is used (through the
 * OS user; the first field, the channel name, is stable across Asterisk
 * versions).
 */
function findAgentChannels($ext) {
    $ext = preg_replace('/[^0-9]/', '', $ext);
    if ($ext === '') return [];

    @exec("asterisk -rx " . escapeshellarg("core show channels concise"), $lines);
    $channels = [];
    $pattern = '#^(PJSIP/' . preg_quote($ext, '#') . '-(sip|webrtc|mob-webrtc)-|Local/' . preg_quote($ext, '#') . '@)#';
    foreach ($lines as $line) {
        $chan = explode('!', $line)[0] ?? '';
        if ($chan !== '' && preg_match($pattern, $chan)) {
            $channels[] = $chan;
        }
    }
    return $channels;
}

/**
 * Finds the CALLER's channel for a transfer.
 *
 * The bug measured on 2026-09-15: the transfer Redirected the AGENT's
 * channel. A single-channel Redirect pulls that channel out of the bridge;
 * the caller is left alone, drops out of Queue() and hangs up in the `h`
 * extension. In the live log the caller got Hangup in the same second the
 * agent went to 8915.
 *
 * The difficulty: queue calls get a Local channel pair in between, which is
 * not optimized away (because of MixMonitor + Queue 'tT'):
 *
 *   bridge A:  PJSIP/3002-webrtc  +  Local/3002@from-internal-pbx;2
 *   bridge B:  Local/3002@from-internal-pbx;1  +  PJSIP/ccisgw   <- caller
 *
 * So the bridge chain is walked across the Local pair.
 *
 * @param string     $ext   the agent's extension
 * @param array|null $lines "core show channels concise" lines (for tests)
 * @return string|null the caller's channel name, null if not found
 */
function findCallerChannelForAgent($ext, $lines = null) {
    $ext = preg_replace('/[^0-9]/', '', $ext);
    if ($ext === '') return null;

    if ($lines === null) {
        $lines = [];
        @exec("asterisk -rx " . escapeshellarg("core show channels concise"), $lines);
    }

    // "core show channels concise" has 14 columns separated by '!'
    // (see Asterisk main/cli.c CONCISE_FORMAT_STRING): [12] is the bridge id.
    $kopru = [];
    $koprudekiler = [];
    foreach ($lines as $l) {
        $c = explode('!', $l);
        if (count($c) < 14 || $c[0] === '') continue;
        $kopru[$c[0]] = $c[12];
        if ($c[12] !== '') $koprudekiler[$c[12]][] = $c[0];
    }

    $koprudekiEs = function ($kanal) use ($kopru, $koprudekiler) {
        $b = $kopru[$kanal] ?? '';
        if ($b === '') return null;
        foreach ($koprudekiler[$b] ?? [] as $k) {
            if ($k !== $kanal) return $k;
        }
        return null;
    };

    $cihazDeseni = '#^PJSIP/' . preg_quote($ext, '#') . '-(sip|webrtc|mob-webrtc)-#';
    $kendiDeseni = '#^(PJSIP/' . preg_quote($ext, '#') . '-|Local/' . preg_quote($ext, '#') . '@)#';

    foreach (array_keys($kopru) as $kanal) {
        if (!preg_match($cihazDeseni, $kanal)) continue;

        $es = $koprudekiEs($kanal);
        if ($es === null) continue;

        // If a Local pair sits in between, move on to the other half's bridge.
        if (preg_match('#^(Local/.*);2$#', $es, $m)) {
            $es = $koprudekiEs($m[1] . ';1');
            if ($es === null) continue;
        }

        // The agent's own legs cannot be the caller.
        if (preg_match($kendiDeseni, $es)) continue;

        return $es;
    }

    return null;
}

/**
 * Finds the number of the party (customer) the agent in a call is connected
 * to, and the active call duration.
 */
function findAgentCallDetails($ext, $lines = null): array
{
    $ext = preg_replace('/[^0-9]/', '', $ext);
    if ($ext === '') {
        return ['connected_number' => '', 'duration' => 0, 'duration_formatted' => '00:00', 'caller_channel' => ''];
    }

    if ($lines === null) {
        $lines = [];
        @exec("asterisk -rx " . escapeshellarg("core show channels concise"), $lines);
    }

    $chanData = [];
    foreach ($lines as $l) {
        $c = explode('!', $l);
        if (count($c) < 14 || $c[0] === '') continue;
        $chanData[$c[0]] = [
            'callerid' => trim($c[7] ?? ''),
            // 10 = amaflags (always 3), 11 = duration — 10 used to be read, so
            // every call showed "00:03" (verified with live output).
            'duration' => intval($c[11] ?? 0),
        ];
    }

    $callerChan = findCallerChannelForAgent($ext, $lines);
    if (!$callerChan) {
        return ['connected_number' => '', 'duration' => 0, 'duration_formatted' => '00:00', 'caller_channel' => ''];
    }

    $info = $chanData[$callerChan] ?? [];
    $callerNum = $info['callerid'] ?? '';
    $duration = $info['duration'] ?? 0;

    // If callerid is empty in the concise output, ask the Asterisk channel directly
    if ($callerNum === '') {
        @exec("asterisk -rx " . escapeshellarg("channel get {$callerChan} CALLERID(num)"), $cidOut);
        foreach ($cidOut ?: [] as $co) {
            if (preg_match('/Value:\s*([0-9+]+)/i', $co, $m)) {
                $callerNum = $m[1];
                break;
            }
        }
    }

    $m = floor($duration / 60);
    $s = $duration % 60;
    $durationFormatted = sprintf('%02d:%02d', $m, $s);

    return [
        'connected_number' => $callerNum,
        'duration' => $duration,
        'duration_formatted' => $durationFormatted,
        'caller_channel' => $callerChan,
    ];
}

/**
 * Guarantees ATOMIC per-agent operations on cc_pause_logs.
 * Two concurrent requests for the same agent (two tabs/devices, a double
 * click) can race on the UPDATE+INSERT pair (TOCTOU) — serialized with a
 * MySQL named lock.
 */
function withAgentPauseLock($db, $ext, callable $fn) {
    $lock_name = 'cc_pause_' . preg_replace('/[^0-9]/', '', $ext);
    $got = $db->query("SELECT GET_LOCK(" . $db->quote($lock_name) . ", 3)")->fetchColumn();
    if ($got != 1) return false;
    try {
        return $fn();
    } finally {
        $db->query("SELECT RELEASE_LOCK(" . $db->quote($lock_name) . ")");
    }
}

function sendAMICommand($cmd) {
    // AMI credentials come from the config.php constants via /etc/ai-pbx.env
    $fp = @fsockopen(AMI_HOST, AMI_PORT, $errno, $errstr, 3);
    if (!$fp) return false;

    stream_set_timeout($fp, 3);
    $ami_user = AMI_USER;
    $ami_pass = AMI_PASS;

    fputs($fp, "Action: Login\r\nUsername: {$ami_user}\r\nSecret: {$ami_pass}\r\n\r\n");

    $authenticated = false;
    $start = time();
    while (!feof($fp) && (time() - $start < 3)) {
        $line = fgets($fp, 4096);
        if (strpos($line, 'Response: Success') !== false) {
            $authenticated = true;
        }
        if (trim($line) === '' && $authenticated) {
            break;
        }
    }

    if (!$authenticated) {
        fclose($fp);
        return false;
    }

    fputs($fp, $cmd);
    $response = "";
    $start = time();
    $saw_event = false;
    $saw_complete = false;
    while (!feof($fp) && (time() - $start < 3)) {
        $line = fgets($fp, 4096);
        $response .= $line;
        if (stripos($line, 'Event:') === 0) {
            $saw_event = true;
            if (stripos($line, 'Complete') !== false) {
                $saw_complete = true;
            }
        }
        if (trim($line) === '') {
            // Single-response actions (Originate/Hangup/Redirect etc.): stop at
            // the first empty line. Multi-event actions (CoreShowChannels etc.):
            // keep reading until the "...Complete" event is seen.
            if (!$saw_event && strpos($response, 'Response:') !== false) {
                break;
            }
            if ($saw_complete) {
                break;
            }
        }
    }

    fputs($fp, "Action: Logoff\r\n\r\n");
    fclose($fp);
    return $response;
}
