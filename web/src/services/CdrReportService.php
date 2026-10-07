<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * CDR (Call Detail Record) Report Service
 */

class CdrReportService {
    /**
     * Deletes a CDR record (and its physical audio file, if any). It used to
     * be embedded in cdr_reports.php; moved here during the MVC migration
     * (2026-08-22), logic UNCHANGED — unlike the PBXHelper::handleAction()
     * flow on the other pages, this page kept rendering normally even on
     * failure (NO redirect/exit); that behaviour is kept through the
     * true/false return value.
     */
    public static function deleteCdr($del_id, $csrf_token, bool $canDeleteCdr): bool
    {
        if (!$canDeleteCdr) {
            notify(t('srv_cdr.err_no_delete'), 'danger');
            return false;
        }
        if (!verifyCSRFToken($csrf_token)) {
            notify(t('common.invalid_csrf'), 'danger');
            return false;
        }

        $del_id = intval($del_id);
        $cdr = DBHelper::fetchOne("SELECT recording_path, caller_num, start_time, coalesce(nullif(linkedid, ''), call_id) AS linkedid FROM cdrs WHERE id = ?", [$del_id]);
        $rec_path = $cdr['recording_path'] ?? null;

        if ($rec_path && file_exists($rec_path)) {
            @unlink($rec_path);
        }

        $linkId = $cdr['linkedid'] ?? null;
        $db = getDB();
        if (!empty($linkId)) {
            $stmt = $db->prepare("DELETE FROM asteriskcdr WHERE linkedid = ? OR uniqueid = ?");
            $stmt->execute([$linkId, $linkId]);
        } else {
            DBHelper::delete('asteriskcdr', 'id', $del_id);
        }
        writeAuditLog(null, 'cdr', $del_id, "Call record: " . ($cdr['caller_num'] ?? $del_id) . " (" . ($cdr['start_time'] ?? '') . ", deleted)", 'delete', $_SESSION['user_id'] ?? null);
        notify(t('srv_cdr.deleted'), 'success');
        return true;
    }
}
