<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Fax Settings (Faks Birimleri / DID Eşleme) Service
 */
class FaxSettingsService {
    public static function saveDidMapping(array $data): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $did_id = intval($data['did_id'] ?? 0);
        $dept = trim($data['department_name'] ?? '');
        $email = trim($data['notification_email'] ?? '');
        $did_extension = trim($data['did_extension'] ?? '');
        $assigned_user_id = intval($data['assigned_user_id'] ?? 0) ?: null;
        $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

        if (empty($dept)) {
            return ['success' => false, 'error' => 'Lütfen Bölüm / Birim adını girin!'];
        }

        try {
            $db = getDB();
            $uid = $_SESSION['user_id'] ?? null;
            if ($did_id > 0) {
                $stmt = $db->prepare('UPDATE sys_did_mappings SET department_name = ?, notification_email = ?, did_extension = ?, assigned_user_id = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$dept, $email, $did_extension ?: null, $assigned_user_id, $is_active, $did_id]);
                writeAuditLog(null, 'did_mapping', $did_id, "Faks Birimi: {$dept}", 'update', $uid);
                return ['success' => true, 'message' => "Faks Birimi '$dept' başarıyla güncellendi!"];
            }
            $stmt = $db->prepare('INSERT INTO sys_did_mappings (department_name, notification_email, did_extension, assigned_user_id, is_active) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$dept, $email, $did_extension ?: null, $assigned_user_id, $is_active]);
            writeAuditLog(null, 'did_mapping', $db->lastInsertId(), "Faks Birimi: {$dept}", 'create', $uid);
            return ['success' => true, 'message' => "Yeni Faks Birimi '$dept' eklendi!"];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => "Hata: " . $e->getMessage()];
        }
    }

    public static function deleteDidMapping($didId, $csrfToken): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $did_id = intval($didId);
        if ($did_id > 0) {
            $dept = DBHelper::fetchColumn("SELECT department_name FROM sys_did_mappings WHERE id = ?", [$did_id]);
            DBHelper::delete('sys_did_mappings', 'id', $did_id);
            writeAuditLog(null, 'did_mapping', $did_id, "Faks Birimi: " . ($dept ?: $did_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            return ['success' => true, 'message' => 'Faks Birim kaydı silindi!'];
        }
        return ['success' => true, 'message' => ''];
    }
}
