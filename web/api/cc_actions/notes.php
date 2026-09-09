<?php
// Çağrı notları: get_call_note, get_pending_note, save_call_note (callcenter_notes tablosu)
//
// Çağrı devam ederken (henüz bir CDR/call_id oluşmadan) temsilci not girebilsin diye
// call_id boşken not, temsilcinin kendi dahilisine (agent_extension) bağlı "bekleyen" (call_id IS NULL)
// bir satır olarak tutulur. Çağrı bitip CDR'a düşünce cdrs.php (my_cdrs) bu bekleyen notu
// otomatik olarak en son oluşan CDR'ın call_id'sine bağlar (claimPendingNote()).
// Asterisk kanal uniqueid'i doğrudan kullanılmaz çünkü kuyruk üzerinden gelen çağrılarda
// temsilcinin kendi kanal uniqueid'i, CDR'a yazılan (arayan tarafa ait) uniqueid ile eşleşmez.
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'get_call_note') {
    $call_id = trim($_GET['call_id'] ?? '');
    if ($call_id === '') {
        echo json_encode(['success' => false, 'error' => 'call_id zorunludur']);
        exit;
    }

    // Sahiplik kontrolü: cdrs.php'deki aynı desen (admin / can_view_all_cdrs
    // dışında herkes sadece kendi çağrısının notunu görebilir) — aksi halde
    // call_id tahmin edilerek başka bir temsilcinin müşteri/not verisi okunabilirdi.
    $can_view_all = ($user['role'] === 'admin' || !empty($user['can_view_all_cdrs']));
    if ($can_view_all) {
        $stmt = $db->prepare('SELECT id, call_id, customer_name, phone, disposition, notes, created_at FROM callcenter_notes WHERE call_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$call_id]);
    } else {
        $stmt = $db->prepare('SELECT id, call_id, customer_name, phone, disposition, notes, created_at FROM callcenter_notes WHERE call_id = ? AND agent_extension = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$call_id, $user_ext]);
    }
    $note = $stmt->fetch();

    echo json_encode(['success' => true, 'note' => $note ?: null]);
    exit;
}

if ($action === 'get_pending_note') {
    // Aktif çağrı sırasında modal tekrar açıldığında (ör. sayfa yenileme) daha önce
    // girilmiş bekleyen notu geri getirir. Temsilci kimliği her zaman sunucu tarafından
    // (oturumdaki $user_ext) belirlenir, client'tan gelen bir dahili numarasına güvenilmez.
    if ($user_ext === '') {
        echo json_encode(['success' => false, 'error' => 'Dahili bulunamadı']);
        exit;
    }

    $stmt = $db->prepare('SELECT id, customer_name, phone, disposition, notes, created_at FROM callcenter_notes WHERE agent_extension = ? AND call_id IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$user_ext]);
    $note = $stmt->fetch();

    echo json_encode(['success' => true, 'note' => $note ?: null]);
    exit;
}

if ($action === 'save_call_note') {
    $call_id = trim($_POST['call_id'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $disposition = trim($_POST['disposition'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($call_id !== '') {
        // Biten bir çağrıya (CDR listesinden) ait not: call_id ile upsert.
        // Sahiplik kontrolü: admin/can_view_all_cdrs dışında biri sadece KENDİ
        // yazdığı notu güncelleyebilir — aksi halde call_id tahmin edilerek başka
        // bir temsilcinin notunun üzerine yazılabilirdi. Kendi notu yoksa (ör. bu
        // çağrıyı ilk kez notluyor) normal şekilde yeni bir satır eklenir.
        $can_edit_all = ($user['role'] === 'admin' || !empty($user['can_view_all_cdrs']));
        if ($can_edit_all) {
            $stmt = $db->prepare('SELECT id FROM callcenter_notes WHERE call_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$call_id]);
        } else {
            $stmt = $db->prepare('SELECT id FROM callcenter_notes WHERE call_id = ? AND agent_extension = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$call_id, $user_ext]);
        }
        $existing_id = $stmt->fetchColumn();

        if ($existing_id) {
            $stmt = $db->prepare('UPDATE callcenter_notes SET customer_name = ?, phone = ?, disposition = ?, notes = ? WHERE id = ?');
            $stmt->execute([$customer_name, $phone, $disposition, $notes, $existing_id]);
        } else {
            $stmt = $db->prepare('INSERT INTO callcenter_notes (call_id, agent_extension, customer_name, phone, disposition, notes) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$call_id, ($user_ext !== '' ? $user_ext : null), $customer_name, $phone, $disposition, $notes]);
        }
    } else {
        // Aktif çağrı: henüz call_id yok, temsilciye bağlı "bekleyen" not olarak sakla
        if ($user_ext === '') {
            echo json_encode(['success' => false, 'error' => 'Dahili bulunamadı, aktif çağrı notu kaydedilemedi']);
            exit;
        }

        $stmt = $db->prepare('SELECT id FROM callcenter_notes WHERE agent_extension = ? AND call_id IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user_ext]);
        $existing_id = $stmt->fetchColumn();

        if ($existing_id) {
            $stmt = $db->prepare('UPDATE callcenter_notes SET customer_name = ?, phone = ?, disposition = ?, notes = ? WHERE id = ?');
            $stmt->execute([$customer_name, $phone, $disposition, $notes, $existing_id]);
        } else {
            $stmt = $db->prepare('INSERT INTO callcenter_notes (call_id, agent_extension, customer_name, phone, disposition, notes) VALUES (NULL, ?, ?, ?, ?, ?)');
            $stmt->execute([$user_ext, $customer_name, $phone, $disposition, $notes]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Çağrı notu kaydedildi']);
    exit;
}
