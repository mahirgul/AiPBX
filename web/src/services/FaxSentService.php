<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/FaxSendService.php';

/**
 * Fax Sent (Giden Fakslar) Service
 */
class FaxSentService {
    /**
     * Deletes an outgoing fax record (and its physical PDF file, if any).
     * Ownership check: a non-admin user can only delete a fax they sent
     * themselves — the same restriction as the list query on the same page
     * (otherwise another user's fax could be deleted by guessing fax_id).
     * It used to be embedded in fax_sent.php; moved here during the MVC
     * migration (2026-08-22), logic UNCHANGED.
     */
    public static function deleteSentFax($faxId, $csrfToken, string $userRole, int $userId): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }

        $fax_id = intval($faxId);
        if ($fax_id <= 0) {
            return ['success' => true, 'message' => ''];
        }

        $db = getDB();
        if ($userRole === 'admin') {
            $stmt = $db->prepare("SELECT pdf_path, tif_path, sender_extension, destination_number, created_at FROM fax_sent WHERE id = ?");
            $stmt->execute([$fax_id]);
        } else {
            $stmt = $db->prepare("SELECT pdf_path, tif_path, sender_extension, destination_number, created_at FROM fax_sent WHERE id = ? AND user_id = ?");
            $stmt->execute([$fax_id, $userId]);
        }
        $fax = $stmt->fetch();

        if (!$fax) {
            return ['success' => false, 'error' => t('srv_fax.err_access_nf')];
        }

        // Physical files are deleted ONLY when no other record uses the same
        // path: resendFax() copies the SAME pdf/tif path into the new row, and
        // a blind unlink would break the other record's preview/download. The
        // .tif also used to be never deleted (with fax_retention_days=0 the
        // cron does not collect it either) — a permanent disk leak (found in
        // the 2026-08-31 audit).
        foreach (['pdf_path', 'tif_path'] as $path_col) {
            $path = $fax[$path_col] ?? '';
            if ($path === '' || !file_exists($path)) continue;
            $ref_stmt = $db->prepare("SELECT COUNT(*) FROM fax_sent WHERE id != ? AND (pdf_path = ? OR tif_path = ?)");
            $ref_stmt->execute([$fax_id, $path, $path]);
            if ((int) $ref_stmt->fetchColumn() === 0) {
                @unlink($path);
            }
        }

        if ($userRole === 'admin') {
            $db->prepare("DELETE FROM fax_sent WHERE id = ?")->execute([$fax_id]);
        } else {
            $db->prepare("DELETE FROM fax_sent WHERE id = ? AND user_id = ?")->execute([$fax_id, $userId]);
        }

        writeAuditLog(null, 'fax_sent', $fax_id, "Sent fax: " . ($fax['sender_extension'] ?? '?') . " -> " . ($fax['destination_number'] ?? '?') . " (" . ($fax['created_at'] ?? '') . ", silindi)", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('srv_fax.out_deleted')];
    }

    /**
     * Queues a FAILED outgoing fax again, reusing the ALREADY generated TIFF
     * file — the PDF->TIFF conversion is not repeated, only a new Asterisk
     * .call file is generated. The original failed record (including its
     * error message) is NOT DELETED/OVERWRITTEN — a new fax_sent row is opened
     * so it stays as history (2026-08-31, user request: "resend on
     * timeout/error"). Same ownership check as deleteSentFax(): a non-admin
     * user can only resend a fax they sent themselves.
     */
    public static function resendFax($faxId, $csrfToken, string $userRole, int $userId): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }

        $fax_id = intval($faxId);
        if ($fax_id <= 0) {
            return ['success' => false, 'error' => t('srv_fax.err_record')];
        }

        $db = getDB();
        if ($userRole === 'admin') {
            $stmt = $db->prepare("SELECT * FROM fax_sent WHERE id = ?");
            $stmt->execute([$fax_id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM fax_sent WHERE id = ? AND user_id = ?");
            $stmt->execute([$fax_id, $userId]);
        }
        $fax = $stmt->fetch();

        if (!$fax) {
            return ['success' => false, 'error' => t('srv_fax.err_access_nf')];
        }
        if ($fax['status'] !== 'FAILED') {
            return ['success' => false, 'error' => t('srv_fax.err_resend_failed_only')];
        }
        if (empty($fax['tif_path']) || !file_exists($fax['tif_path'])) {
            return ['success' => false, 'error' => t('srv_fax.err_source_gone')];
        }
        if (AsteriskHelper::getPrimaryTrunkName() === null) {
            return ['success' => false, 'error' => t('srv_fax.err_no_trunk')];
        }

        $stmt2 = $db->prepare('INSERT INTO fax_sent (user_id, sender_extension, destination_number, pdf_path, tif_path, pages, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "PENDING", NOW())');
        $stmt2->execute([$fax['user_id'], $fax['sender_extension'], $fax['destination_number'], $fax['pdf_path'], $fax['tif_path'], $fax['pages']]);
        $new_fax_id = $db->lastInsertId();

        try {
            FaxSendService::submitCallFile((int)$new_fax_id, $fax['destination_number'], $fax['sender_extension'], $fax['tif_path']);
        } catch (\Exception $e) {
            $db->prepare("UPDATE fax_sent SET status = 'FAILED', error_message = ?, completed_at = NOW() WHERE id = ?")
               ->execute([$e->getMessage(), $new_fax_id]);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        writeAuditLog(null, 'fax_sent', $new_fax_id, "Fax resent: " . $fax['sender_extension'] . " -> " . $fax['destination_number'] . " (original #$fax_id)", 'resend', $_SESSION['user_id'] ?? null);

        return ['success' => true, 'message' => sprintf(t('srv_fax.requeued'), $new_fax_id)];
    }
}
