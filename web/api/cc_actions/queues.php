<?php
// Kuyruk izleme aksiyonları: get_queues, toggle_queue, get_supervisor_agents, get_live_calls
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }
require_once __DIR__ . '/../../src/queue_helper.php';

if ($action === 'get_queues') {
    // Fetch all active queues from database
    $stmt = $db->query("SELECT id, queue_name, title, members_json, static_members_json FROM pbx_queues WHERE is_active = 1 ORDER BY queue_name ASC");
    $db_queues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Asterisk live queue member status
    @exec("asterisk -rx " . escapeshellarg("queue show"), $raw_q_output);
    $parsed_queues = parseAsteriskQueuesOutput($raw_q_output);

    $queues = [];
    foreach ($db_queues as $q) {
        $q_name = $q['queue_name'];
        $q_title = $q['title'] ?: $q_name;

        $members = json_decode($q['members_json'] ?? '[]', true) ?: [];
        $is_assigned = in_array((string)$user_ext, array_map('strval', $members));
        $is_static = in_array((string)$user_ext, QueueHelper::staticMembersOf($q), true);

        $in_queue = false;
        $is_paused = false;
        $device_offline = false;

        if (isset($parsed_queues[$q_name]['members'][$user_ext])) {
            $m_info = $parsed_queues[$q_name]['members'][$user_ext];
            $in_queue = $m_info['in_queue'];
            $is_paused = $m_info['is_paused'];
            $device_offline = $m_info['is_unavailable'];
        }

        $queues[] = [
            'id' => $q['id'],
            'queue_name' => $q_name,
            'title' => $q_title,
            'assigned' => $is_assigned,
            'is_static' => $is_static,
            'in_queue' => $in_queue,
            'is_paused' => $is_paused,
            'device_offline' => $device_offline
        ];
    }

    echo json_encode(['success' => true, 'queues' => $queues, 'agent_extension' => $user_ext]);
    exit;
}

if ($action === 'get_supervisor_agents') {
    // 1. Fetch active queues and their members_json
    $stmt = $db->query("SELECT queue_name, title, members_json FROM pbx_queues WHERE is_active = 1");
    $db_queues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Asterisk live queue status
    @exec("asterisk -rx " . escapeshellarg("queue show"), $raw_q_output);
    $q_output = array_map(function($l) {
        return preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", "", $l);
    }, $raw_q_output ?: []);

    // Parse member lines from Asterisk queue show
    $member_statuses = [];
    if (is_array($q_output)) {
        foreach ($q_output as $line) {
            if (preg_match('/(?:PJSIP|Local)\/([0-9]+)/i', $line, $matches)) {
                $ext_found = $matches[1];

                $is_paused = (stripos($line, '(paused)') !== false);
                $is_unavailable = (stripos($line, '(Unavailable)') !== false || stripos($line, '(Invalid)') !== false);
                $is_busy = (stripos($line, '(In use)') !== false || stripos($line, '(Busy)') !== false || stripos($line, '(Ringing)') !== false);
                $is_idle = (stripos($line, '(Not in use)') !== false);

                if ($is_unavailable) {
                    $status_key = 'OFFLINE';
                    $in_q = false;
                } elseif ($is_busy) {
                    $status_key = 'BUSY';
                    $in_q = true;
                } elseif ($is_idle) {
                    $status_key = $is_paused ? 'PAUSED' : 'READY';
                    $in_q = true;
                } else {
                    $status_key = 'OFFLINE';
                    $in_q = false;
                }

                // Do not overwrite an already active status (READY/BUSY/PAUSED) with OFFLINE
                if (!isset($member_statuses[$ext_found]) || $status_key !== 'OFFLINE') {
                    $member_statuses[$ext_found] = [
                        'status_key' => $status_key,
                        'in_queue' => $in_q,
                        'is_paused' => $is_paused
                    ];
                }
            }
        }
    }

    // 3. Map active users with extensions from sys_users
    $users_stmt = $db->query("SELECT extension, full_name, role FROM sys_users WHERE extension IS NOT NULL AND extension != ''");
    $users_map = [];
    foreach ($users_stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $users_map[(string)$u['extension']] = $u;
    }

    @exec("asterisk -rx " . escapeshellarg("core show channels concise"), $concise_lines);

    $agents_list = [];
    foreach ($db_queues as $q) {
        $q_name = $q['queue_name'];
        $q_title = $q['title'] ?: $q_name;
        $members = json_decode($q['members_json'] ?? '[]', true) ?: [];

        foreach ($members as $ext) {
            $ext_str = (string)$ext;
            if (empty($ext_str)) continue;

            // STRICT CHECK: Extension MUST exist in sys_users! If deleted, skip!
            if (!isset($users_map[$ext_str])) {
                continue;
            }

            $full_name = $users_map[$ext_str]['full_name'];
            $st_info = $member_statuses[$ext_str] ?? ['status_key' => 'OFFLINE', 'in_queue' => false, 'is_paused' => false];

            $call_details = ($st_info['status_key'] === 'BUSY')
                ? findAgentCallDetails($ext_str, $concise_lines)
                : ['connected_number' => '', 'duration' => 0, 'duration_formatted' => '00:00'];

            $agents_list[] = [
                'extension' => $ext_str,
                'full_name' => $full_name,
                'queue_name' => $q_name,
                'queue_title' => $q_title,
                'status_key' => $st_info['status_key'],
                'in_queue' => $st_info['in_queue'],
                'is_paused' => (bool)$st_info['is_paused'],

                // cc_board (templates/views/cc_board/index.php) BU alanlari okur:
                // is_in_call -> "Görüşmede", is_paused -> "Molada",
                // is_logged_in -> "Boşta", hicbiri yoksa "Çevrimdışı".
                'is_logged_in' => (bool)$st_info['in_queue'],
                'is_in_call' => ($st_info['status_key'] === 'BUSY'),
                'connected_number' => $call_details['connected_number'] ?? '',
                'duration' => $call_details['duration'] ?? 0,
                'duration_formatted' => $call_details['duration_formatted'] ?? '00:00',
                'queues' => [$q_name],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'agents' => $agents_list
    ]);
    exit;
}

if ($action === 'spy_call') {
    $role = $_SESSION['user_role'] ?? '';
    if (!in_array($role, ['admin', 'cc_manager', 'cc_supervisor'], true)) {
        echo json_encode(['success' => false, 'error' => 'Bu işlem için yetkiniz bulunmamaktadır.']);
        exit;
    }

    $target_ext = preg_replace('/[^0-9]/', '', $_POST['target_ext'] ?? '');
    $mode = $_POST['mode'] ?? 'spy';
    $supervisor_ext = preg_replace('/[^0-9]/', '', $_SESSION['user_extension'] ?? $user_ext);

    if (empty($target_ext) || empty($supervisor_ext)) {
        echo json_encode(['success' => false, 'error' => 'Hedef temsilci veya yönetici dahili numarası bulunamadı.']);
        exit;
    }

    $spy_flags = match($mode) {
        'whisper' => 'qw',
        'barge' => 'qB',
        default => 'q'
    };

    $ami_action = [
        'Action' => 'Originate',
        'Channel' => "Local/{$supervisor_ext}@from-internal-pbx-ortak/n",
        'Application' => 'ChanSpy',
        'Data' => "PJSIP/{$target_ext},{$spy_flags}",
        'CallerID' => "SPY: {$target_ext} <*90>",
        'Priority' => '1',
        'Async' => 'true'
    ];

    $res = AsteriskHelper::queryAMI($ami_action);
    if (!empty($res['Response']) && strtolower($res['Response']) === 'success') {
        $mode_labels = ['spy' => 'Gizli Dinleme', 'whisper' => 'Fısıldama', 'barge' => 'Araya Girme'];
        $label = $mode_labels[$mode] ?? 'Dinleme';
        echo json_encode(['success' => true, 'message' => "Telefonunuz çaldırılıyor ({$label}). Açtığınızda {$target_ext} numaralı temsilcinin görüşmesine bağlanacaksınız."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Dinleme başlatılamadı: ' . ($res['Message'] ?? 'Bilinmeyen hata')]);
    }
    exit;
}

if ($action === 'toggle_queue') {
    $q_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['queue_name'] ?? '');
    $do_login = ($_POST['login'] ?? '1') === '1';

    if (empty($q_name) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz kuyruk adı veya dahili']);
        exit;
    }

    // Check if user is assigned to this queue
    if (!QueueHelper::isAssignedMember($user_ext, $q_name) && $do_login) {
        echo json_encode(['success' => false, 'error' => "Dahili numaranız ($user_ext) bu kuyruğa ($q_name) tanımlı değildir!"]);
        exit;
    }

    if (!$do_login && QueueHelper::isStaticMember($user_ext, $q_name)) {
        echo json_encode(['success' => false, 'error' => 'Bu kuyrukta statik temsilcisiniz; kuyruktan çıkamazsınız, sadece mola verebilirsiniz.']);
        exit;
    }

    QueueHelper::setMembership($user_ext, $q_name, $do_login);
    $msg = $do_login ? "$q_name kuyruğuna giriş yapıldı" : "$q_name kuyruktan çıkış yapıldı";

    echo json_encode(['success' => true, 'message' => $msg]);
    exit;
}

if ($action === 'get_live_calls') {
    @exec("asterisk -rx " . escapeshellarg("queue show"), $q_output);
    @exec("asterisk -rx " . escapeshellarg("core show channels concise"), $chan_output);

    // Build title map from DB queues
    $stmt_q = $db->query("SELECT queue_name, title FROM pbx_queues");
    $q_title_map = [];
    if ($stmt_q) {
        foreach ($stmt_q->fetchAll(PDO::FETCH_ASSOC) as $row_q) {
            $q_title_map[$row_q['queue_name']] = $row_q['title'] ?: $row_q['queue_name'];
        }
    }

    $waiting_calls = [];
    $active_calls = [];

    // Parse Queue Callers waiting
    $current_queue = '';
    if (is_array($q_output)) {
        foreach ($q_output as $line) {
            $clean = preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", "", $line);
            if (preg_match('/^([a-zA-Z0-9_-]+)\s+has\s+(\d+)\s+calls/i', trim($clean), $m)) {
                $current_queue = $m[1];
            }
            // Match callers line: 1. PJSIP/trunk_sip-00000001 (wait: 01:23, pri: 0)
            if (preg_match('/^\s*\d+\.\s+([^\s]+)\s+\(wait:\s*([0-9:]+)/i', $clean, $cm)) {
                $chan = trim($cm[1]);
                $wait_time = trim($cm[2]);

                // Try to extract caller ID and uniqueid from channels output
                // NOT: "core show channels concise" alanları ':' değil '!' ile ayrılır
                // (bkz. Asterisk main/cli.c CONCISE_FORMAT_STRING).
                $caller_num = 'Bilinmeyen';
                $call_id = '';
                if (is_array($chan_output)) {
                    foreach ($chan_output as $ch_line) {
                        $cols = explode('!', $ch_line);
                        if (isset($cols[0]) && strpos($cols[0], $chan) !== false) {
                            if (!empty($cols[7])) $caller_num = $cols[7];
                            if (!empty($cols[13])) $call_id = $cols[13];
                            break;
                        }
                    }
                }

                $waiting_calls[] = [
                    'channel' => $chan,
                    'call_id' => $call_id,
                    'queue' => $current_queue,
                    'queue_name' => $current_queue,
                    'queue_title' => $q_title_map[$current_queue] ?? $current_queue,
                    'caller' => $caller_num,
                    'caller_num' => $caller_num,
                    'wait_time' => $wait_time
                ];
            }
        }
    }

    // Parse Active Channels
    // NOT: "core show channels concise" alanları ':' değil '!' ile ayrılır
    // (bkz. Asterisk main/cli.c CONCISE_FORMAT_STRING). Alan sırası:
    // 0 Channel,1 Context,2 Exten,3 Priority,4 State,5 Application,6 Data,
    // 7 CallerIDnum,8 Accountcode,9 PeerAccount,10 Amaflags,11 Duration,12 BridgeID,13 Uniqueid
    if (is_array($chan_output)) {
        foreach ($chan_output as $line) {
            $cols = explode('!', $line);
            if (count($cols) >= 14) {
                $chan_name = $cols[0];
                $exten = $cols[2];
                $state = $cols[4];
                $caller_id = $cols[7];
                $duration = $cols[11];
                $call_id = $cols[13];

                if (strpos($chan_name, 'PJSIP/') !== false && !empty($caller_id)) {
                    $active_calls[] = [
                        'channel' => $chan_name,
                        'call_id' => $call_id,
                        'caller' => $caller_id,
                        'caller_num' => $caller_id,
                        'exten' => $exten,
                        'state' => $state,
                        'duration' => $duration
                    ];
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'calls' => $waiting_calls,
        'waiting_calls' => $waiting_calls,
        'active_calls' => $active_calls
    ]);
    exit;
}
