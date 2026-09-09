<?php
// Çağrı merkezi API aksiyonları için ortak yardımcılar (dispatcher tarafından yüklenir)
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

        if (preg_match('/(?:PJSIP|Local)\/([0-9a-zA-Z_-]+)/i', $trimmed, $mm)) {
            $ext = $mm[1];
            $is_paused = (stripos($trimmed, '(paused)') !== false || stripos($trimmed, 'paused') !== false);
            $is_unavailable = (stripos($trimmed, '(Unavailable)') !== false || stripos($trimmed, '(Invalid)') !== false);
            $is_busy = (stripos($trimmed, '(In use)') !== false || stripos($trimmed, '(Busy)') !== false || stripos($trimmed, '(Ringing)') !== false);

            // ÜYELİK ≠ CİHAZ DURUMU: kuyruk üyeleri listesinde satır varsa temsilci
            // kuyruğun ÜYESİDİR; cihazı (WebRTC/SIP kaydı) çevrimdışı olsa bile.
            $queues[$current_queue]['members'][$ext] = [
                'extension' => $ext,
                'in_queue' => true,
                'is_paused' => $is_paused,
                'is_unavailable' => $is_unavailable,
                'is_busy' => $is_busy,
                'raw_line' => $trimmed
            ];
        }
    }

    return $queues;
}

/**
 * Dual-Endpoint mimaride bir dahilinin GERÇEK aktif kanal adlarını bulur.
 * "PJSIP/<ext>" diye bir kanal YOKTUR — gerçek adlar PJSIP/<ext>-sip-XXXXXXXX,
 * PJSIP/<ext>-webrtc-XXXXXXXX veya Local/<ext>@... şeklindedir.
 * Not: AMI CoreShowChannels action'ı manager kullanıcısının
 * kısıtlı yetki sınıfında (read=originate,call) YOK ("Permission denied"
 * döner) — yetkiyi genişletmek yerine CLI (OS kullanıcısı üzerinden,
 * Asterisk sürümünden bağımsız stabil ilk alan: kanal adı) kullanılır.
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
 * cc_pause_logs üzerinde ajan başına ATOMİK işlem garantisi.
 * Aynı ajan için eşzamanlı iki istek (iki sekme/cihaz, çift tıklama) UPDATE+INSERT
 * çiftini yarışa sokabilir (TOCTOU) — MySQL adlandırılmış kilidiyle serileştirilir.
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
    // AMI kimlik bilgileri /etc/ai-pbx.env üzerinden config.php sabitlerinden gelir
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
            // Tekli-response action (Originate/Hangup/Redirect vb.): ilk boş
            // satırda dur. Çoklu-event action (CoreShowChannels vb.): "...Complete"
            // event'i görülene kadar okumaya devam et.
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
