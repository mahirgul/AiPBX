<?php
/**
 * User & Extension Service
 */

class UserService {
    /**
     * Readable temporary password shown to the admin once (no confusable
     * 0/O, 1/l/I): xxxx-xxxx-xxxx, ~68 bits.
     */
    public static function generateReadablePassword(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        return implode('-', $groups);
    }

    public static function saveUser($data) {
        $generated_password = '';
        $res = PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data, &$generated_password) {
            $user_id = intval($data['user_id'] ?? 0);
            $username = trim($data['username'] ?? '');
            $password = trim($data['password'] ?? '');
            $sip_password = trim($data['sip_password'] ?? '');
            $full_name = trim($data['full_name'] ?? '');
            $email = trim($data['email'] ?? '');
            $role = trim($data['role'] ?? 'user');
            $extension = trim($data['extension'] ?? '');
            $cid_internal = trim($data['cid_internal'] ?? '');
            $cid_external = trim($data['cid_external'] ?? '');
            $pickup_group = trim($data['pickup_group'] ?? '');
            $can_listen = isset($data['can_listen_recordings']) ? 1 : 0;
            $can_view_cdrs = isset($data['can_view_all_cdrs']) ? 1 : 0;
            $can_view_queue_monitor = isset($data['can_view_queue_monitor']) ? 1 : 0;
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($username) || empty($full_name)) {
                throw new \Exception(t('srv_user.err_required'));
            }

            if (isset($data['allowed_phone_modes']) && is_array($data['allowed_phone_modes'])) {
                $allowed_phone_mode = formatPhoneModes($data['allowed_phone_modes']);
            } elseif (isset($data['phone_modes']) && is_array($data['phone_modes'])) {
                $allowed_phone_mode = formatPhoneModes($data['phone_modes']);
            } else {
                $raw = trim($data['allowed_phone_mode'] ?? 'both');
                $allowed_phone_mode = formatPhoneModes(parsePhoneModes($raw));
            }

            // The username is UNIQUE in the DB (the error would be a raw PDO
            // error); the extension number was NOT checked at all — two users
            // could get the same extension (two accounts wrote the same PJSIP
            // endpoint).
            if (DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_users WHERE username = ? AND id != ?', [$username, $user_id]) > 0) {
                throw new \Exception(sprintf(t('srv_user.err_username_taken'), $username));
            }
            if ($extension !== '' && DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_users WHERE extension = ? AND id != ?', [$extension, $user_id]) > 0) {
                throw new \Exception(sprintf(t('srv_user.err_ext_taken'), $extension));
            }
            // It must not collide with a queue, IVR, conference, ring group, feature code etc. either.
            if ($extension !== '') {
                require_once dirname(__DIR__) . '/internal_numbers.php';
                internalNumberValidate($extension, 'user', $user_id);
            }

            $valid_roles = array_column(DBHelper::fetchAll('SELECT role_key FROM sys_roles'), 'role_key');
            if (!in_array($role, $valid_roles, true)) {
                throw new \Exception(sprintf(t('srv_user.err_role'), $role));
            }

            $old_extension = $user_id > 0 ? DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$user_id]) : null;
            if ($old_extension && $old_extension !== $extension) {
                SIPHelper::deleteSettings($old_extension);
            }

            // roles.php/system_users.php are open only to users with
            // role==='admin' + is_active (auth.php circuit breaker) — if this
            // edit changes the role of the LAST active admin in the system or
            // deactivates them, nobody can reach these two pages anymore (see
            // the equivalent protection in UserService::deleteUser() and
            // PBXHelper::toggleStatus()).
            if ($user_id > 0) {
                $old_role = DBHelper::fetchColumn('SELECT role FROM sys_users WHERE id = ?', [$user_id]);
                if ($old_role === 'admin' && ($role !== 'admin' || $is_active == 0)) {
                    $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$user_id]);
                    if (intval($other_admins) < 1) {
                        throw new \Exception(t('srv_user.err_last_admin_role'));
                    }
                }
            }

            if ($user_id > 0) {
                $save_data = [
                    'id' => $user_id,
                    'username' => $username,
                    'full_name' => $full_name,
                    'email' => $email,
                    'role' => $role,
                    'extension' => $extension ?: null,
                    'cid_internal' => $cid_internal,
                    'cid_external' => $cid_external,
                    'pickup_group' => $pickup_group ?: null,
                    'can_listen_recordings' => $can_listen,
                    'can_view_all_cdrs' => $can_view_cdrs,
                    'can_view_queue_monitor' => $can_view_queue_monitor,
                    'allowed_phone_mode' => $allowed_phone_mode,
                    'is_active' => $is_active
                ];
                if (!empty($password)) {
                    $save_data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                if (!empty($sip_password)) {
                    $save_data['sip_password'] = $sip_password;
                }
                DBHelper::save('sys_users', $save_data);
                $msg = sprintf(t('srv_user.updated'), $username);
                writeAuditLog(null, 'user_account', $user_id, "User: {$username} ({$full_name}, role: {$role})", 'update', $_SESSION['user_id'] ?? null);
            } else {
                if (empty($password)) {
                    if (!empty($email)) {
                        // Shown to nobody: the user sets their web password
                        // through the link in the invitation email; mobile
                        // sign-in needs no password at all.
                        $effective_password = bin2hex(random_bytes(16));
                    } else {
                        // No email: shown to the admin once, and must be changed
                        // at the first web sign-in.
                        $generated_password = self::generateReadablePassword();
                        $effective_password = $generated_password;
                    }
                } else {
                    $effective_password = $password;
                }
                $effective_sip_pass = !empty($sip_password) ? $sip_password : SIPHelper::generateStrongSIPPassword();
                DBHelper::insert('sys_users', [
                    'username' => $username,
                    'password_hash' => password_hash($effective_password, PASSWORD_DEFAULT),
                    'sip_password' => $effective_sip_pass,
                    'full_name' => $full_name,
                    'email' => $email,
                    'role' => $role,
                    'extension' => $extension ?: null,
                    'cid_internal' => $cid_internal,
                    'cid_external' => $cid_external,
                    'pickup_group' => $pickup_group ?: null,
                    'can_listen_recordings' => $can_listen,
                    'can_view_all_cdrs' => $can_view_cdrs,
                    'can_view_queue_monitor' => $can_view_queue_monitor,
                    'allowed_phone_mode' => $allowed_phone_mode,
                    'is_active' => $is_active,
                    'must_reset_password' => $generated_password !== '' ? 1 : 0
                ]);
                $user_id = (int) getDB()->lastInsertId();
                $msg = sprintf(t('srv_user.created'), $username);
                writeAuditLog(null, 'user_account', $user_id, "User: {$username} ({$full_name}, role: {$role})", 'create', $_SESSION['user_id'] ?? null);

                // With an email set, send the automatic activation and password-setup mail
                // (the admin can turn invitations off on CSV import: skip_invitation).
                if (!empty($email) && empty($data['skip_invitation'])) {
                    require_once __DIR__ . '/UserInvitationService.php';
                    $inviteRes = UserInvitationService::sendInvitationEmail($user_id, true);
                    if ($inviteRes['success']) {
                        $msg .= ' ' . sprintf(t('srv_user.invite_sent'), $email);
                    } else {
                        $msg .= ' (' . sprintf(t('srv_user.invite_failed'), ($inviteRes['error'] ?? '')) . ')';
                    }
                }
            }

            $uid = $_SESSION['user_id'] ?? null;
            if (!empty($extension)) {
                $db = getDB();
                $cur_pass = DBHelper::fetchColumn("SELECT sip_password FROM sys_users WHERE extension = ?", [$extension]);
                $effective_sip_pass = !empty($sip_password) ? $sip_password : (!empty($cur_pass) ? $cur_pass : SIPHelper::generateStrongSIPPassword());
                SIPHelper::syncExtensionToSIP($extension, $full_name, $effective_sip_pass);
                markPendingSync('extensions', 'extension', $extension, "Extension: {$extension} ({$full_name})", 'update', $uid);
                markPendingSync('general_dialplan', 'extension', $extension, "Extension dialplan: {$extension}", 'update', $uid);
                $msg .= ' ' . t('common.apply_hint');
            } elseif (!empty($old_extension)) {
                // The extension number was removed: regenerate so the old PJSIP endpoint drops out of the conf
                markPendingSync('extensions', 'extension', $old_extension, "Extension: {$old_extension} (removed)", 'delete', $uid);
                markPendingSync('general_dialplan', 'extension', $old_extension, "Extension dialplan: {$old_extension} (removed)", 'delete', $uid);
                $msg .= ' ' . t('common.apply_hint');
            }
            return $msg;
        });
        if (!empty($res['success']) && $generated_password !== '') {
            $res['generated_password'] = $generated_password;
        }
        return $res;
    }

    public static function deleteUser($user_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($user_id) {
            $user_id = intval($user_id);
            if ($user_id <= 0) throw new \Exception(t('srv_invite.err_invalid_id'));

            // roles.php/system_users.php are open only to users with
            // role==='admin' (auth.php circuit breaker) — if the LAST active
            // admin account in the system is deleted, nobody can reach these
            // two pages anymore and the system is locked for good. This check
            // used to exist only in the interface (the delete button was
            // hidden for username==='admin'), not on the server at all.
            $target_role = DBHelper::fetchColumn("SELECT role FROM sys_users WHERE id = ?", [$user_id]);
            if ($target_role === 'admin') {
                $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$user_id]);
                if (intval($other_admins) < 1) {
                    throw new \Exception(t('srv_user.err_last_admin_delete'));
                }
            }

            $target_user = DBHelper::fetchOne("SELECT username, full_name, extension FROM sys_users WHERE id = ?", [$user_id]);
            $ext = $target_user['extension'] ?? null;
            DBHelper::delete('sys_users', 'id', $user_id);
            writeAuditLog(null, 'user_account', $user_id, "User: " . ($target_user['username'] ?? $user_id) . " (" . ($target_user['full_name'] ?? '') . ", deleted)", 'delete', $_SESSION['user_id'] ?? null);

            if (!empty($ext)) {
                SIPHelper::deleteSettings($ext);
                // Sanitize pbx_queues members_json and supervisors_json
                $db = getDB();
                $queues = $db->query("SELECT id, queue_name, members_json, static_members_json, supervisors_json, supervisor_extension FROM pbx_queues")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($queues as $q) {
                    $m_list = json_decode($q['members_json'] ?? '[]', true) ?: [];
                    $s_list = json_decode($q['supervisors_json'] ?? '[]', true) ?: [];
                    
                    $m_new = array_values(array_diff(array_map('strval', $m_list), [(string)$ext]));
                    $s_new = array_values(array_diff(array_map('strval', $s_list), [(string)$ext]));
                    
                    $st_list = array_map('strval', json_decode($q['static_members_json'] ?? '[]', true) ?: []);
                    $st_new = array_values(array_diff($st_list, [(string)$ext]));

                    $update_data = [
                        'members_json' => json_encode($m_new),
                        'static_members_json' => json_encode($st_new),
                        'supervisors_json' => json_encode($s_new)
                    ];
                    // A static member is a "member =>" line in queues_pbx.conf; the config must be regenerated.
                    if (count($st_new) !== count($st_list)) {
                        markPendingSync('queues', 'queue', $q['queue_name'], "Queue: {$q['queue_name']} (static agent {$ext} removed)", 'update', $_SESSION['user_id'] ?? null);
                    }
                    if ((string)$q['supervisor_extension'] === (string)$ext) {
                        $update_data['supervisor_extension'] = !empty($s_new) ? $s_new[0] : null;
                    }
                    
                    DBHelper::update('pbx_queues', $update_data, 'id', $q['id']);
                }

                // Remove from all live Asterisk queues
                require_once __DIR__ . '/../queue_helper.php';
                $db_q = getDB();
                $stmt_all_q = $db_q->query("SELECT queue_name FROM pbx_queues WHERE is_active = 1");
                if ($stmt_all_q) {
                    foreach ($stmt_all_q->fetchAll(PDO::FETCH_COLUMN) as $qn) {
                        QueueHelper::setMembership($ext, $qn, false);
                    }
                }
                markPendingSync('extensions', 'extension', $ext, "Extension: {$ext} (user deleted)", 'delete', $_SESSION['user_id'] ?? null);
                return t('srv_user.deleted_apply');
            }
            return t('srv_user.deleted');
        });
    }

    public static function resetPassword($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $user_id = intval($data['user_id'] ?? 0);
            $new_password = trim($data['new_password'] ?? '');
            $new_sip_password = trim($data['new_sip_password'] ?? '');

            if ($user_id <= 0 || (empty($new_password) && empty($new_sip_password))) {
                throw new \Exception(t('srv_user.err_no_password'));
            }

            $up_fields = [];
            if (!empty($new_password)) {
                $up_fields['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            }
            if (!empty($new_sip_password)) {
                $up_fields['sip_password'] = $new_sip_password;
            }
            DBHelper::update('sys_users', $up_fields, 'id', $user_id);
            if (!empty($new_password)) {
                // When an administrator resets the password, the sessions on the phones drop too.
                getDB()->prepare('UPDATE sys_users SET token_epoch = token_epoch + 1 WHERE id = ?')->execute([$user_id]);
            }

            // The passwords THEMSELVES are never logged — only "which kind of
            // password changed" (web sign-in / SIP / both).
            $changed_kinds = [];
            if (!empty($new_password)) $changed_kinds[] = 'web login';
            if (!empty($new_sip_password)) $changed_kinds[] = 'SIP';
            $u_username = DBHelper::fetchColumn("SELECT username FROM sys_users WHERE id = ?", [$user_id]);
            writeAuditLog(null, 'user_account', $user_id, "User: " . ($u_username ?: $user_id) . " (password changed: " . implode('+', $changed_kinds) . ")", 'update', $_SESSION['user_id'] ?? null);

            $u_ext = DBHelper::fetchColumn("SELECT extension FROM sys_users WHERE id = ?", [$user_id]);
            if (!empty($u_ext) && !empty($new_sip_password)) {
                markPendingSync('extensions', 'extension', $u_ext, "Extension: {$u_ext} (SIP password changed)", 'update', $_SESSION['user_id'] ?? null);
                return t('srv_user.pw_updated_apply');
            }
            return t('srv_user.pw_updated');
        });
    }
}
