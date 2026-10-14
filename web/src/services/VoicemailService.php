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
            markPendingSync('voicemail', 'voicemail', 'mailbox', "Voicemail settings (#{$userId})", 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('general_dialplan', 'general_dialplan', 'dialplan', "Extension dialplan (voicemail)", 'update', $_SESSION['user_id'] ?? null);

            return t('srv_vm.saved');
        });
    }

    public static function getVoicemailMessages($ext) {
        $ext = preg_replace('/[^0-9]/', '', (string)$ext);
        if ($ext === '') return [];

        $spoolDir = "/var/spool/asterisk/voicemail/default/{$ext}";
        $folders = ['INBOX' => t('srv_vm.folder_new'), 'Old' => t('srv_vm.folder_old')];
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
                    'origtime' => 0,
                ];

                $lines = @file($txt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines ?: [] as $line) {
                    if (str_contains($line, '=')) {
                        [$k, $v] = explode('=', $line, 2);
                        $k = trim($k);
                        $v = trim($v);
                        if ($k === 'callerid') $info['callerid'] = $v;
                        if ($k === 'origdate') $info['origdate'] = $v;
                        if ($k === 'origtime') $info['origtime'] = (int) $v;
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

        // Heard messages archived to the S3 bucket (#69): listed, played and deleted like the others.
        foreach (self::archived($ext) as $row) {
            $sec = (int) $row['duration'];
            $messages[] = [
                'id' => 'Archive:' . $row['id'],
                'folder' => 'Archive',
                'folder_name' => t('srv_vm.folder_archive'),
                'number' => (string) $row['id'],
                'callerid' => (string) $row['callerid'],
                'origdate' => $row['origtime'] ? date('D M j h:i:s A T Y', (int) $row['origtime']) : '',
                'duration' => $sec,
                'duration_formatted' => sprintf('%02d:%02d', floor($sec / 60), $sec % 60),
                'has_audio' => true,
                'origtime' => (int) $row['origtime'],
            ];
        }

        // Newest first (origtime: seconds since 1970; origdate is text in the server's locale).
        usort($messages, fn($a, $b) => [$b['origtime'], $b['origdate']] <=> [$a['origtime'], $a['origdate']]);
        return $messages;
    }

    public static function deleteMessage($ext, $msgId, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, fn() => self::deleteFiles((string) $ext, (string) $msgId));
    }

    /** Deletes the files of one message (portal and mobile app); throws on failure. */
    public static function deleteFiles(string $ext, string $msgId): string {
        $ext = preg_replace('/[^0-9]/', '', $ext);
        if (preg_match('/^Archive:(\d+)$/', $msgId, $a)) {
            $key = self::archivedKey($ext, $msgId);
            if ($key === null) {
                throw new \Exception(t('srv_vm.err_not_found'));
            }
            require_once __DIR__ . '/BucketFiles.php';
            BucketFiles::delete($key);
            getDB()->prepare('DELETE FROM voicemail_archive WHERE id = ? AND mailbox = ?')->execute([(int) $a[1], $ext]);
            return t('srv_vm.deleted');
        }
        if ($ext === '' || !preg_match('/^(INBOX|Old):(msg[0-9]{4})$/', $msgId, $m)) {
            throw new \Exception(t('srv_vm.err_id'));
        }
        $files = glob("/var/spool/asterisk/voicemail/default/{$ext}/{$m[1]}/{$m[2]}.*") ?: [];
        if (empty($files)) {
            throw new \Exception(t('srv_vm.err_not_found'));
        }
        foreach ($files as $f) {
            // The error used to be swallowed and "deleted" was reported even when it could not be deleted.
            if (!@unlink($f)) {
                throw new \Exception(t('srv_vm.err_delete'));
            }
        }
        return t('srv_vm.deleted');
    }

    /**
     * Path of the audio of a message ("INBOX:msg0003"), or null. Only the
     * mailbox's own INBOX/Old folders and msgNNNN names are accepted.
     */
    public static function audioPath(string $ext, string $msgId): ?string
    {
        $ext = preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '' || !preg_match('/^(INBOX|Old):(msg[0-9]{4})$/', $msgId, $m)) {
            return null;
        }
        $base = "/var/spool/asterisk/voicemail/default/{$ext}/{$m[1]}/{$m[2]}";
        foreach (['wav', 'WAV'] as $suffix) {
            if (is_file("{$base}.{$suffix}")) {
                return "{$base}.{$suffix}";
            }
        }
        return null;
    }

    /**
     * Marks a new message as heard: moves its files from INBOX to Old under
     * the next free number, as Asterisk does after listening (the phones'
     * voicemail lamp follows through pollmailboxes). Returns the new id.
     */
    public static function markHeard(string $ext, string $msgId, ?string $spool = null): string
    {
        $ext = preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '' || !preg_match('/^INBOX:(msg[0-9]{4})$/', $msgId, $m)) {
            if (preg_match('/^(Old:msg[0-9]{4}|Archive:\d+)$/', $msgId)) {
                return $msgId; // already heard
            }
            throw new \Exception(t('srv_vm.err_id'));
        }
        $box = ($spool ?? '/var/spool/asterisk/voicemail/default') . '/' . $ext;
        $files = glob("{$box}/INBOX/{$m[1]}.*") ?: [];
        if ($files === []) {
            throw new \Exception(t('srv_vm.err_not_found'));
        }
        if (!is_dir("{$box}/Old")) {
            if (!@mkdir("{$box}/Old", 0775)) {
                throw new \Exception(t('srv_vm.err_delete'));
            }
            // Asterisk moves messages it plays into Old too: the folder must stay
            // writable for the asterisk group (www-data is a member), as it would
            // be had Asterisk created it.
            @chgrp("{$box}/Old", 'asterisk');
            @chmod("{$box}/Old", 0775);
        }
        $next = 0;
        foreach (glob("{$box}/Old/msg[0-9][0-9][0-9][0-9].*") ?: [] as $f) {
            $next = max($next, (int) substr(basename($f), 3, 4) + 1);
        }
        $target = sprintf('msg%04d', $next);
        foreach ($files as $f) {
            $suffix = substr(basename($f), strlen($m[1]));
            if (!@rename($f, "{$box}/Old/{$target}{$suffix}")) {
                throw new \Exception(t('srv_vm.err_delete'));
            }
        }
        return "Old:{$target}";
    }

    /** Messages of a mailbox archived to the bucket, newest first. */
    public static function archived(string $ext): array
    {
        try {
            $stmt = getDB()->prepare('SELECT id, callerid, origtime, duration FROM voicemail_archive WHERE mailbox = ? ORDER BY origtime DESC');
            $stmt->execute([$ext]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return []; // before the migration ran
        }
    }

    /** Bucket key of an archived message of this mailbox ("Archive:<id>"), or null. */
    public static function archivedKey(string $ext, string $msgId): ?string
    {
        if (!preg_match('/^Archive:(\d+)$/', $msgId, $m)) {
            return null;
        }
        $stmt = getDB()->prepare('SELECT object_key FROM voicemail_archive WHERE id = ? AND mailbox = ?');
        $stmt->execute([(int) $m[1], preg_replace('/[^0-9]/', '', $ext)]);
        $key = $stmt->fetchColumn();
        return is_string($key) && str_starts_with($key, 'voicemail/') ? $key : null;
    }
}
