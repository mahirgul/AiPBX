<?php
/**
 * PBX Master Facade Suite
 * Delegates business logic to domain-specific services in src/services/
 */

require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/file_helper.php';
require_once __DIR__ . '/asterisk_helper.php';
require_once __DIR__ . '/asterisk_sync.php';

// Require Domain Services
require_once __DIR__ . '/services/UserService.php';
require_once __DIR__ . '/services/TrunkService.php';
require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/IVRService.php';
require_once __DIR__ . '/services/TimeConditionService.php';
require_once __DIR__ . '/services/RouteService.php';
require_once __DIR__ . '/services/SoundService.php';
require_once __DIR__ . '/services/ExtensionService.php';
require_once __DIR__ . '/services/FeatureCodeService.php';
require_once __DIR__ . '/services/HangupActionService.php';

class PBXHelper {
    /**
     * Unified Form Action Handler with CSRF Check & Exception Safety
     */
    public static function handleAction($csrf_token, callable $action) {
        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }
        try {
            $msg = $action();
            return ['success' => true, 'message' => is_string($msg) ? $msg : 'İşlem başarıyla tamamlandı!'];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Hata: ' . $e->getMessage()];
        }
    }

    // User Service Proxies
    public static function saveUser($data) { return UserService::saveUser($data); }
    public static function deleteUser($id, $csrf) { return UserService::deleteUser($id, $csrf); }
    public static function resetUserPassword($data) { return UserService::resetPassword($data); }

    // Trunk Service Proxies
    public static function saveTrunk($data) { return TrunkService::saveTrunk($data); }
    public static function deleteTrunk($id, $csrf) { return TrunkService::deleteTrunk($id, $csrf); }

    // Queue Service Proxies
    public static function saveQueue($data) { return QueueService::saveQueue($data); }
    public static function deleteQueue($id, $csrf) { return QueueService::deleteQueue($id, $csrf); }

    // IVR Service Proxies
    public static function saveIVR($data) { return IVRService::saveIVR($data); }
    public static function deleteIVR($id, $csrf) { return IVRService::deleteIVR($id, $csrf); }
    public static function saveIVREntry($data) { return IVRService::saveIVREntry($data); }
    public static function deleteIVREntry($id, $csrf) { return IVRService::deleteIVREntry($id, $csrf); }

    // Time Condition Service Proxies
    public static function saveTimeCondition($data) { return TimeConditionService::saveTimeCondition($data); }
    public static function deleteTimeCondition($id, $csrf) { return TimeConditionService::deleteTimeCondition($id, $csrf); }

    // Route Service Proxies
    public static function saveDIDRoute($data) { return RouteService::saveDIDRoute($data); }
    public static function deleteDIDRoute($id, $csrf) { return RouteService::deleteDIDRoute($id, $csrf); }
    public static function saveOutboundRoute($data) { return RouteService::saveOutboundRoute($data); }
    public static function deleteOutboundRoute($id, $csrf) { return RouteService::deleteOutboundRoute($id, $csrf); }

    // Sound Service Proxies
    public static function uploadAnnouncement($data, $files) { return SoundService::uploadAnnouncement($data, $files); }
    public static function saveAnnouncement($data, $files = []) { return SoundService::saveAnnouncement($data, $files); }
    public static function deleteAnnouncement($id, $csrf) { return SoundService::deleteAnnouncement($id, $csrf); }
    public static function saveMOHClass($data) { return SoundService::saveMOHClass($data); }
    public static function deleteMOHClass($id, $csrf) { return SoundService::deleteMOHClass($id, $csrf); }
    public static function uploadMOHFile($data, $files, $csrf) { return SoundService::uploadMOHFile($data, $files, $csrf); }
    public static function saveTimeGroup($data) { return TimeConditionService::saveTimeGroup($data); }
    public static function deleteTimeGroup($id, $csrf) { return TimeConditionService::deleteTimeGroup($id, $csrf); }

    // Extension Service Proxies
    public static function saveExtension($data) { return ExtensionService::saveExtension($data); }
    public static function removeExtension($id, $csrf) { return ExtensionService::removeExtension($id, $csrf); }
    public static function syncAllExtensions($csrf) { return ExtensionService::syncAll($csrf); }

    // Feature Code Service Proxies
    public static function saveFeatureCode($data, array $validRoleKeys) { return FeatureCodeService::saveFeatureCode($data, $validRoleKeys); }
    public static function toggleFeatureCodeStatus($id, $csrf) { return FeatureCodeService::toggleStatus($id, $csrf); }

    // Hangup Action Service Proxies
    public static function saveHangupAction($data) { return HangupActionService::saveHangupAction($data); }
    public static function deleteHangupAction($id, $csrf) { return HangupActionService::deleteHangupAction($id, $csrf); }

    /**
     * Unified Toggle Active/Passive Status
     */
    public static function toggleStatus($table, $id, $csrf_token) {
        return self::handleAction($csrf_token, function() use ($table, $id) {
            // domain: PENDING_SYNC_DOMAIN_MAP anahtarı (2026-08-24'e kadar bu
            // fonksiyon syncXxx()'i DOĞRUDAN/ANINDA çağırıyordu — 11 Service
            // dosyasının ertelenmiş-reload sistemine taşındığı rollout'ta
            // (2026-08-24) bu genel-amaçlı helper's src/helpers.php'de yaşadığı
            // için gözden kaçmıştı, kullanıcının audit-log genişletme isteği
            // sırasında bulunup aynı sisteme taşındı. label_col: entity_label
            // için okunacak kolon.
            $allowed_tables = [
                'sys_users' => ['domain' => 'extensions', 'label_col' => 'full_name', 'entity_type' => 'extension'],
                'pbx_trunks' => ['domain' => 'trunks', 'label_col' => 'title', 'entity_type' => 'trunk'],
                'pbx_dids' => ['domain' => 'inbound_dialplan', 'label_col' => 'title', 'entity_type' => 'did_route'],
                'pbx_outbound_routes' => ['domain' => 'outbound_dialplan', 'label_col' => 'route_name', 'entity_type' => 'outbound_route'],
                'pbx_queues' => ['domain' => 'queues', 'label_col' => 'title', 'entity_type' => 'queue', 'has_internal_number' => true],
                'pbx_time_conditions' => ['domain' => 'time_conditions', 'label_col' => 'title', 'entity_type' => 'time_condition', 'has_internal_number' => true],
                'pbx_ivrs' => ['domain' => 'ivrs', 'label_col' => 'title', 'entity_type' => 'ivr', 'has_internal_number' => true],
                'pbx_announcements' => ['domain' => 'inbound_dialplan', 'label_col' => 'title', 'entity_type' => 'announcement', 'has_internal_number' => true],
                'pbx_time_groups' => ['domain' => 'time_conditions', 'label_col' => 'title', 'entity_type' => 'time_group'],
                'sys_did_mappings' => ['domain' => 'inbound_dialplan', 'label_col' => 'department_name', 'entity_type' => 'did_mapping'],
            ];
            if (!isset($allowed_tables[$table])) {
                throw new \Exception("Geçersiz tablo adı!");
            }
            $id = intval($id);
            $meta = $allowed_tables[$table];
            $row = DBHelper::fetchOne("SELECT is_active, {$meta['label_col']} AS label" . ($table === 'sys_users' ? ', extension' : '') . " FROM {$table} WHERE id = ?", [$id]);
            if (!$row || $row['is_active'] === null) {
                throw new \Exception("Kayıt bulunamadı!");
            }
            $current = $row['is_active'];
            $new_status = ($current == 1) ? 0 : 1;

            // sys_users + pasife alma: son aktif admin hesabı pasife alınırsa,
            // roles.php/system_users.php (auth.php'nin admin-only circuit-breaker'ı
            // yüzünden) kimseye açılmaz hale gelir — deleteUser()'daki aynı korumanın
            // eşdeğeri burada da uygulanıyor (bkz. UserService::deleteUser()).
            if ($table === 'sys_users' && $new_status === 0) {
                $target_role = DBHelper::fetchColumn("SELECT role FROM sys_users WHERE id = ?", [$id]);
                if ($target_role === 'admin') {
                    $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$id]);
                    if (intval($other_admins) < 1) {
                        throw new \Exception("Sistemdeki son aktif admin hesabı pasife alınamaz! Önce başka bir kullanıcıyı admin yapın.");
                    }
                }
            }

            DBHelper::update($table, ['is_active' => $new_status], 'id', $id);

            $status_text = ($new_status == 1) ? 'Aktif' : 'Pasif';
            // sys_users özel durumu: dahilisi olmayan bir kullanıcının (ör. saf
            // ofis personeli) aktif/pasif durumu PJSIP config'ini hiç etkilemiyor
            // — gereksiz yere "extensions" domain'ini kirletmeyelim.
            if ($table !== 'sys_users' || !empty($row['extension'])) {
                markPendingSync($meta['domain'], $meta['entity_type'], $id, ($row['label'] ?: $id) . " ({$status_text})", 'update', $_SESSION['user_id'] ?? null);
                // Pasife alinan bir hedefin numarasi da dialplan'dan dusmeli
                // (SyncInternalNumbers yalnizca is_active=1 satirlari yazar).
                if (!empty($meta['has_internal_number'])) {
                    $num = DBHelper::fetchColumn("SELECT internal_number FROM {$table} WHERE id = ?", [$id]);
                    if (!empty($num)) {
                        markPendingSync('internal_numbers', $meta['entity_type'], $id,
                            ($row['label'] ?: $id) . " ({$status_text}, dahili {$num})",
                            'update', $_SESSION['user_id'] ?? null);
                    }
                }
                if ($table === 'pbx_outbound_routes') {
                    markPendingSync('ivrs', $meta['entity_type'], $id, ($row['label'] ?: $id) . " ({$status_text})", 'update', $_SESSION['user_id'] ?? null);
                }
                return "Kayıt durumu {$status_text} olarak güncellendi! Etkili olması için Uygula sayfasından gönderin.";
            }
            return "Kayıt durumu {$status_text} olarak güncellendi!";
        });
    }
}

