<?php
// Temsilcinin çağrı geçmişi: my_cdrs
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'my_cdrs') {
    $can_listen = ($user['role'] === 'admin' || !empty($user['can_listen_recordings']));
    $can_view_all = ($user['role'] === 'admin' || !empty($user['can_view_all_cdrs']));

    // Aktif çağrı sırasında girilen "bekleyen" notu (agent_extension bazlı, call_id IS NULL)
    // bu temsilcinin en son biten çağrısına (CDR'ına) bağla. Son 2 saat içinde girilmiş
    // bekleyen not aranır; daha eskisi (unutulmuş taslak) otomatik bağlanmaz.
    if (!empty($user_ext)) {
        // agent_extension artık kuyruk-yönlendirmesiz aramalarda ARANAN tarafı da
        // gösterebiliyor (bkz. cdrs view güncellemesi) — bu ajanın kendi başlattığı
        // çağrılar caller_num'da görünür, o yüzden ikisi de kontrol ediliyor.
        $stmt_last_cdr = $db->prepare('SELECT call_id FROM cdrs WHERE (agent_extension = ? OR caller_num = ?) AND call_id IS NOT NULL ORDER BY start_time DESC LIMIT 1');
        $stmt_last_cdr->execute([$user_ext, $user_ext]);
        $last_call_id = $stmt_last_cdr->fetchColumn();

        if ($last_call_id) {
            $stmt_claim = $db->prepare("UPDATE callcenter_notes SET call_id = ? WHERE agent_extension = ? AND call_id IS NULL AND created_at >= (NOW() - INTERVAL 2 HOUR) ORDER BY id DESC LIMIT 1");
            $stmt_claim->execute([$last_call_id, $user_ext]);
        }
    }

    if (empty($user_ext) || $user['role'] === 'admin') {
        $stmt = $db->prepare('SELECT id, call_id, start_time, caller_num, agent_extension, agent_name, duration, billsec, status, recording_path FROM cdrs ORDER BY start_time DESC LIMIT 30');
        $stmt->execute();
    } else {
        $stmt = $db->prepare('SELECT id, call_id, start_time, caller_num, agent_extension, agent_name, duration, billsec, status, recording_path FROM cdrs WHERE (agent_extension = ? OR caller_num = ?) ORDER BY start_time DESC LIMIT 30');
        $stmt->execute([$user_ext, $user_ext]);
    }
    $cdrs = $stmt->fetchAll();

    // Add audio playback URL and check file existence
    foreach ($cdrs as &$c) {
        $has_rec = (!empty($c['recording_path']) && file_exists($c['recording_path']));
        $c['has_recording'] = $has_rec;
        $c['can_listen'] = $can_listen || ($c['agent_extension'] === $user_ext) || ($c['caller_num'] === $user_ext);
        $c['audio_url'] = $has_rec ? '/api/cc_audio.php?id=' . $c['id'] : null;
    }
    unset($c);

    echo json_encode(['success' => true, 'cdrs' => $cdrs, 'can_listen_recordings' => $can_listen, 'can_view_all_cdrs' => $can_view_all]);
    exit;
}
