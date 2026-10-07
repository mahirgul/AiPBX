<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Fax Inbox (Gelen Fakslar) Service
 */
class FaxInboxService {
    /**
     * Deletes an incoming fax record (and its physical PDF/TIFF files, if any).
     * Ownership check: a non-admin user can only delete faxes of their own
     * unit (did_extension) — the same restriction as the list query on the
     * same page (otherwise another unit's fax could be deleted by guessing
     * fax_id). It used to be embedded in fax_inbox.php; moved here during the
     * MVC migration (2026-08-22), logic UNCHANGED.
     */
    public static function deleteFax($faxId, $csrfToken, string $userRole, string $userExt, int $userId = 0): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
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
            return ['success' => false, 'error' => t('srv_fax.err_access_nf')];
        }

        if ($userRole !== 'admin') {
            require_once __DIR__ . '/../repositories/FaxInboxRepository.php';
            $allowedDids = FaxInboxRepository::getAllowedDIDs($userId, $userExt);
            if (!in_array($fax['did_extension'], $allowedDids, true)) {
                return ['success' => false, 'error' => t('srv_fax.err_access')];
            }
        }

        if (!empty($fax['pdf_path']) && file_exists($fax['pdf_path'])) @unlink($fax['pdf_path']);
        if (!empty($fax['tif_path']) && file_exists($fax['tif_path'])) @unlink($fax['tif_path']);

        $db->prepare("DELETE FROM fax_received WHERE id = ?")->execute([$fax_id]);

        writeAuditLog(null, 'fax_inbox', $fax_id, "Incoming fax: " . ($fax['caller_id'] ?? '?') . " -> " . ($fax['did_extension'] ?? '?') . " (" . ($fax['received_at'] ?? '') . ", silindi)", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('srv_fax.in_deleted')];
    }
}
