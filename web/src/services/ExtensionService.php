<?php
/**
 * Extension Service
 */

class ExtensionService {
    public static function saveExtension($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $user_id = intval($data['user_id'] ?? 0);
            $extension = trim($data['extension'] ?? '');
            $sip_password = trim($data['sip_password'] ?? '');
            $full_name = trim($data['full_name'] ?? '');
            $extension_type = ($data['extension_type'] ?? 'sip') === 'fax' ? 'fax' : 'sip';
            // Outbound route group: the user can only use the routes of this
            // group. 1 is the default; an invalid value falls back to 1 so the
            // user does not land in a context that does not exist and become
            // unable to call out at all.
            $outbound_group = max(1, min(99, intval($data['outbound_group'] ?? 1)));
            $sip_auth_digest = isset($data['sip_auth_digest']) ? (intval($data['sip_auth_digest']) ? 1 : 0) : 1;
            $cid_internal = trim($data['cid_internal'] ?? '');
            $cid_external = trim($data['cid_external'] ?? '');
            $is_active = isset($data['is_active']) ? 1 : 0;

            if (!preg_match('/^\d{3,6}$/', $extension)) {
                throw new \Exception(t('srv_ext.err_number'));
            }
            if (empty($full_name)) {
                throw new \Exception('Ad Soyad zorunludur!');
            }
            // Fax users never register over SIP (no PJSIP endpoint is generated).
            // A password is required for SIP extensions with digest auth.
            if ($extension_type === 'sip' && $sip_auth_digest === 1 && strlen($sip_password) < 6) {
                throw new \Exception(t('srv_ext.err_password'));
            }

            // The extension number must be unique
            $stmt = $db->prepare('SELECT id FROM sys_users WHERE extension = ? AND id != ?');
            $stmt->execute([$extension, $user_id]);
            if ($stmt->fetch()) {
                throw new \Exception(sprintf(t('srv_ext.err_taken'), $extension));
            }

            $permission_group_id = !empty($data['permission_group_id']) ? intval($data['permission_group_id']) : 1;
            $boss_secretary_group_id = !empty($data['boss_secretary_group_id']) ? intval($data['boss_secretary_group_id']) : null;
            $boss_secretary_role = $data['boss_secretary_role'] ?? 'none';
            if (!in_array($boss_secretary_role, ['none', 'boss', 'secretary'], true)) {
                $boss_secretary_role = 'none';
            }
            // The form sends a hidden 0 before each voicemail switch, because an
            // unticked box is not posted at all: reading a missing box as 1
            // made it impossible to turn the box off (#2). A caller that does
            // not send a switch keeps the stored value (new extension: on).
            $current = $user_id > 0
                ? ($db->query('SELECT voicemail_enabled, voicemail_email_notify, voicemail_attach_audio FROM sys_users WHERE id = ' . $user_id)->fetch(PDO::FETCH_ASSOC) ?: [])
                : [];
            $vmSwitch = fn(string $k): int => isset($data[$k]) ? (intval($data[$k]) ? 1 : 0) : (int)($current[$k] ?? 1);
            $voicemail_enabled = $vmSwitch('voicemail_enabled');
            $voicemail_email_notify = $vmSwitch('voicemail_email_notify');
            $voicemail_pin = preg_replace('/[^0-9]/', '', trim($data['voicemail_pin'] ?? ''));
            if (empty($voicemail_pin)) {
                $voicemail_pin = $extension;
            }
            $voicemail_email = trim($data['voicemail_email'] ?? '');
            $voicemail_attach_audio = $vmSwitch('voicemail_attach_audio');
            $vm_on_noanswer = isset($data['vm_on_noanswer']) ? intval($data['vm_on_noanswer']) : 0;
            $vm_on_busy = isset($data['vm_on_busy']) ? intval($data['vm_on_busy']) : 0;
            // The "when unreachable" box on the form was never read: the
            // variable was defined only in the fax branch, and saving a SIP
            // extension failed with "vm_on_unavail cannot be null" because the
            // column is NOT NULL.
            $vm_on_unavail = isset($data['vm_on_unavail']) ? intval($data['vm_on_unavail']) : 0;
            $vm_always = isset($data['vm_always']) ? intval($data['vm_always']) : 0;

            if ($extension_type === 'fax') {
                $voicemail_enabled = 0;
                $voicemail_pin = '';
                $voicemail_email = '';
                $voicemail_attach_audio = 0;
                $vm_on_noanswer = 0;
                $vm_on_busy = 0;
                $vm_on_unavail = 0;
                $vm_always = 0;
            }

            // Save/Update Extension
            if ($user_id > 0) {
                // Existing record: clean up the old extension file too (the number may change)
                $old_ext = DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$user_id]);
                DBHelper::update('sys_users', [
                    'full_name' => $full_name,
                    'extension' => $extension,
                    'sip_password' => $extension_type === 'sip' ? $sip_password : '',
                    'sip_auth_digest' => $sip_auth_digest,
                    'extension_type' => $extension_type,
                    'outbound_group' => $outbound_group,
                    'permission_group_id' => $permission_group_id,
                    'boss_secretary_group_id' => $boss_secretary_group_id,
                    'boss_secretary_role' => $boss_secretary_role,
                    'voicemail_enabled' => $voicemail_enabled,
                    'voicemail_pin' => $voicemail_pin,
                    'voicemail_email' => $voicemail_email,
                    'voicemail_email_notify' => $voicemail_email_notify,
                    'voicemail_attach_audio' => $voicemail_attach_audio,
                    'vm_on_noanswer' => $vm_on_noanswer,
                    'vm_on_busy' => $vm_on_busy,
                    'vm_on_unavail' => $vm_on_unavail,
                    'vm_always' => $vm_always,
                    'cid_internal' => $cid_internal,
                    'cid_external' => $cid_external,
                    'is_active' => $is_active
                ], 'id', $user_id);
                SIPHelper::setSettings($extension, ['auth_digest' => $sip_auth_digest ? 'yes' : 'no']);
                if ($old_ext && $old_ext !== $extension) {
                    SIPHelper::deleteSettings($old_ext);
                    markPendingSync('extensions', 'extension', $old_ext, "Extension: {$old_ext} (number changed, removed)", 'delete', $_SESSION['user_id'] ?? null);
                }
                markPendingSync('extensions', 'extension', $extension, "Extension: {$extension} ({$full_name})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('general_dialplan', 'general_dialplan', 'dialplan', "Extension dialplan ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('ivrs', 'ivrs', 'all', "IVR direct extension dialing ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('voicemail', 'voicemail', 'all', "Voicemail ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('permissions', 'permissions', 'all', "Permission groups ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                $msg = sprintf(t('srv_ext.updated'), $extension);
            } else {
                // New extension subscriber: create a minimal system record for the device
                $username = 'ext' . $extension;
                $stmt = $db->prepare('SELECT id FROM sys_users WHERE username = ?');
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $username .= rand(10, 99);
                }
                DBHelper::insert('sys_users', [
                    'username' => $username,
                    'password_hash' => '',
                    'full_name' => $full_name,
                    'email' => '',
                    'role' => 'user',
                    'extension' => $extension,
                    'sip_password' => $extension_type === 'sip' ? $sip_password : '',
                    'sip_auth_digest' => $sip_auth_digest,
                    'extension_type' => $extension_type,
                    'outbound_group' => $outbound_group,
                    'permission_group_id' => $permission_group_id,
                    'boss_secretary_group_id' => $boss_secretary_group_id,
                    'boss_secretary_role' => $boss_secretary_role,
                    'voicemail_enabled' => $voicemail_enabled,
                    'voicemail_pin' => $voicemail_pin,
                    'voicemail_email' => $voicemail_email,
                    'voicemail_email_notify' => $voicemail_email_notify,
                    'voicemail_attach_audio' => $voicemail_attach_audio,
                    'vm_on_noanswer' => $vm_on_noanswer,
                    'vm_on_busy' => $vm_on_busy,
                    'vm_on_unavail' => $vm_on_unavail,
                    'vm_always' => $vm_always,
                    'cid_internal' => $cid_internal,
                    'cid_external' => $cid_external,
                    'is_active' => $is_active,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                SIPHelper::syncExtensionToSIP($extension, $full_name, $sip_password, $sip_auth_digest);
                markPendingSync('extensions', 'extension', $extension, "Extension: {$extension} ({$full_name})", 'create', $_SESSION['user_id'] ?? null);
                markPendingSync('general_dialplan', 'general_dialplan', 'dialplan', "Extension dialplan ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('ivrs', 'ivrs', 'all', "IVR direct extension dialing ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('voicemail', 'voicemail', 'all', "Voicemail ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                markPendingSync('permissions', 'permissions', 'all', "Permission groups ({$extension})", 'update', $_SESSION['user_id'] ?? null);
                $msg = sprintf(t('srv_ext.created'), $extension);
            }
            return $msg;
        });
    }

    public static function removeExtension($user_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () use ($user_id) {
            $user_id = intval($user_id);
            if ($user_id <= 0) {
                throw new \Exception(t('srv_ext.err_invalid'));
            }
            $old_ext = DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$user_id]);
            if (empty($old_ext)) {
                throw new \Exception(t('srv_ext.err_no_ext'));
            }
            // Remove the extension (the user account is kept)
            DBHelper::update('sys_users', ['extension' => null, 'sip_password' => null], 'id', $user_id);
            markPendingSync('extensions', 'extension', $old_ext, "Extension: {$old_ext} (removed)", 'delete', $_SESSION['user_id'] ?? null);
            markPendingSync('general_dialplan', 'general_dialplan', 'dialplan', "Extension dialplan ({$old_ext} removed)", 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('ivrs', 'ivrs', 'all', "IVR direct extension dialing ({$old_ext} removed)", 'update', $_SESSION['user_id'] ?? null);
            return sprintf(t('srv_ext.removed'), $old_ext);
        });
    }

    /**
     * "Resync all extensions" button on /extensions (ExtensionController).
     * Marks every extension for the next Apply.
     */
    public static function syncAll($csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () {
            markPendingSync('extensions', 'system_setting', 'all_extensions', 'All extensions (manual resync)', 'update', $_SESSION['user_id'] ?? null);
            return t('srv_ext.resync');
        });
    }
}
