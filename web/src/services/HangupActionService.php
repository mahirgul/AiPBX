<?php
require_once __DIR__ . '/../internal_numbers.php';
/**
 * Hangup Action (Çağrı Sonlandırma) Service
 */

class HangupActionService {
    public static function saveHangupAction($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $action_id = intval($data['hangup_id'] ?? 0);
            $action_key = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['action_key'] ?? ''));
            $title = trim($data['title'] ?? '');
            $action_type = trim($data['action_type'] ?? 'hangup');
            $announcement_id = !empty($data['announcement_id']) ? intval($data['announcement_id']) : null;

            if (empty($action_key) || empty($title)) {
                throw new \Exception('Sonlandırma kimlik anahtarı (Action Key) ve başlık zorunludur!');
            }

            $internal_number = internalNumberSanitize($data['internal_number'] ?? '');
            $eski_numara = (string) (DBHelper::fetchColumn(
                "SELECT internal_number FROM pbx_hangup_actions WHERE id = ?", [$action_id]
            ) ?? '');
            assertInternalNumberAvailable($internal_number, 'hangup', $action_id);
            $numara_kolonu = $internal_number !== '' ? $internal_number : null;

            $db = getDB();
            $is_new = ($action_id <= 0);
            if ($action_id > 0) {
                $stmt = $db->prepare("UPDATE pbx_hangup_actions SET title = ?, action_type = ?, announcement_id = ?, internal_number = ? WHERE id = ?");
                $stmt->execute([$title, $action_type, $announcement_id, $numara_kolonu, $action_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_hangup_actions (action_key, title, action_type, announcement_id, internal_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$action_key, $title, $action_type, $announcement_id, $numara_kolonu]);
                $action_id = $db->lastInsertId();
            }
            // buildDestinationLines()'ın 'hangup' dalı bu tabloyu Gelen Rota/IVR/Zaman
            // Koşulu/Kuyruk-yedek üretirken okuyor — bu üç domain BİRDEN etkilendiği
            // için üçüne de ayrı işaret konur (2026-08-21 denetiminde bulunan bug'ın
            // aynısı ertelenmiş sistemde tekrarlanmasın diye).
            $uid = $_SESSION['user_id'] ?? null;
            $action = $is_new ? 'create' : 'update';
            markPendingSync('inbound_dialplan', 'hangup_action', $action_id, "Sonlandırma: {$title}", $action, $uid);
            markPendingSync('ivrs', 'hangup_action', $action_id, "Sonlandırma: {$title}", $action, $uid);
            markPendingSync('time_conditions', 'hangup_action', $action_id, "Sonlandırma: {$title}", $action, $uid);
            // Numara eklendi/degistirildi/silindiyse dahili hedef context'i de tazelenmeli.
            if ($internal_number !== $eski_numara) {
                markPendingSync('internal_numbers', 'hangup_action', $action_id,
                    "Dahili hedef numarasi: " . ($internal_number !== '' ? $internal_number : 'kaldirildi'),
                    'update', $uid);
            }
            return "Çağrı sonlandırma seçeneği '$title' kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function deleteHangupAction($hangup_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () use ($hangup_id) {
            $action_id = intval($hangup_id);
            if ($action_id > 0) {
                $db = getDB();
                $title = DBHelper::fetchColumn("SELECT title FROM pbx_hangup_actions WHERE id = ?", [$action_id]);
                $silinen_numara = (string) (DBHelper::fetchColumn("SELECT internal_number FROM pbx_hangup_actions WHERE id = ?", [$action_id]) ?? '');
                $stmt = $db->prepare("DELETE FROM pbx_hangup_actions WHERE id = ? AND action_key NOT IN ('hangup', 'busy', 'congestion')");
                $stmt->execute([$action_id]);
                $uid = $_SESSION['user_id'] ?? null;
                $label = "Sonlandırma: " . ($title ?: $action_id) . " (silindi)";
                markPendingSync('inbound_dialplan', 'hangup_action', $action_id, $label, 'delete', $uid);
                markPendingSync('ivrs', 'hangup_action', $action_id, $label, 'delete', $uid);
                markPendingSync('time_conditions', 'hangup_action', $action_id, $label, 'delete', $uid);
                if ($silinen_numara !== '') {
                    markPendingSync('internal_numbers', 'hangup_action', $action_id,
                        "Dahili hedef numarasi silindi: {$silinen_numara}", 'delete', $uid);
                }
                return "Sonlandırma seçeneği silindi! Etkili olması için Uygula sayfasından gönderin.";
            }
            return '';
        });
    }
}
