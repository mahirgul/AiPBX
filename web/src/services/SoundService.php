<?php
require_once __DIR__ . '/../internal_numbers.php';
/**
 * Announcements & MOH Sound Service
 */

class SoundService {
    /**
     * Yüklenen ses dosyasını (hangi formatta/örnekleme hızında/kanalda olursa
     * olsun) Asterisk'in beklediği 8kHz, 16-bit, mono PCM WAV'a çevirir.
     * Öncesinde dönüştürme YAPILMIYORDU — sadece dosya olduğu gibi .wav
     * uzantısıyla kaydediliyordu, bu yüzden stereo/44.1kHz/µ-law gibi
     * uyumsuz dosyalar Asterisk'te çalmıyor veya bozuk/yanlış hızda çalıyordu.
     */
    public static function convertToAsteriskWav($srcPath, $destPath) {
        $cmd = 'sox ' . escapeshellarg($srcPath) . ' -t wav -r 8000 -c 1 -b 16 ' . escapeshellarg($destPath) . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret !== 0 || !file_exists($destPath) || filesize($destPath) === 0) {
            throw new \Exception('Ses dosyası Asterisk uyumlu formata dönüştürülemedi: ' . implode(' ', $out));
        }
    }

    public static function uploadAnnouncement($data, $files) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data, $files) {
            $sound_name = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['sound_name'] ?? ''));
            $custom_dir = SOUNDS_CUSTOM_DIR;

            if (!isset($files['audio_file']) || $files['audio_file']['error'] !== UPLOAD_ERR_OK || empty($sound_name)) {
                throw new \Exception("Lütfen geçerli bir ses dosyası ve isim belirtin!");
            }

            $tmp_path = $files['audio_file']['tmp_name'];
            $target_file = $custom_dir . '/' . $sound_name . '.wav';
            $raw_upload = $custom_dir . '/.raw_' . uniqid() . '_' . $sound_name;

            if (move_uploaded_file($tmp_path, $raw_upload)) {
                try {
                    self::convertToAsteriskWav($raw_upload, $target_file);
                } finally {
                    @unlink($raw_upload);
                }
                @chown($target_file, 'asterisk');
                @chgrp($target_file, 'asterisk');
                @chmod($target_file, 0664);

                $title = trim($data['title'] ?? $sound_name);
                $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
                $db = getDB();
                $stmt = $db->prepare("INSERT INTO pbx_announcements (title, audio_file, is_active) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), is_active = VALUES(is_active)");
                $stmt->execute([$title, 'custom/' . $sound_name, $is_active]);

                // buildDestinationLines()'ın 'announcement' dalı ve pbx_hangup_actions
                // bu tabloyu Gelen Rota/IVR/Zaman Koşulu üretirken okuyor — bir anons
                // üç domain'i BİRDEN etkileyebildiği için üçüne de ayrı işaret konur.
                $uid = $_SESSION['user_id'] ?? null;
                markPendingSync('inbound_dialplan', 'announcement', $sound_name, "Anons: {$title}", 'create', $uid);
                markPendingSync('ivrs', 'announcement', $sound_name, "Anons: {$title}", 'create', $uid);
                markPendingSync('time_conditions', 'announcement', $sound_name, "Anons: {$title}", 'create', $uid);

                return "Ses anons dosyası 'custom/$sound_name.wav' yüklendi! Etkili olması için Uygula sayfasından gönderin.";
            } else {
                throw new \Exception("Ses dosyası yüklenirken hata oluştu!");
            }
        });
    }

    public static function saveAnnouncement($data, $files = []) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data, $files) {
            $anc_id = intval($data['anc_id'] ?? 0);
            $title = trim($data['title'] ?? '');
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
            $custom_dir = SOUNDS_CUSTOM_DIR;

            if ($anc_id <= 0 || empty($title)) {
                throw new \Exception("Lütfen geçerli bir anons başlığı belirtin!");
            }

            $internal_number = internalNumberSanitize($data['internal_number'] ?? '');
            $eski_numara = (string) (DBHelper::fetchColumn(
                "SELECT internal_number FROM pbx_announcements WHERE id = ?", [$anc_id]
            ) ?? '');
            assertInternalNumberAvailable($internal_number, 'announcement', $anc_id);

            DBHelper::update('pbx_announcements', [
                'title' => $title,
                'is_active' => $is_active,
                'internal_number' => $internal_number !== '' ? $internal_number : null,
            ], 'id', $anc_id);
            $uid = $_SESSION['user_id'] ?? null;
            markPendingSync('inbound_dialplan', 'announcement', $anc_id, "Anons: {$title}", 'update', $uid);
            markPendingSync('ivrs', 'announcement', $anc_id, "Anons: {$title}", 'update', $uid);
            markPendingSync('time_conditions', 'announcement', $anc_id, "Anons: {$title}", 'update', $uid);
            // Numara eklendi/degistirildi/silindiyse dahili hedef context'i de tazelenmeli.
            if ($internal_number !== $eski_numara) {
                markPendingSync('internal_numbers', 'announcement', $anc_id,
                    "Dahili hedef numarasi: " . ($internal_number !== '' ? $internal_number : 'kaldirildi'),
                    'update', $uid);
            }
            $msg = "Ses anonsu başlığı '$title' güncellendi! Etkili olması için Uygula sayfasından gönderin.";

            if (isset($files['audio_file']) && $files['audio_file']['error'] === UPLOAD_ERR_OK) {
                $anc = DBHelper::fetchOne("SELECT audio_file FROM pbx_announcements WHERE id = ?", [$anc_id]);
                if ($anc) {
                    $sound_name = str_replace('custom/', '', $anc['audio_file']);
                    $target_file = $custom_dir . '/' . $sound_name . '.wav';
                    $raw_upload = $custom_dir . '/.raw_' . uniqid() . '_' . $sound_name;
                    if (move_uploaded_file($files['audio_file']['tmp_name'], $raw_upload)) {
                        try {
                            self::convertToAsteriskWav($raw_upload, $target_file);
                        } finally {
                            @unlink($raw_upload);
                        }
                        @chown($target_file, 'asterisk');
                        @chgrp($target_file, 'asterisk');
                        @chmod($target_file, 0664);
                        $msg = "Ses anonsu başlığı ve ses dosyası güncellendi!";
                    }
                }
            }
            return $msg;
        });
    }

    public static function deleteAnnouncement($anc_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($anc_id) {
            $anc_id = intval($anc_id);
            $custom_dir = SOUNDS_CUSTOM_DIR;
            $anc = DBHelper::fetchOne("SELECT audio_file, internal_number FROM pbx_announcements WHERE id = ?", [$anc_id]);
            $silinen_numara = (string) ($anc['internal_number'] ?? '');

            if ($anc) {
                $file_path = $custom_dir . '/' . str_replace('custom/', '', $anc['audio_file']) . '.wav';
                FileHelper::deleteFile($file_path);
                DBHelper::delete('pbx_announcements', 'id', $anc_id);
                $uid = $_SESSION['user_id'] ?? null;
                markPendingSync('inbound_dialplan', 'announcement', $anc_id, "Anons: " . ($anc['audio_file'] ?? $anc_id) . " (silindi)", 'delete', $uid);
                markPendingSync('ivrs', 'announcement', $anc_id, "Anons: " . ($anc['audio_file'] ?? $anc_id) . " (silindi)", 'delete', $uid);
                markPendingSync('time_conditions', 'announcement', $anc_id, "Anons: " . ($anc['audio_file'] ?? $anc_id) . " (silindi)", 'delete', $uid);
                if (!empty($silinen_numara)) {
                    markPendingSync('internal_numbers', 'announcement', $anc_id,
                        "Dahili hedef numarasi silindi: {$silinen_numara}", 'delete', $uid);
                }
                return "Ses anonsu başarıyla silindi! Etkili olması için Uygula sayfasından gönderin.";
            }
            throw new \Exception("Anons kaydı bulunamadı!");
        });
    }

    public static function saveMOHClass($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $class_name = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['class_name'] ?? ''));
            if (empty($class_name)) {
                throw new \Exception("Geçersiz MOH sınıf ismi!");
            }
            $target_dir = MOH_BASE_DIR . '/' . $class_name;
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
                @chown($target_dir, 'asterisk');
            }

            $db = getDB();
            $stmt = $db->prepare("INSERT INTO pbx_moh_classes (name, directory, mode, sort) VALUES (?, ?, 'files', 'alpha') ON DUPLICATE KEY UPDATE directory = VALUES(directory)");
            $stmt->execute([$class_name, $target_dir]);

            markPendingSync('moh', 'moh_class', $class_name, "MOH Sınıfı: {$class_name}", 'create', $_SESSION['user_id'] ?? null);
            return "Bekleme Müziği (MOH) Sınıfı '{$class_name}' başarıyla oluşturuldu! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function deleteMOHClass($moh_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($moh_id) {
            $moh_id = intval($moh_id);
            if ($moh_id <= 0) throw new \Exception("Geçersiz MOH ID!");

            $db = getDB();
            $stmt = $db->prepare("SELECT name FROM pbx_moh_classes WHERE id = ? AND name != 'default'");
            $stmt->execute([$moh_id]);
            $class_name = $stmt->fetchColumn();

            if ($class_name) {
                $dir = MOH_BASE_DIR . '/' . $class_name;
                $stmt = $db->prepare("DELETE FROM pbx_moh_classes WHERE id = ?");
                $stmt->execute([$moh_id]);
                if (is_dir($dir)) {
                    $files = glob($dir . '/*');
                    foreach ($files as $f) { if (is_file($f)) @unlink($f); }
                    @rmdir($dir);
                }
                markPendingSync('moh', 'moh_class', $class_name, "MOH Sınıfı: {$class_name} (silindi)", 'delete', $_SESSION['user_id'] ?? null);
                return "MOH sınıfı '{$class_name}' silindi! Etkili olması için Uygula sayfasından gönderin.";
            }
            throw new \Exception("Varsayılan MOH sınıfı silinemez veya sınıf bulunamadı!");
        });
    }

    /**
     * Bir MOH sınıfına özel bekleme müziği dosyası yükler. Önceden
     * sounds.php'nin içine gömülüydü (Service katmanı dışında bir istisnaydı);
     * MVC göçü sırasında (2026-08-22) buraya taşındı, mantık DEĞİŞTİRİLMEDİ.
     */
    public static function uploadMOHFile($data, $files, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($data, $files) {
            $moh_class = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['moh_class'] ?? 'default'));
            $target_dir = ($moh_class === 'default') ? MOH_BASE_DIR : MOH_BASE_DIR . '/' . $moh_class;

            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
                @chown($target_dir, 'asterisk');
            }

            // Not: orijinal sayfada dosya eksikse/hatalıysa sessizce hiçbir şey
            // yapılmıyordu (mesaj/hata yok) — bu davranış korunuyor (boş string
            // döner, handleAction() bunu "success, mesajsız" olarak işler).
            if (!isset($files['moh_audio']) || $files['moh_audio']['error'] !== UPLOAD_ERR_OK) {
                return '';
            }

            $base_name = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($files['moh_audio']['name'], PATHINFO_FILENAME));
            $file_name = $base_name . '.wav';
            $target_path = $target_dir . '/' . $file_name;
            $raw_upload = $target_dir . '/.raw_' . uniqid() . '_' . $base_name;

            if (move_uploaded_file($files['moh_audio']['tmp_name'], $raw_upload)) {
                try {
                    self::convertToAsteriskWav($raw_upload, $target_path);
                    @chown($target_path, 'asterisk');
                    @chgrp($target_path, 'asterisk');
                    @chmod($target_path, 0664);

                    markPendingSync('moh', 'moh_class', $moh_class, "MOH Sınıfı: {$moh_class} (yeni dosya: {$file_name})", 'update', $_SESSION['user_id'] ?? null);
                    return "MOH Ses dosyası '$file_name' [$moh_class] sınıfına yüklendi! Etkili olması için Uygula sayfasından gönderin.";
                } catch (\Exception $e) {
                    throw new \Exception("MOH ses dosyası dönüştürülürken hata oluştu: " . $e->getMessage());
                } finally {
                    @unlink($raw_upload);
                }
            }
            throw new \Exception("MOH ses dosyası yüklenirken hata oluştu!");
        });
    }
}
