<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Fax Inbox (Gelen Fakslar) Service
 */
class FaxInboxService {
    /**
     * Bir gelen faks kaydını (ve varsa fiziksel PDF/TIFF dosyalarını) siler.
     * Sahiplik kontrolü: admin olmayan bir kullanıcı sadece kendi biriminin
     * (did_extension) faksını silebilir — aynı sayfadaki listeleme sorgusuyla
     * aynı kısıtlama (aksi halde fax_id tahmin ederek başka birimin faksı
     * silinebilirdi). Önceden fax_inbox.php'nin içine gömülüydü; MVC göçü
     * sırasında (2026-08-22) buraya taşındı, mantık DEĞİŞTİRİLMEDİ.
     */
    public static function deleteFax($faxId, $csrfToken, string $userRole, string $userExt, int $userId = 0): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }

        $fax_id = intval($faxId);
        if ($fax_id <= 0) {
            return ['success' => true, 'message' => ''];
        }

        $db = getDB();
        $stmt = $db->prepare("SELECT pdf_path, tif_path, caller_id, did_extension, received_at FROM fax_received WHERE id = ?");
        $stmt->execute([$fax_id]);
        $fax = $stmt->fetch();

        if (!$fax) {
            return ['success' => false, 'error' => 'Bu faks kaydına erişim yetkiniz yok veya kayıt bulunamadı.'];
        }

        if ($userRole !== 'admin') {
            require_once __DIR__ . '/../repositories/FaxInboxRepository.php';
            $allowedDids = FaxInboxRepository::getAllowedDIDs($userId, $userExt);
            if (!in_array($fax['did_extension'], $allowedDids, true)) {
                return ['success' => false, 'error' => 'Bu faks kaydına erişim yetkiniz yok.'];
            }
        }

        if (!empty($fax['pdf_path']) && file_exists($fax['pdf_path'])) @unlink($fax['pdf_path']);
        if (!empty($fax['tif_path']) && file_exists($fax['tif_path'])) @unlink($fax['tif_path']);

        $db->prepare("DELETE FROM fax_received WHERE id = ?")->execute([$fax_id]);

        writeAuditLog(null, 'fax_inbox', $fax_id, "Gelen Faks: " . ($fax['caller_id'] ?? '?') . " -> " . ($fax['did_extension'] ?? '?') . " (" . ($fax['received_at'] ?? '') . ", silindi)", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => 'Gelen faks kaydı silindi.'];
    }
}
