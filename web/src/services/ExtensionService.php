<?php
/**
 * Extension Service
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../internal_numbers.php';
require_once __DIR__ . '/../sip_helper.php';

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
            $sip_auth_digest = PBXHelper::boolFlag($data, 'sip_auth_digest', 1);
            $cid_internal = trim($data['cid_internal'] ?? '');
            $cid_external = trim($data['cid_external'] ?? '');
            $is_active = PBXHelper::boolFlag($data, 'is_active', 0);

            if (!preg_match('/^\d{3,6}$/', $extension)) {
                throw new \Exception(t('srv_ext.err_number'));
            }
            if (empty($full_name)) {
                throw new \Exception(t('srv_user.err_required'));
            }
            // Fax users never register over SIP (no PJSIP endpoint is generated).
            // A password is required for SIP extensions with digest auth.
            if ($extension_type === 'sip' && $sip_auth_digest === 1 && strlen($sip_password) < 6) {
                throw new \Exception(t('srv_ext.err_password'));
            }

            // The extension number must not collide with other internal numbers or extensions
            internalNumberValidate($extension, 'user', $user_id);
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
            // An empty PIN becomes the extension number, which anyone can guess;
            // with the rule "PIN may not be the extension" (PBX settings →
            // Voicemail, on by default) it becomes a random PIN instead. A
            // typed PIN must pass the rules.
            require_once __DIR__ . '/VoicemailPolicy.php';
            $voicemail_pin = preg_replace('/[^0-9]/', '', trim($data['voicemail_pin'] ?? ''));
            if ($voicemail_pin === '') {
                $voicemail_pin = VoicemailPolicy::settings()['vm_pin_not_extension']
                    ? VoicemailPolicy::randomPin((string) $extension)
                    : (string) $extension;
            } elseif ($extension_type !== 'fax' && ($pinError = VoicemailPolicy::pinError($voicemail_pin, (string) $extension)) !== null) {
                throw new \Exception($pinError);
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
                $old_role = DBHelper::fetchColumn('SELECT role FROM sys_users WHERE id = ?', [$user_id]);
                if ($old_role === 'admin' && $is_active == 0) {
                    $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$user_id]);
                    if (intval($other_admins) < 1) {
                        throw new \Exception(t('srv_user.err_last_admin_role'));
                    }
                }
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
                // The SIP secret must follow the panel password: SyncExtensions
                // prefers sip.secret over sys_users.sip_password, so writing
                // only auth_digest here left the PJSIP endpoint on the OLD
                // secret (and the old secret stayed valid). A non-SIP (fax)
                // extension has no endpoint, so any leftover row is removed.
                if ($extension_type === 'sip') {
                    SIPHelper::setSettings($extension, [
                        // Without digest auth the password may be empty: keep the old one.
                        'secret' => $sip_password !== '' ? $sip_password : null,
                        'auth_digest' => $sip_auth_digest ? 'yes' : 'no',
                    ]);
                } else {
                    SIPHelper::deleteSettings($extension);
                }
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
            SIPHelper::deleteSettings($old_ext);
            $db = getDB();
            try {
                $db->prepare('UPDATE pbx_phones SET user_id = NULL WHERE user_id = ?')->execute([$user_id]);
                $db->prepare('DELETE FROM pbx_phone_keys WHERE user_id = ?')->execute([$user_id]);
                if (is_file(__DIR__ . '/PhoneProvisionService.php')) {
                    require_once __DIR__ . '/PhoneProvisionService.php';
                    PhoneProvisionService::exportTftpQuietly();
                }
            } catch (\Throwable $e) {
            }
            $queues = $db->query("SELECT id, queue_name, members_json, static_members_json, supervisors_json, supervisor_extension FROM pbx_queues")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($queues as $q) {
                $m_list = json_decode($q['members_json'] ?? '[]', true) ?: [];
                $s_list = json_decode($q['supervisors_json'] ?? '[]', true) ?: [];
                $st_list = array_map('strval', json_decode($q['static_members_json'] ?? '[]', true) ?: []);
                $m_new = array_values(array_diff(array_map('strval', $m_list), [(string)$old_ext]));
                $s_new = array_values(array_diff(array_map('strval', $s_list), [(string)$old_ext]));
                $st_new = array_values(array_diff($st_list, [(string)$old_ext]));
                $update_data = [
                    'members_json' => json_encode($m_new),
                    'static_members_json' => json_encode($st_new),
                    'supervisors_json' => json_encode($s_new),
                ];
                if (count($st_new) !== count($st_list)) {
                    markPendingSync('queues', 'queue', $q['queue_name'], "Queue: {$q['queue_name']} (static agent {$old_ext} removed)", 'update', $_SESSION['user_id'] ?? null);
                }
                if ((string)$q['supervisor_extension'] === (string)$old_ext) {
                    $update_data['supervisor_extension'] = !empty($s_new) ? $s_new[0] : null;
                }
                DBHelper::update('pbx_queues', $update_data, 'id', $q['id']);
            }
            require_once __DIR__ . '/../queue_helper.php';
            $stmt_all_q = $db->query("SELECT queue_name FROM pbx_queues WHERE is_active = 1");
            if ($stmt_all_q) {
                foreach ($stmt_all_q->fetchAll(PDO::FETCH_COLUMN) as $qn) {
                    QueueHelper::setMembership($old_ext, $qn, false);
                }
            }
            $uid = $_SESSION['user_id'] ?? null;
            markPendingSync('extensions', 'extension', $old_ext, "Extension: {$old_ext} (removed)", 'delete', $uid);
            markPendingSync('general_dialplan', 'general_dialplan', 'dialplan', "Extension dialplan ({$old_ext} removed)", 'update', $uid);
            markPendingSync('ivrs', 'ivrs', 'all', "IVR direct extension dialing ({$old_ext} removed)", 'update', $uid);
            markPendingSync('voicemail', 'voicemail', 'all', "Voicemail ({$old_ext} removed)", 'update', $uid);
            markPendingSync('permissions', 'permissions', 'all', "Permission groups ({$old_ext} removed)", 'update', $uid);
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
