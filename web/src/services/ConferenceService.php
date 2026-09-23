<?php
/**
 * Conference Rooms (Konferans Odaları) Service
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/internal_numbers.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

class ConferenceService {
    public static function getConferences() {
        $db = getDB();
        $stmt = $db->query("SELECT * FROM pbx_conferences ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getConference($id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM pbx_conferences WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function saveConference($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $id = intval($data['id'] ?? 0);
            $room_number = internalNumberSanitize($data['room_number'] ?? '');
            $title = trim($data['title'] ?? '');
            $user_pin = preg_replace('/[^0-9]/', '', trim($data['user_pin'] ?? ''));
            $admin_pin = preg_replace('/[^0-9]/', '', trim($data['admin_pin'] ?? ''));
            $wait_marked = isset($data['wait_marked']) ? intval($data['wait_marked']) : 0;
            $end_marked = isset($data['end_marked']) ? intval($data['end_marked']) : 0;
            $max_members = max(2, min(500, intval($data['max_members'] ?? 50)));
            $announce_join_leave = isset($data['announce_join_leave']) ? intval($data['announce_join_leave']) : 1;
            $announce_user_count = isset($data['announce_user_count']) ? intval($data['announce_user_count']) : 1;
            $record_conference = isset($data['record_conference']) ? intval($data['record_conference']) : 0;
            $mute_on_join = isset($data['mute_on_join']) ? intval($data['mute_on_join']) : 0;
            $music_on_hold = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['music_on_hold'] ?? 'default')) ?: 'default';
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($room_number) || empty($title)) {
                throw new \Exception("Oda Dahili Numarası ve Başlık zorunludur!");
            }

            // Çakışma kontrolü
            internalNumberValidate($room_number, 'conference', $id);

            $db = getDB();
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_conferences SET room_number = ?, internal_number = ?, title = ?, user_pin = ?, admin_pin = ?, wait_marked = ?, end_marked = ?, max_members = ?, announce_join_leave = ?, announce_user_count = ?, record_conference = ?, mute_on_join = ?, music_on_hold = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$room_number, $room_number, $title, $user_pin, $admin_pin, $wait_marked, $end_marked, $max_members, $announce_join_leave, $announce_user_count, $record_conference, $mute_on_join, $music_on_hold, $is_active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_conferences (room_number, internal_number, title, user_pin, admin_pin, wait_marked, end_marked, max_members, announce_join_leave, announce_user_count, record_conference, mute_on_join, music_on_hold, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$room_number, $room_number, $title, $user_pin, $admin_pin, $wait_marked, $end_marked, $max_members, $announce_join_leave, $announce_user_count, $record_conference, $mute_on_join, $music_on_hold, $is_active]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('conferences', 'conference', $id, "{$title} ({$room_number})", $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            markPendingSync('internal_numbers', 'conference', $id, "{$title} ({$room_number})", $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            return "Konferans odası başarıyla kaydedildi.";
        });
    }

    public static function deleteConference($id, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($id) {
            $id = intval($id);
            $db = getDB();

            // Referans kontrolü
            $refs = [];
            $dids = $db->prepare("SELECT did_number FROM pbx_dids WHERE dest_type = 'conference' AND dest_id = ?");
            $dids->execute([$id]);
            if ($d = $dids->fetchAll(PDO::FETCH_COLUMN)) {
                $refs[] = "Gelen Rotalar (" . implode(', ', $d) . ")";
            }

            if (!empty($refs)) {
                throw new \Exception("Bu konferans odası şu modüllerde hedef olarak kullanıldığı için silinemez: " . implode(', ', $refs));
            }

            $cf = self::getConference($id);
            $label = $cf ? "{$cf['title']} ({$cf['room_number']})" : "Oda #{$id}";

            $del = $db->prepare("DELETE FROM pbx_conferences WHERE id = ?");
            $del->execute([$id]);

            markPendingSync('conferences', 'conference', $id, $label, 'delete', $_SESSION['user_id'] ?? null);
            markPendingSync('internal_numbers', 'conference', $id, $label, 'delete', $_SESSION['user_id'] ?? null);
            return "Konferans odası silindi.";
        });
    }

    public static function getLiveMembers($roomNumber) {
        $room = preg_replace('/[^0-9]/', '', (string)$roomNumber);
        if ($room === '') return [];

        @exec("asterisk -rx " . escapeshellarg("confbridge list {$room}"), $rawOutput);
        $members = [];
        foreach ($rawOutput ?: [] as $line) {
            $line = trim($line);
            // Format: Channel Flags User Profile Bridge Profile Menu CallerID
            if (preg_match('/^(PJSIP\/\S+|Local\/\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+)$/i', $line, $m)) {
                $members[] = [
                    'channel' => $m[1],
                    'flags' => $m[2],
                    'is_admin' => (stripos($m[2], 'A') !== false),
                    'is_muted' => (stripos($m[2], 'm') !== false),
                    'callerid' => trim($m[6]),
                ];
            }
        }
        return $members;
    }

    public static function kickMember($roomNumber, $channel, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($roomNumber, $channel) {
            $room = preg_replace('/[^0-9]/', '', (string)$roomNumber);
            $chan = preg_replace('/[^a-zA-Z0-9\/@_.-]/', '', (string)$channel);
            if ($room && $chan) {
                @exec("asterisk -rx " . escapeshellarg("confbridge kick {$room} {$chan}"));
                return "Katılımcı odadan çıkarıldı.";
            }
            throw new \Exception("Geçersiz oda veya kanal");
        });
    }

    public static function muteMember($roomNumber, $channel, $mute = true, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($roomNumber, $channel, $mute) {
            $room = preg_replace('/[^0-9]/', '', (string)$roomNumber);
            $chan = preg_replace('/[^a-zA-Z0-9\/@_.-]/', '', (string)$channel);
            if ($room && $chan) {
                $cmd = $mute ? "confbridge mute {$room} {$chan}" : "confbridge unmute {$room} {$chan}";
                @exec("asterisk -rx " . escapeshellarg($cmd));
                return $mute ? "Katılımcı sessize alındı." : "Katılımcının sesi açıldı.";
            }
            throw new \Exception("Geçersiz oda veya kanal");
        });
    }
}
