<?php
require_once __DIR__ . '/../internal_numbers.php';
/**
 * Call Center Queue Service
 */

class QueueService {
    public static function saveQueue($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $queue_id = intval($data['queue_id'] ?? 0);
            $queue_name = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['queue_name'] ?? ''));
            $title = trim($data['title'] ?? $queue_name);
            $strategy = trim($data['strategy'] ?? 'rrmemory');
            $timeout = intval($data['timeout'] ?: 15);
            $retry = intval($data['retry'] ?: 5);
            $wrapup = intval($data['wrapuptime'] ?: 10);
            $musicclass = trim($data['musicclass'] ?? 'default');
            $maxlen = intval($data['maxlen'] ?? 0);
            $announce_frequency = intval($data['announce_frequency'] ?? 30);
            $announce_holdtime = trim($data['announce_holdtime'] ?? 'yes');
            $joinempty = trim($data['joinempty'] ?? 'yes');
            $leavewhenempty = trim($data['leavewhenempty'] ?? 'no');
            $ringinuse = trim($data['ringinuse'] ?? 'no');
            $max_wait_seconds = intval($data['max_wait_seconds'] ?: 300);
            $fallback_action = trim($data['fallback_action'] ?? 'hangup');
            $fallback_target = preg_replace('/[^0-9]/', '', trim($data['fallback_target'] ?? '')) ?: null;
            $record_enabled = isset($data['record_enabled']) ? 1 : 0;
            $record_format = trim($data['record_format'] ?? 'wav');
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
            $language = trim($data['language'] ?? '');
            if ($language !== '' && !in_array($language, getAvailableLanguages(), true)) {
                $language = '';
            }

            if ($fallback_action === 'forward' && empty($fallback_target)) {
                throw new \Exception("Zaman Aşımı Aksiyonu \"Dahiliye Yönlendir\" seçildiğinde bir Yönlendirme Dahilisi seçmelisiniz!");
            }
            $selected_members = $data['members'] ?? [];
            $selected_supervisors = $data['supervisors'] ?? [];
            if (!is_array($selected_supervisors)) $selected_supervisors = [];
            if (empty($selected_supervisors) && !empty($data['supervisor_extension'])) {
                $selected_supervisors = [$data['supervisor_extension']];
            }

            if (empty($queue_name)) throw new \Exception("Kuyruk sistem ismi zorunludur!");

            $internal_number = internalNumberSanitize($data['internal_number'] ?? '');
            $eski_numara = (string) (DBHelper::fetchColumn(
                "SELECT internal_number FROM pbx_queues WHERE id = ?", [$queue_id]
            ) ?? '');
            assertInternalNumberAvailable($internal_number, 'queue', $queue_id);


            $members_json = json_encode(array_values($selected_members));
            $supervisors_json = json_encode(array_values($selected_supervisors));
            $primary_supervisor = !empty($selected_supervisors) ? $selected_supervisors[0] : null;

            $q_data = [
                'id' => $queue_id,
                'queue_name' => $queue_name,
                'title' => $title,
                'language' => $language ?: null,
                'strategy' => $strategy,
                'timeout' => $timeout,
                'retry' => $retry,
                'wrapuptime' => $wrapup,
                'musicclass' => $musicclass,
                'maxlen' => $maxlen,
                'announce_frequency' => $announce_frequency,
                'announce_holdtime' => $announce_holdtime,
                'joinempty' => $joinempty,
                'leavewhenempty' => $leavewhenempty,
                'ringinuse' => $ringinuse,
                'max_wait_seconds' => $max_wait_seconds,
                'fallback_action' => $fallback_action,
                'fallback_target' => $fallback_target,
                'record_enabled' => $record_enabled,
                'record_format' => $record_format,
                'members_json' => $members_json,
                'supervisors_json' => $supervisors_json,
                'supervisor_extension' => $primary_supervisor,
                'internal_number' => $internal_number !== '' ? $internal_number : null,
                'is_active' => $is_active
            ];

            $is_new = ($queue_id <= 0);
            $id = DBHelper::save('pbx_queues', $q_data);
            QueueHelper::syncQueueToDetails($q_data);

            // Asterisk'e hemen yansıtılmıyor — "Uygula" sayfasından admin
            // Gönder'e basana kadar bekletiliyor (2026-08-24, ertelenmiş
            // reload sistemi). Gerçek regen+reload: applyPendingSync().
            markPendingSync('queues', 'queue', $queue_name, "Kuyruk: {$title} ({$queue_name})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            // Numara eklendi/degistirildi/silindiyse dahili hedef context'i de tazelenmeli.
            if ($internal_number !== $eski_numara) {
                markPendingSync('internal_numbers', 'queue', $id,
                    "Dahili hedef numarasi: " . ($internal_number !== '' ? $internal_number : 'kaldirildi'),
                    'update', $_SESSION['user_id'] ?? null);
            }

            return "Kuyruk '{$title}' ({$queue_name}) kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    /**
     * Bir kuyruk, ona işaret eden aktif bir Gelen Rota/IVR seçeneği/Zaman Koşulu
     * varken silinemez — dest_type/dest_id polimorfik alanı (pbx_dids vb.) DB
     * seviyesinde FK olamadığı için (2026-08-23 incelemesinde bulundu: sessizce
     * bozuk/geçersiz bir hedefe düşen DID riski) bu kontrol burada yapılıyor.
     * TimeConditionService::deleteTimeGroup()'taki mevcut desenle aynı yaklaşım.
     */
    public static function deleteQueue($queue_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($queue_id) {
            $queue_id = intval($queue_id);
            $q_row = DBHelper::fetchOne("SELECT queue_name, title FROM pbx_queues WHERE id = ?", [$queue_id]);
            $q_name = $q_row['queue_name'] ?? null;
            if (!empty($q_name)) {
                $refs = DBHelper::fetchColumn(
                    "SELECT COUNT(*) FROM (
                        SELECT id FROM pbx_dids WHERE dest_type = 'queue' AND dest_id = ?
                        UNION ALL
                        SELECT id FROM pbx_ivr_entries WHERE dest_type = 'queue' AND dest_id = ?
                        UNION ALL
                        SELECT id FROM pbx_ivrs WHERE (timeout_dest_type = 'queue' AND timeout_dest_id = ?) OR (invalid_dest_type = 'queue' AND invalid_dest_id = ?)
                        UNION ALL
                        SELECT id FROM pbx_time_conditions WHERE (match_dest_type = 'queue' AND match_dest_id = ?) OR (nomatch_dest_type = 'queue' AND nomatch_dest_id = ?) OR rules_json LIKE ?
                    ) refs",
                    [$q_name, $q_name, $q_name, $q_name, $q_name, $q_name, '%"match_dest_type":"queue","match_dest_id":"' . $q_name . '"%']
                );
                if ($refs > 0) {
                    throw new \Exception("Bu kuyruk bir Gelen Rota, IVR seçeneği veya Zaman Koşuluna bağlı olduğu için silinemez! Önce o bağlantıları kaldırın veya başka bir hedefe yönlendirin.");
                }
                QueueHelper::deleteDetails($q_name);
            }
            $silinen_numara = (string) (DBHelper::fetchColumn("SELECT internal_number FROM pbx_queues WHERE id = ?", [$queue_id]) ?? '');
            DBHelper::delete('pbx_queues', 'id', $queue_id);
            markPendingSync('queues', 'queue', $q_name ?: ('id_' . $queue_id), "Kuyruk: " . ($q_row['title'] ?? $q_name ?? $queue_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            if ($silinen_numara !== '') {
                markPendingSync('internal_numbers', 'queue', $queue_id,
                    "Dahili hedef numarasi silindi: {$silinen_numara}", 'delete',
                    $_SESSION['user_id'] ?? null);
            }

            return "Kuyruk kaydı silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }
}
