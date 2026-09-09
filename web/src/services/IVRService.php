<?php
require_once __DIR__ . '/../internal_numbers.php';
/**
 * IVR Menu & Entry Service
 */

class IVRService {
    public static function saveIVR($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $ivr_id = intval($data['ivr_id'] ?? 0);
            $title = trim($data['title'] ?? '');
            $prompt_file = trim($data['prompt_file'] ?? 'custom/welcome');
            $timeout_seconds = intval($data['timeout_seconds'] ?? 10);
            $timeout_dest_type = sanitizeDestType($data['timeout_dest_type'] ?? 'queue');
            $timeout_dest_id = trim($data['timeout_dest_id'] ?? '');
            $invalid_dest_type = sanitizeDestType($data['invalid_dest_type'] ?? 'hangup');
            $invalid_dest_id = trim($data['invalid_dest_id'] ?? '');
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
            $language = trim($data['language'] ?? '');
            if ($language !== '' && !in_array($language, getAvailableLanguages(), true)) {
                $language = '';
            }
            // 1-10 aralığına sıkıştırılıyor: 0/negatif menüyü hiç tekrarlatmaz
            // (ilk yanlış tuşta düşer), aşırı büyük değer ise arayanı sonsuza
            // yakın döngüde tutabilir.
            $max_failures = max(1, min(10, intval($data['max_failures'] ?? 3)));
            $allow_direct_dial = isset($data['allow_direct_dial']) ? intval($data['allow_direct_dial']) : 0;

            if (empty($title)) throw new \Exception("IVR başlığı zorunludur!");

            $internal_number = internalNumberSanitize($data['internal_number'] ?? '');
            $eski_numara = (string) (DBHelper::fetchColumn(
                "SELECT internal_number FROM pbx_ivrs WHERE id = ?", [$ivr_id]
            ) ?? '');
            assertInternalNumberAvailable($internal_number, 'ivr', $ivr_id);


            $is_new = ($ivr_id <= 0);
            $id = DBHelper::save('pbx_ivrs', [
                'id' => $ivr_id,
                'title' => $title,
                'prompt_file' => $prompt_file,
                'language' => $language ?: null,
                'timeout_seconds' => $timeout_seconds,
                'max_failures' => $max_failures,
                'allow_direct_dial' => $allow_direct_dial,
                'timeout_dest_type' => $timeout_dest_type,
                'timeout_dest_id' => $timeout_dest_id,
                'invalid_dest_type' => $invalid_dest_type,
                'invalid_dest_id' => $invalid_dest_id,
                'internal_number' => $internal_number !== '' ? $internal_number : null,
                'is_active' => $is_active
            ]);

            markPendingSync('ivrs', 'ivr', $id, "IVR: {$title}", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            // Numara eklendi/degistirildi/silindiyse dahili hedef context'i de tazelenmeli.
            if ($internal_number !== $eski_numara) {
                markPendingSync('internal_numbers', 'ivr', $id,
                    "Dahili hedef numarasi: " . ($internal_number !== '' ? $internal_number : 'kaldirildi'),
                    'update', $_SESSION['user_id'] ?? null);
            }

            return "IVR Menüsü '{$title}' kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    /**
     * Bir IVR, ona işaret eden aktif bir Gelen Rota/başka bir IVR seçeneği veya
     * zaman aşımı/geçersiz-giriş hedefi/Zaman Koşulu varken silinemez — bkz.
     * QueueService::deleteQueue()'daki aynı kontrol ve gerekçe (dest_type/dest_id
     * polimorfik alanı FK olamıyor, 2026-08-23 incelemesi). Bu IVR'ın KENDİ
     * pbx_ivr_entries/timeout/invalid self-loop'ları (örn. "geçersiz giriş →
     * aynı menüyü tekrarla") hariç tutulur — onlar ivr_id FK'sinin ON DELETE
     * CASCADE'i ile zaten otomatik silinir, bu kontrolün engellemesi gereken
     * bir şey değil.
     */
    public static function deleteIVR($ivr_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($ivr_id) {
            $ivr_id = intval($ivr_id);
            $ivr_title = DBHelper::fetchColumn("SELECT title FROM pbx_ivrs WHERE id = ?", [$ivr_id]);
            $refs = DBHelper::fetchColumn(
                "SELECT COUNT(*) FROM (
                    SELECT id FROM pbx_dids WHERE dest_type = 'ivr' AND dest_id = ?
                    UNION ALL
                    SELECT id FROM pbx_ivr_entries WHERE dest_type = 'ivr' AND dest_id = ? AND ivr_id != ?
                    UNION ALL
                    SELECT id FROM pbx_ivrs WHERE id != ? AND ((timeout_dest_type = 'ivr' AND timeout_dest_id = ?) OR (invalid_dest_type = 'ivr' AND invalid_dest_id = ?))
                    UNION ALL
                    SELECT id FROM pbx_time_conditions WHERE (match_dest_type = 'ivr' AND match_dest_id = ?) OR (nomatch_dest_type = 'ivr' AND nomatch_dest_id = ?) OR rules_json LIKE ?
                ) refs",
                [(string)$ivr_id, (string)$ivr_id, $ivr_id, $ivr_id, (string)$ivr_id, (string)$ivr_id, (string)$ivr_id, (string)$ivr_id, '%"match_dest_type":"ivr","match_dest_id":"' . $ivr_id . '"%']
            );
            if ($refs > 0) {
                throw new \Exception("Bu IVR bir Gelen Rota, başka bir IVR seçeneği veya Zaman Koşuluna bağlı olduğu için silinemez! Önce o bağlantıları kaldırın veya başka bir hedefe yönlendirin.");
            }
            $silinen_numara = (string) (DBHelper::fetchColumn("SELECT internal_number FROM pbx_ivrs WHERE id = ?", [$ivr_id]) ?? '');
            DBHelper::delete('pbx_ivrs', 'id', $ivr_id);
            markPendingSync('ivrs', 'ivr', $ivr_id, "IVR: " . ($ivr_title ?: $ivr_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            if ($silinen_numara !== '') {
                markPendingSync('internal_numbers', 'ivr', $ivr_id,
                    "Dahili hedef numarasi silindi: {$silinen_numara}", 'delete',
                    $_SESSION['user_id'] ?? null);
            }

            return "IVR Menüsü silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function saveIVREntry($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $ivr_id = intval($data['ivr_id'] ?? 0);
            $digit = preg_replace('/[^0-9*#]/', '', trim($data['digit'] ?? '1'));
            $dest_type = sanitizeDestType($data['dest_type'] ?? 'queue');
            $dest_id = trim($data['dest_id'] ?? '');

            if ($ivr_id <= 0 || $digit === '') throw new \Exception("Geçersiz IVR seçeneği!");

            $db = getDB();
            $stmt = $db->prepare("INSERT INTO pbx_ivr_entries (ivr_id, digit, dest_type, dest_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE dest_type = VALUES(dest_type), dest_id = VALUES(dest_id)");
            $stmt->execute([$ivr_id, $digit, $dest_type, $dest_id]);

            $ivr_title = DBHelper::fetchColumn("SELECT title FROM pbx_ivrs WHERE id = ?", [$ivr_id]);
            markPendingSync('ivrs', 'ivr', $ivr_id, "IVR: " . ($ivr_title ?: $ivr_id) . " (tuşlama: {$digit})", 'update', $_SESSION['user_id'] ?? null);
            return "IVR Tuşlama Haritası (Tuş: {$digit}) güncellendi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function deleteIVREntry($entry_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($entry_id) {
            $entry_id = intval($entry_id);
            $ivr_id = DBHelper::fetchColumn("SELECT ivr_id FROM pbx_ivr_entries WHERE id = ?", [$entry_id]);
            DBHelper::delete('pbx_ivr_entries', 'id', $entry_id);
            if ($ivr_id) {
                $ivr_title = DBHelper::fetchColumn("SELECT title FROM pbx_ivrs WHERE id = ?", [$ivr_id]);
                markPendingSync('ivrs', 'ivr', $ivr_id, "IVR: " . ($ivr_title ?: $ivr_id) . " (bir tuşlama seçeneği silindi)", 'update', $_SESSION['user_id'] ?? null);
            }
            return "IVR Tuşlama seçeneği silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }
}
