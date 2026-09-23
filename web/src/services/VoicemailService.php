<?php
/**
 * Voicemail Service
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

class VoicemailService {
    public static function getUserVoicemailSettings($userId) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, extension, full_name, email, voicemail_enabled, voicemail_pin, voicemail_email, voicemail_attach_audio, vm_on_noanswer, vm_on_busy, vm_on_unavail, vm_always FROM sys_users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function saveUserVoicemailSettings($userId, $data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($userId, $data) {
            $db = getDB();
            $pin = preg_replace('/[^0-9]/', '', trim($data['voicemail_pin'] ?? ''));
            $email = trim($data['voicemail_email'] ?? '');
            $attach = isset($data['voicemail_attach_audio']) ? intval($data['voicemail_attach_audio']) : 1;
            $vm_on_noanswer = isset($data['vm_on_noanswer']) ? intval($data['vm_on_noanswer']) : 0;
            $vm_on_busy = isset($data['vm_on_busy']) ? intval($data['vm_on_busy']) : 0;
            $vm_on_unavail = isset($data['vm_on_unavail']) ? intval($data['vm_on_unavail']) : 0;
            $vm_always = isset($data['vm_always']) ? intval($data['vm_always']) : 0;
            $voicemail_enabled = isset($data['voicemail_enabled']) ? intval($data['voicemail_enabled']) : 1;

            $stmt = $db->prepare("UPDATE sys_users SET voicemail_enabled = ?, voicemail_pin = ?, voicemail_email = ?, voicemail_attach_audio = ?, vm_on_noanswer = ?, vm_on_busy = ?, vm_on_unavail = ?, vm_always = ? WHERE id = ?");
            $stmt->execute([$voicemail_enabled, $pin, $email, $attach, $vm_on_noanswer, $vm_on_busy, $vm_on_unavail, $vm_always, $userId]);

            // Voicemail ve General Dialplan sync gerekli
            markPendingSync('voicemail', 'voicemail', 'mailbox', "Sesli posta ayarları (#{$userId})", 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('general_dialplan', 'general_dialplan', 'dialplan', "Dahili arama planı (Sesli posta)", 'update', $_SESSION['user_id'] ?? null);

            return "Sesli posta ayarları başarıyla güncellendi.";
        });
    }

    public static function getVoicemailMessages($ext) {
        $ext = preg_replace('/[^0-9]/', '', (string)$ext);
        if ($ext === '') return [];

        $spoolDir = "/var/spool/asterisk/voicemail/default/{$ext}";
        $folders = ['INBOX' => 'Yeni', 'Old' => 'Dinlenmiş'];
        $messages = [];

        foreach ($folders as $folderKey => $folderName) {
            $dir = "{$spoolDir}/{$folderKey}";
            if (!is_dir($dir)) continue;

            $txtFiles = glob("{$dir}/msg*.txt");
            foreach ($txtFiles ?: [] as $txt) {
                $base = substr($txt, 0, -4);
                $msgNum = basename($base);
                $wavExists = is_file("{$base}.wav") || is_file("{$base}.WAV");

                $info = [
                    'id' => "{$folderKey}:{$msgNum}",
                    'folder' => $folderKey,
                    'folder_name' => $folderName,
                    'number' => $msgNum,
                    'callerid' => '',
                    'origdate' => '',
                    'duration' => 0,
                    'duration_formatted' => '00:00',
                    'has_audio' => $wavExists,
                ];

                $lines = @file($txt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines ?: [] as $line) {
                    if (str_contains($line, '=')) {
                        [$k, $v] = explode('=', $line, 2);
                        $k = trim($k);
                        $v = trim($v);
                        if ($k === 'callerid') $info['callerid'] = $v;
                        if ($k === 'origdate') $info['origdate'] = $v;
                        if ($k === 'duration') {
                            $sec = intval($v);
                            $info['duration'] = $sec;
                            $info['duration_formatted'] = sprintf('%02d:%02d', floor($sec / 60), $sec % 60);
                        }
                    }
                }
                $messages[] = $info;
            }
        }

        // Sort descending by date/number
        usort($messages, fn($a, $b) => strcmp($b['origdate'] ?? '', $a['origdate'] ?? ''));
        return $messages;
    }

    public static function deleteMessage($ext, $msgId, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($ext, $msgId) {
            $ext = preg_replace('/[^0-9]/', '', (string)$ext);
            $parts = explode(':', (string)$msgId, 2);
            if (count($parts) !== 2) throw new \Exception("Geçersiz mesaj ID");

            $folder = preg_replace('/[^a-zA-Z]/', '', $parts[0]);
            $msgNum = preg_replace('/[^a-zA-Z0-9]/', '', $parts[1]);

            $dir = "/var/spool/asterisk/voicemail/default/{$ext}/{$folder}";
            $pattern = "{$dir}/{$msgNum}.*";
            foreach (glob($pattern) ?: [] as $f) {
                @unlink($f);
            }
            return "Sesli mesaj silindi.";
        });
    }
}
