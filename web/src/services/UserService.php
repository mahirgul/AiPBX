<?php
/**
 * User & Extension Service
 */

class UserService {
    public static function saveUser($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $user_id = intval($data['user_id'] ?? 0);
            $username = trim($data['username'] ?? '');
            $password = trim($data['password'] ?? '');
            $sip_password = trim($data['sip_password'] ?? '');
            $full_name = trim($data['full_name'] ?? '');
            $email = trim($data['email'] ?? '');
            $role = trim($data['role'] ?? 'cc_agent');
            $extension = trim($data['extension'] ?? '');
            $cid_internal = trim($data['cid_internal'] ?? '');
            $cid_external = trim($data['cid_external'] ?? '');
            $pickup_group = trim($data['pickup_group'] ?? '');
            $can_listen = isset($data['can_listen_recordings']) ? 1 : 0;
            $can_view_cdrs = isset($data['can_view_all_cdrs']) ? 1 : 0;
            $can_view_queue_monitor = isset($data['can_view_queue_monitor']) ? 1 : 0;
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($username) || empty($full_name)) {
                throw new \Exception("Kullanıcı adı ve ad soyad zorunludur!");
            }

            if (isset($data['allowed_phone_modes']) && is_array($data['allowed_phone_modes'])) {
                $allowed_phone_mode = formatPhoneModes($data['allowed_phone_modes']);
            } elseif (isset($data['phone_modes']) && is_array($data['phone_modes'])) {
                $allowed_phone_mode = formatPhoneModes($data['phone_modes']);
            } else {
                $raw = trim($data['allowed_phone_mode'] ?? 'both');
                $allowed_phone_mode = formatPhoneModes(parsePhoneModes($raw));
            }

            $valid_roles = array_column(DBHelper::fetchAll('SELECT role_key FROM sys_roles'), 'role_key');
            if (!in_array($role, $valid_roles, true)) {
                throw new \Exception("Geçersiz sistem rolü: '{$role}'");
            }

            $old_extension = $user_id > 0 ? DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$user_id]) : null;
            if ($old_extension && $old_extension !== $extension) {
                SIPHelper::deleteSettings($old_extension);
            }

            // roles.php/system_users.php sadece role==='admin' + is_active olan
            // kullanıcılara açık (auth.php circuit-breaker) — bu düzenleme sistemdeki
            // SON aktif admin'in rolünü değiştiriyor veya onu pasife alıyorsa, kimse
            // artık bu iki sayfaya giremez hale gelir (bkz. UserService::deleteUser()
            // ve PBXHelper::toggleStatus()'taki eşdeğer koruma).
            if ($user_id > 0) {
                $old_role = DBHelper::fetchColumn('SELECT role FROM sys_users WHERE id = ?', [$user_id]);
                if ($old_role === 'admin' && ($role !== 'admin' || $is_active == 0)) {
                    $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$user_id]);
                    if (intval($other_admins) < 1) {
                        throw new \Exception("Sistemdeki son aktif admin hesabının rolü değiştirilemez veya pasife alınamaz! Önce başka bir kullanıcıyı admin yapın.");
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
                $msg = "Kullanıcı '{$username}' güncellendi!";
                writeAuditLog(null, 'user_account', $user_id, "Kullanıcı: {$username} ({$full_name}, rol: {$role})", 'update', $_SESSION['user_id'] ?? null);
            } else {
                if (empty($password)) {
                    if (!empty($email)) {
                        $effective_password = bin2hex(random_bytes(16));
                    } else {
                        throw new \Exception("Yeni kullanıcı için web giriş şifresi veya aktivasyon için e-posta adresi zorunludur!");
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
                    'is_active' => $is_active
                ]);
                $user_id = (int) getDB()->lastInsertId();
                $msg = "Yeni sistem kullanıcısı '{$username}' oluşturuldu!";
                writeAuditLog(null, 'user_account', $user_id, "Kullanıcı: {$username} ({$full_name}, rol: {$role})", 'create', $_SESSION['user_id'] ?? null);

                // E-posta tanımlıysa otomatik aktivasyon ve şifre belirleme maili gönder
                if (!empty($email)) {
                    require_once __DIR__ . '/UserInvitationService.php';
                    $inviteRes = UserInvitationService::sendInvitationEmail($user_id, true);
                    if ($inviteRes['success']) {
                        $msg .= " Aktivasyon ve şifre belirleme e-postası ({$email}) gönderildi.";
                    } else {
                        $msg .= " (Uyarı: Aktivasyon maili gönderilemedi: " . ($inviteRes['error'] ?? '') . ")";
                    }
                }
            }

            $uid = $_SESSION['user_id'] ?? null;
            if (!empty($extension)) {
                $db = getDB();
                $cur_pass = DBHelper::fetchColumn("SELECT sip_password FROM sys_users WHERE extension = ?", [$extension]);
                $effective_sip_pass = !empty($sip_password) ? $sip_password : (!empty($cur_pass) ? $cur_pass : SIPHelper::generateStrongSIPPassword());
                SIPHelper::syncExtensionToSIP($extension, $full_name, $effective_sip_pass);
                markPendingSync('extensions', 'extension', $extension, "Dahili: {$extension} ({$full_name})", 'update', $uid);
                $msg .= " Etkili olması için Uygula sayfasından gönderin.";
            } elseif (!empty($old_extension)) {
                // Dahili numarası kaldırıldı: eski PJSIP endpoint'inin conf'tan düşmesi için yeniden üret
                markPendingSync('extensions', 'extension', $old_extension, "Dahili: {$old_extension} (kaldırıldı)", 'delete', $uid);
                $msg .= " Etkili olması için Uygula sayfasından gönderin.";
            }
            return $msg;
        });
    }

    public static function deleteUser($user_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($user_id) {
            $user_id = intval($user_id);
            if ($user_id <= 0) throw new \Exception("Geçersiz kullanıcı ID!");

            // roles.php/system_users.php sadece role==='admin' olan kullanıcılara açık
            // (auth.php circuit-breaker) — sistemdeki SON aktif admin hesabı silinirse
            // kimse artık bu iki sayfaya giremez, sistem kalıcı olarak kilitlenir.
            // Önceden bu kontrol sadece arayüzde (silme butonu username==='admin' için
            // gizli) yapılıyordu, sunucu tarafında hiç yoktu.
            $target_role = DBHelper::fetchColumn("SELECT role FROM sys_users WHERE id = ?", [$user_id]);
            if ($target_role === 'admin') {
                $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$user_id]);
                if (intval($other_admins) < 1) {
                    throw new \Exception("Sistemdeki son aktif admin hesabı silinemez! Önce başka bir kullanıcıyı admin yapın.");
                }
            }

            $target_user = DBHelper::fetchOne("SELECT username, full_name, extension FROM sys_users WHERE id = ?", [$user_id]);
            $ext = $target_user['extension'] ?? null;
            DBHelper::delete('sys_users', 'id', $user_id);
            writeAuditLog(null, 'user_account', $user_id, "Kullanıcı: " . ($target_user['username'] ?? $user_id) . " (" . ($target_user['full_name'] ?? '') . ", silindi)", 'delete', $_SESSION['user_id'] ?? null);

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
                    // Statik üye queues_pbx.conf'ta "member =>" satırı; config yeniden üretilmeli.
                    if (count($st_new) !== count($st_list)) {
                        markPendingSync('queues', 'queue', $q['queue_name'], "Kuyruk: {$q['queue_name']} (statik temsilci {$ext} silindi)", 'update', $_SESSION['user_id'] ?? null);
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
                markPendingSync('extensions', 'extension', $ext, "Dahili: {$ext} (kullanıcı silindi)", 'delete', $_SESSION['user_id'] ?? null);
                return "Kullanıcı hesabı silindi! Etkili olması için Uygula sayfasından gönderin.";
            }
            return "Kullanıcı hesabı silindi!";
        });
    }

    public static function resetPassword($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $user_id = intval($data['user_id'] ?? 0);
            $new_password = trim($data['new_password'] ?? '');
            $new_sip_password = trim($data['new_sip_password'] ?? '');

            if ($user_id <= 0 || (empty($new_password) && empty($new_sip_password))) {
                throw new \Exception("Lütfen güncellenecek en az bir şifre alanını doldurun!");
            }

            $up_fields = [];
            if (!empty($new_password)) {
                $up_fields['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            }
            if (!empty($new_sip_password)) {
                $up_fields['sip_password'] = $new_sip_password;
            }
            DBHelper::update('sys_users', $up_fields, 'id', $user_id);

            // Şifrelerin KENDİSİ asla loglanmaz — sadece "hangi şifre türü
            // değişti" bilgisi (web girişi / SIP / ikisi de).
            $changed_kinds = [];
            if (!empty($new_password)) $changed_kinds[] = 'web girişi';
            if (!empty($new_sip_password)) $changed_kinds[] = 'SIP';
            $u_username = DBHelper::fetchColumn("SELECT username FROM sys_users WHERE id = ?", [$user_id]);
            writeAuditLog(null, 'user_account', $user_id, "Kullanıcı: " . ($u_username ?: $user_id) . " (şifre değişti: " . implode('+', $changed_kinds) . ")", 'update', $_SESSION['user_id'] ?? null);

            $u_ext = DBHelper::fetchColumn("SELECT extension FROM sys_users WHERE id = ?", [$user_id]);
            if (!empty($u_ext) && !empty($new_sip_password)) {
                markPendingSync('extensions', 'extension', $u_ext, "Dahili: {$u_ext} (SIP şifresi değişti)", 'update', $_SESSION['user_id'] ?? null);
                return "Kullanıcı şifre ayarları başarıyla güncellendi! SIP şifresinin etkili olması için Uygula sayfasından gönderin.";
            }
            return "Kullanıcı şifre ayarları başarıyla güncellendi!";
        });
    }
}
