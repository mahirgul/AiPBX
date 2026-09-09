<?php
/**
 * Feature Code (Star Code) Service
 */

class FeatureCodeService {
    /**
     * Bu sayfa yalnızca DÜZENLEME destekler (yeni feature code eklenemez —
     * davranışın kendisi sabittir, sadece kod/başlık/roller değiştirilebilir).
     * Önceden feature_codes.php'nin içine gömülüydü; MVC göçü sırasında
     * (2026-08-22) buraya taşındı, mantık DEĞİŞTİRİLMEDİ.
     */
    public static function saveFeatureCode($data, array $valid_role_keys) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data, $valid_role_keys) {
            $id = intval($data['feature_id'] ?? 0);
            $title = trim($data['title'] ?? '');
            $code = trim($data['code'] ?? '');
            $roles_in = array_filter(array_map('trim', explode(',', $data['allowed_roles'] ?? '')));
            $roles_in = array_values(array_intersect($roles_in, $valid_role_keys));
            $allowed_roles = !empty($roles_in) ? implode(',', $roles_in) : null;
            $is_active = isset($data['is_active']) ? 1 : 0;

            // Normal kodlar '*' ile başlar (ör. *78). Değişken parametreli kodlar (ör. kuyruk
            // ID'si tuşlanan *81/*80) Asterisk desen söz dizimiyle '_*' ile başlar (ör. _*81.).
            $starts_ok = ($code !== '') && (($code[0] === '*') || (strpos($code, '_*') === 0));
            if (empty($title) || !$starts_ok) {
                throw new \Exception('Başlık zorunludur ve kod "*" (veya desen kodlarında "_*") ile başlamalıdır!');
            }

            $db = getDB();
            $dup = $db->prepare("SELECT id FROM pbx_feature_codes WHERE code = ? AND id != ?");
            $dup->execute([$code, $id]);
            if ($dup->fetchColumn()) {
                throw new \Exception("Kod '{$code}' başka bir özellik tarafından kullanılıyor!");
            }

            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_feature_codes SET title = ?, code = ?, allowed_roles = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$title, $code, $allowed_roles, $is_active, $id]);
                markPendingSync('featurecodes', 'feature_code', $id, "Özellik Kodu: {$title} ({$code})", 'update', $_SESSION['user_id'] ?? null);
                return "'{$title}' feature code'u güncellendi! Etkili olması için Uygula sayfasından gönderin.";
            }
            return '';
        });
    }

    public static function toggleStatus($feature_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () use ($feature_id) {
            $id = intval($feature_id);
            if ($id > 0) {
                $title = DBHelper::fetchColumn("SELECT title FROM pbx_feature_codes WHERE id = ?", [$id]);
                getDB()->prepare("UPDATE pbx_feature_codes SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
                markPendingSync('featurecodes', 'feature_code', $id, "Özellik Kodu: " . ($title ?: $id) . " (durum değişti)", 'update', $_SESSION['user_id'] ?? null);
                return 'Durum güncellendi! Etkili olması için Uygula sayfasından gönderin.';
            }
            return '';
        });
    }
}
