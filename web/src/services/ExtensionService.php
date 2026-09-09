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
            // Giden rota grubu: kullanici yalnizca bu gruptaki rotalari
            // kullanabilir. 1 varsayilan; gecersiz deger 1'e dusuruluyor ki
            // kullanici var olmayan bir context'e dusup disari hic arayamaz
            // hale gelmesin.
            $outbound_group = max(1, min(99, intval($data['outbound_group'] ?? 1)));
            $sip_auth_digest = isset($data['sip_auth_digest']) ? (intval($data['sip_auth_digest']) ? 1 : 0) : 1;
            $cid_internal = trim($data['cid_internal'] ?? '');
            $cid_external = trim($data['cid_external'] ?? '');
            $is_active = isset($data['is_active']) ? 1 : 0;

            if (!preg_match('/^\d{3,6}$/', $extension)) {
                throw new \Exception('Dahili numarası 3-6 haneli sayılardan oluşmalıdır!');
            }
            if (empty($full_name)) {
                throw new \Exception('Ad Soyad zorunludur!');
            }
            // Faks kullanıcıları hiç SIP kaydı yapmaz (PJSIP endpoint üretilmez).
            // Auth Digest etkin olan SIP dahililer için şifre zorunludur.
            if ($extension_type === 'sip' && $sip_auth_digest === 1 && strlen($sip_password) < 6) {
                throw new \Exception('SIP şifresi en az 6 karakter olmalıdır!');
            }

            // Dahili numarası benzersiz olmalı
            $stmt = $db->prepare('SELECT id FROM sys_users WHERE extension = ? AND id != ?');
            $stmt->execute([$extension, $user_id]);
            if ($stmt->fetch()) {
                throw new \Exception("{$extension} dahilisi zaten başka bir kayda atanmış!");
            }

            // Save/Update Extension
            if ($user_id > 0) {
                // Mevcut kayıt: eski dahili dosyasını da temizle (numara değişebilir)
                $old_ext = DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$user_id]);
                DBHelper::update('sys_users', [
                    'full_name' => $full_name,
                    'extension' => $extension,
                    'sip_password' => $extension_type === 'sip' ? $sip_password : '',
                    'sip_auth_digest' => $sip_auth_digest,
                    'extension_type' => $extension_type,
                    'outbound_group' => $outbound_group,
                    'cid_internal' => $cid_internal,
                    'cid_external' => $cid_external,
                    'is_active' => $is_active
                ], 'id', $user_id);
                SIPHelper::setSettings($extension, ['auth_digest' => $sip_auth_digest ? 'yes' : 'no']);
                if ($old_ext && $old_ext !== $extension) {
                    SIPHelper::deleteSettings($old_ext);
                    markPendingSync('extensions', 'extension', $old_ext, "Dahili: {$old_ext} (numara değişti, kaldırıldı)", 'delete', $_SESSION['user_id'] ?? null);
                }
                markPendingSync('extensions', 'extension', $extension, "Dahili: {$extension} ({$full_name})", 'update', $_SESSION['user_id'] ?? null);
                $msg = "{$extension} dahili abonesi güncellendi! Etkili olması için Uygula sayfasından gönderin.";
            } else {
                // Yeni dahili abone: cihaz amaçlı minimal sistem kaydı oluştur
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
                    'role' => 'fax_user',
                    'extension' => $extension,
                    'sip_password' => $extension_type === 'sip' ? $sip_password : '',
                    'sip_auth_digest' => $sip_auth_digest,
                    'extension_type' => $extension_type,
                    'outbound_group' => $outbound_group,
                    'cid_internal' => $cid_internal,
                    'cid_external' => $cid_external,
                    'is_active' => $is_active,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                SIPHelper::syncExtensionToSIP($extension, $full_name, $sip_password, $sip_auth_digest);
                markPendingSync('extensions', 'extension', $extension, "Dahili: {$extension} ({$full_name})", 'create', $_SESSION['user_id'] ?? null);
                $msg = "{$extension} dahili abonesi oluşturuldu! Etkili olması için Uygula sayfasından gönderin.";
            }
            return $msg;
        });
    }

    public static function removeExtension($user_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () use ($user_id) {
            $user_id = intval($user_id);
            if ($user_id <= 0) {
                throw new \Exception('Geçersiz kayıt!');
            }
            $old_ext = DBHelper::fetchColumn('SELECT extension FROM sys_users WHERE id = ?', [$user_id]);
            if (empty($old_ext)) {
                throw new \Exception('Bu kayıtta dahili numarası tanımlı değil!');
            }
            // Dahiliyi kaldır (kullanıcı hesabı korunur)
            DBHelper::update('sys_users', ['extension' => null, 'sip_password' => null], 'id', $user_id);
            markPendingSync('extensions', 'extension', $old_ext, "Dahili: {$old_ext} (kaldırıldı)", 'delete', $_SESSION['user_id'] ?? null);
            return "{$old_ext} dahilisi kaldırıldı (kullanıcı hesabı korundu)! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    /**
     * /extensions sayfasındaki "Tüm Dahilileri Yeniden Senkronize Et" butonu —
     * PBXHelper::syncAllExtensions() proxy'si üzerinden ExtensionController'dan
     * çağrılıyor. 2026-08-24 rollout taramasında (grep sadece doğrudan
     * "ExtensionService::syncAll" çağrılarını aradı, PBXHelper indirection
     * katmanını KAÇIRDI) bu gerçek/erişilebilir çağrı yeri gözden kaçmıştı —
     * kullanıcının "unutulmuş özellik bağları var mı" sorusu üzerine bulunup
     * düzeltildi.
     */
    public static function syncAll($csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () {
            markPendingSync('extensions', 'system_setting', 'all_extensions', 'Tüm Dahililer (elle yeniden senkronize)', 'update', $_SESSION['user_id'] ?? null);
            return 'Tüm dahili abone konfigürasyonları işaretlendi! Etkili olması için Uygula sayfasından gönderin.';
        });
    }
}
