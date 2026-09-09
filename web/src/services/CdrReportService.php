<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * CDR (Call Detail Record) Report Service
 */

class CdrReportService {
    /**
     * Bir CDR kaydını (ve varsa fiziksel ses dosyasını) siler. Önceden
     * cdr_reports.php'nin içine gömülüydü; MVC göçü sırasında (2026-08-22)
     * buraya taşındı, mantık DEĞİŞTİRİLMEDİ — diğer sayfalardaki
     * PBXHelper::handleAction() akışından farklı olarak, bu sayfa başarısızlıkta
     * bile normal render'a devam ediyordu (redirect/exit YOK), bu davranış
     * true/false dönüş değeriyle korunuyor.
     */
    public static function deleteCdr($del_id, $csrf_token, bool $canDeleteCdr): bool
    {
        if (!$canDeleteCdr) {
            notify('Çağrı kaydı silme yetkiniz bulunmamaktadır!', 'danger');
            return false;
        }
        if (!verifyCSRFToken($csrf_token)) {
            notify('Güvenlik doğrulaması başarısız!', 'danger');
            return false;
        }

        $del_id = intval($del_id);
        $cdr = DBHelper::fetchOne("SELECT recording_path, caller_num, start_time FROM cdrs WHERE id = ?", [$del_id]);
        $rec_path = $cdr['recording_path'] ?? null;

        if ($rec_path && file_exists($rec_path)) {
            @unlink($rec_path);
        }

        DBHelper::delete('cdrs', 'id', $del_id);
        writeAuditLog(null, 'cdr', $del_id, "Çağrı Kaydı: " . ($cdr['caller_num'] ?? $del_id) . " (" . ($cdr['start_time'] ?? '') . ", silindi)", 'delete', $_SESSION['user_id'] ?? null);
        notify('Çağrı kaydı ve ses dosyası başarıyla silindi.', 'success');
        return true;
    }
}
