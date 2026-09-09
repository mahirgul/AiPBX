<?php
require_once __DIR__ . '/../internal_numbers.php';
/**
 * Time Condition Service
 */

class TimeConditionService {
    public static function saveTimeCondition($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $tc_id = intval($data['tc_id'] ?? 0);
            $title = trim($data['title'] ?? '');
            $rules_post = $data['rules'] ?? [];
            $rules_list = [];

            if (is_array($rules_post)) {
                foreach ($rules_post as $rp) {
                    $tgId = intval($rp['time_group_id'] ?? 0);
                    $mTypeRaw = trim($rp['match_dest_type'] ?? '');
                    $mId = trim($rp['match_dest_id'] ?? '');
                    $nmType = sanitizeDestType($rp['nomatch_dest_type'] ?? '');
                    $nmId = trim($rp['nomatch_dest_id'] ?? '');

                    if ($tgId > 0 && !empty($mTypeRaw)) {
                        $rules_list[] = [
                            'time_group_id' => $tgId,
                            'match_dest_type' => sanitizeDestType($mTypeRaw),
                            'match_dest_id' => $mId,
                            'nomatch_dest_type' => $nmType,
                            'nomatch_dest_id' => $nmId
                        ];
                    }
                }
            }

            $rules_json = !empty($rules_list) ? json_encode($rules_list, JSON_UNESCAPED_UNICODE) : null;
            $first_rule = $rules_list[0] ?? [];
            $time_group_id = intval($first_rule['time_group_id'] ?? ($data['time_group_id'] ?? 1));
            $match_dest_type = sanitizeDestType($first_rule['match_dest_type'] ?? ($data['match_dest_type'] ?? 'queue'));
            $match_dest_id = $first_rule['match_dest_id'] ?? ($data['match_dest_id'] ?? '');
            $nomatch_dest_type = sanitizeDestType($data['nomatch_dest_type'] ?? 'announcement');
            $nomatch_dest_id = trim($data['nomatch_dest_id'] ?? '');
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($title)) throw new \Exception("Zaman koşulu başlığı zorunludur!");

            $internal_number = internalNumberSanitize($data['internal_number'] ?? '');
            $eski_numara = (string) (DBHelper::fetchColumn(
                "SELECT internal_number FROM pbx_time_conditions WHERE id = ?", [$tc_id]
            ) ?? '');
            assertInternalNumberAvailable($internal_number, 'time_condition', $tc_id);


            $is_new = ($tc_id <= 0);
            $id = DBHelper::save('pbx_time_conditions', [
                'id' => $tc_id,
                'title' => $title,
                'time_group_id' => $time_group_id,
                'match_dest_type' => $match_dest_type,
                'match_dest_id' => $match_dest_id,
                'nomatch_dest_type' => $nomatch_dest_type,
                'nomatch_dest_id' => $nomatch_dest_id,
                'rules_json' => $rules_json,
                'internal_number' => $internal_number !== '' ? $internal_number : null,
                'is_active' => $is_active
            ]);

            markPendingSync('time_conditions', 'time_condition', $id, "Zaman Koşulu: {$title}", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            // Numara eklendi/degistirildi/silindiyse dahili hedef context'i de tazelenmeli.
            if ($internal_number !== $eski_numara) {
                markPendingSync('internal_numbers', 'time_condition', $id,
                    "Dahili hedef numarasi: " . ($internal_number !== '' ? $internal_number : 'kaldirildi'),
                    'update', $_SESSION['user_id'] ?? null);
            }

            return "Zaman Koşulu '{$title}' kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function deleteTimeCondition($tc_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($tc_id) {
            $tc_id = intval($tc_id);
            $tc_title = DBHelper::fetchColumn("SELECT title FROM pbx_time_conditions WHERE id = ?", [$tc_id]);
            $silinen_numara = (string) (DBHelper::fetchColumn("SELECT internal_number FROM pbx_time_conditions WHERE id = ?", [$tc_id]) ?? '');
            DBHelper::delete('pbx_time_conditions', 'id', $tc_id);
            markPendingSync('time_conditions', 'time_condition', $tc_id, "Zaman Koşulu: " . ($tc_title ?: $tc_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            if ($silinen_numara !== '') {
                markPendingSync('internal_numbers', 'time_condition', $tc_id,
                    "Dahili hedef numarasi silindi: {$silinen_numara}", 'delete',
                    $_SESSION['user_id'] ?? null);
            }

            return "Zaman Koşulu silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function saveTimeGroup($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $tg_id = intval($data['tg_id'] ?? 0);
            $title = trim($data['title'] ?? '');
            $time_start = trim($data['time_start'] ?? '08:30');
            $time_end = trim($data['time_end'] ?? '17:30');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time_start)) $time_start = '08:30';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time_end)) $time_end = '17:30';
            $days = isset($data['days']) && is_array($data['days']) ? implode(',', $data['days']) : (trim($data['days_of_week'] ?? '1,2,3,4,5'));
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            $holidays_raw = trim($data['holidays'] ?? '');
            $holidays_arr = array_filter(array_map('trim', explode(',', $holidays_raw)));
            $holidays_json = !empty($holidays_arr) ? json_encode(array_values($holidays_arr)) : null;

            if (strlen($time_start) == 5) $time_start .= ':00';
            if (strlen($time_end) == 5) $time_end .= ':00';

            if (empty($title)) throw new \Exception("Zaman grubu başlığı zorunludur!");

            $db = getDB();
            if ($tg_id > 0) {
                $stmt = $db->prepare("UPDATE pbx_time_groups SET title = ?, time_start = ?, time_end = ?, days_of_week = ?, holidays_json = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$title, $time_start, $time_end, $days, $holidays_json, $is_active, $tg_id]);
                $msg = "Zaman Grubu '{$title}' güncellendi!";
                $group_id = $tg_id;
                $group_action = 'update';
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_time_groups (title, time_start, time_end, days_of_week, holidays_json, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $time_start, $time_end, $days, $holidays_json, $is_active]);
                $msg = "Yeni Zaman Grubu '{$title}' eklendi!";
                $group_id = $db->lastInsertId();
                $group_action = 'create';
            }

            // Bir zaman grubunun saat/gün aralığı değişmesi, onu kullanan TÜM
            // zaman koşullarının ürettiği dialplan'ı etkiler — domain bazında
            // "time_conditions" tek regen'i tetikliyor (hangi TC'ler bu grubu
            // kullanıyor bilmeye gerek yok, syncAllTimeConditions() zaten hepsini
            // yeniden üretiyor).
            markPendingSync('time_conditions', 'time_group', $group_id, "Zaman Grubu: {$title}", $group_action, $_SESSION['user_id'] ?? null);
            $msg .= " Etkili olması için Uygula sayfasından gönderin.";
            return $msg;
        });
    }

    /**
     * Bir Zaman Grubu, onu kullanan aktif bir Zaman Koşulu varken silinemez —
     * hem eski tek-kural (time_group_id kolonu) hem yeni çoklu-kural
     * (rules_json içindeki referanslar) şemasını kontrol eder. Bu kontrol
     * önceden src/time_conditions.php'nin içine gömülüydü (Service katmanı
     * dışında tek istisnaydı); MVC göçü sırasında (2026-08-22) buraya taşındı,
     * mantık DEĞİŞTİRİLMEDİ.
     */
    public static function deleteTimeGroup($tg_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($tg_id) {
            $tg_id = intval($tg_id);
            if ($tg_id <= 0) {
                throw new \Exception("Geçersiz Zaman Grubu ID!");
            }
            $db = getDB();
            $check = $db->prepare("SELECT COUNT(*) FROM pbx_time_conditions WHERE time_group_id = ? OR rules_json LIKE ?");
            $check->execute([$tg_id, '%"time_group_id":' . $tg_id . '%']);
            if ($check->fetchColumn() > 0) {
                throw new \Exception("Bu Zaman Grubu aktif bir Zaman Koşuluna bağlı olduğu için silinemez!");
            }
            $stmt = $db->prepare("DELETE FROM pbx_time_groups WHERE id = ?");
            $stmt->execute([$tg_id]);
            return "Zaman Grubu silindi!";
        });
    }
}
