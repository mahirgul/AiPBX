<?php
// Arama aksiyonları: originate, hangup, transfer, hold, pickup_call
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'originate') {
    $to = preg_replace('/[^0-9+]/', '', $_POST['to'] ?? '');
    if (empty($to) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz hedef numara veya dahili']);
        exit;
    }

    // Dual-Endpoint: PJSIP/<ext> adında endpoint yoktur; Local kanal ile dialplan
    // uzerinden gidilir (from-internal-pbx → PJSIP_DIAL_CONTACTS → tum cihazlar)
    // Giden rota grubu: Kullanıcı 1'den farklı bir gruba atanmışsa ilgili context kullanılır
    $outbound_grp = max(1, intval($user['outbound_group'] ?? 1));
    $outbound_context = ($outbound_grp > 1) ? "from-internal-g{$outbound_grp}" : 'cc-internal';

    $cmd = "Action: Originate\r\n" .
           "Channel: Local/$user_ext@cc-internal\r\n" .
           "Context: $outbound_context\r\n" .
           "Exten: $to\r\n" .
           "Priority: 1\r\n" .
           "CallerID: Temsilci $user_ext <$user_ext>\r\n\r\n";

    $res = sendAMICommand($cmd);
    if (!$res || strpos($res, 'Response: Success') === false) {
        echo json_encode([
            'success' => false,
            'error' => "Arama başlatılamadı! Dahili numaranızın ($user_ext) santral kaydı (WebRTC/SIP) aktif / çevrimiçi değil."
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => "$user_ext dahilisinden $to aranıyor..."]);
    exit;
}

if ($action === 'hangup') {
    // Asıl kapatma tarayıcıda JsSIP session.terminate() ile zaten yapılır;
    // bu, WebSocket/sinyalleşme başarısız olursa devreye giren AMI yedeğidir.
    $channels = findAgentChannels($user_ext);
    if (empty($channels)) {
        // İstemci tarafı sonlandırma zaten başarılı olmuş olabilir; hata sayma.
        echo json_encode(['success' => true, 'message' => 'Aktif kanal bulunamadı (muhtemelen zaten kapatıldı)']);
        exit;
    }
    $ok = false;
    foreach ($channels as $ch) {
        $res = sendAMICommand("Action: Hangup\r\nChannel: $ch\r\n\r\n");
        if ($res && strpos($res, 'Response: Success') !== false) $ok = true;
    }
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Arama kapatıldı' : 'Kapatma komutu başarısız oldu']);
    exit;
}

if ($action === 'transfer') {
    $to = preg_replace('/[^0-9+]/', '', $_POST['to'] ?? '');
    if (empty($to) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz hedef numara']);
        exit;
    }
    // Asıl aktarma tarayıcıda JsSIP session.refer() ile zaten yapılır;
    // bu, sinyalleşme başarısız olursa devreye giren AMI yedeğidir.
    //
    // YÖNLENDİRİLECEK KANAL ARAYANINKİDİR, temsilcininki DEĞİL.
    // Eskiden findAgentChannels() ile bulunan TÜM temsilci kanalları
    // (PJSIP cihaz bacağı + Local çiftinin iki yarısı) tek tek Redirect
    // ediliyordu. Tek kanallı Redirect o kanalı köprüden çeker; arayan
    // ortada kalır, Queue() uygulamasından düşer ve `h` uzantısında
    // kapanır — 2026-09-15 canlı logunda temsilci 8915'e giderken aynı
    // saniyede arayan Hangup yedi.
    $caller_channel = findCallerChannelForAgent($user_ext);
    if (empty($caller_channel)) {
        echo json_encode(['success' => false, 'error' => 'Aktarılacak çağrı bulunamadı']);
        exit;
    }
    $res = sendAMICommand("Action: Redirect\r\nChannel: $caller_channel\r\nContext: cc-internal\r\nExten: $to\r\nPriority: 1\r\n\r\n");
    $ok = ($res && strpos($res, 'Response: Success') !== false);
    echo json_encode(['success' => $ok, 'message' => $ok ? "Çağrı $to numarasına aktarılıyor..." : 'Aktarma komutu başarısız oldu']);
    exit;
}

if ($action === 'hold') {
    // Gerçek hold tarayıcıda JsSIP session.hold() (re-INVITE, sendonly) ile
    // sağlanır — bu action sunucu tarafında ek bir işlem yapmaz (kanalı YANLIŞLIKLA
    // kapatan eski kod kaldırıldı). Sadece istemciye onay döner.
    echo json_encode(['success' => true, 'message' => 'Çağrı beklemeye alındı']);
    exit;
}

if ($action === 'pickup_call') {
    // Asterisk kanal adları sadece [A-Za-z0-9/_.@;-] karakterlerinden oluşur
    // (örn. PJSIP/3001-00000012, Local/3001@cc-internal-00000001;1) — bu
    // whitelist dışındaki her karakter (özellikle \r\n) AMI komut enjeksiyonunu
    // önlemek için burada süzülür (Action:/Channel: satırlarına ham gömülüyor).
    $target_channel = preg_replace('/[^A-Za-z0-9\/_.@;-]/', '', trim($_POST['channel'] ?? ''));
    $mode = trim($_POST['mode'] ?? 'webrtc');

    if (empty($target_channel) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz çağrı kanalı veya dahili']);
        exit;
    }

    // Kuyruk üyeliği kontrolü: sıradan bir cc_agent, ait olmadığı bir kuyruktaki
    // çağrıyı listede görüp channel adını tahmin/kopyalayarak alamasın diye,
    // hedef kanalın gerçekten hangi kuyrukta beklediği "queue show" çıktısından
    // bulunup çağıranın o kuyruğun üyesi olup olmadığı doğrulanır. Admin/cc_manager
    // (süpervizör rolleri) her kuyruktan çağrı alabildiği için bu kontrolden muaf.
    if (!in_array($user['role'] ?? '', ['admin', 'cc_manager'], true)) {
        @exec("asterisk -rx " . escapeshellarg("queue show"), $qs_output);
        $found_queue = null;
        $cur_q = '';
        if (is_array($qs_output)) {
            foreach ($qs_output as $qline) {
                $qclean = preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", "", $qline);
                if (preg_match('/^([a-zA-Z0-9_-]+)\s+has\s+\d+\s+calls/i', trim($qclean), $qm)) {
                    $cur_q = $qm[1];
                }
                if (strpos($qclean, $target_channel) !== false && $cur_q !== '') {
                    $found_queue = $cur_q;
                    break;
                }
            }
        }
        if ($found_queue !== null) {
            $mem_stmt = $db->prepare("SELECT members_json FROM pbx_queues WHERE queue_name = ? AND is_active = 1");
            $mem_stmt->execute([$found_queue]);
            $members = json_decode($mem_stmt->fetchColumn() ?: '[]', true) ?: [];
            if (!in_array((string)$user_ext, array_map('strval', $members), true)) {
                echo json_encode(['success' => false, 'error' => 'Bu çağrı üyesi olmadığınız bir kuyrukta bekliyor']);
                exit;
            }
        }
    }

    // Redirect waiting queue caller to agent's extension in cc-internal
    $res = sendAMICommand("Action: Redirect\r\nChannel: $target_channel\r\nContext: cc-internal\r\nExten: $user_ext\r\nPriority: 1\r\n\r\n");
    if (!$res || strpos($res, 'Response: Success') === false) {
        echo json_encode(['success' => false, 'error' => 'Çağrı başka bir temsilci tarafından zaten alınmış olabilir']);
        exit;
    }

    if ($mode === 'sip') {
        // Also trigger originate to desk phone if needed (Dual-Endpoint: Local kanal)
        $orig_cmd = "Action: Originate\r\n" .
                    "Channel: Local/$user_ext@cc-internal\r\n" .
                    "Context: cc-internal\r\n" .
                    "Exten: $user_ext\r\n" .
                    "Priority: 1\r\n" .
                    "CallerID: Pickup <$user_ext>\r\n\r\n";
        sendAMICommand($orig_cmd);
    }

    echo json_encode(['success' => true, 'message' => "Çağrı dahilinize ($user_ext) yönlendirildi"]);
    exit;
}
