<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/FaxSendService.php';

/**
 * Fax Sent (Giden Fakslar) Service
 */
class FaxSentService {
    /**
     * Bir giden faks kaydını (ve varsa fiziksel PDF dosyasını) siler.
     * Sahiplik kontrolü: admin olmayan bir kullanıcı sadece kendi gönderdiği
     * faksı silebilir — aynı sayfadaki listeleme sorgusuyla aynı kısıtlama
     * (aksi halde fax_id tahmin ederek başka kullanıcının faksı silinebilirdi).
     * Önceden fax_sent.php'nin içine gömülüydü; MVC göçü sırasında
     * (2026-08-22) buraya taşındı, mantık DEĞİŞTİRİLMEDİ.
     */
    public static function deleteSentFax($faxId, $csrfToken, string $userRole, int $userId): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
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
            return ['success' => false, 'error' => 'Bu faks kaydına erişim yetkiniz yok veya kayıt bulunamadı.'];
        }

        // Fiziksel dosyalar SADECE başka bir kayıt aynı yolu kullanmıyorsa silinir:
        // resendFax() yeni satıra AYNI pdf/tif yolunu kopyalıyor, körü körüne
        // unlink edilirse diğer kaydın önizleme/indirmesi bozulurdu. Ayrıca .tif
        // önceden hiç silinmiyordu (fax_retention_days=0 olduğu için cron da
        // toplamıyor) — kalıcı disk sızıntısıydı (2026-08-31 denetiminde bulundu).
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

        writeAuditLog(null, 'fax_sent', $fax_id, "Giden Faks: " . ($fax['sender_extension'] ?? '?') . " -> " . ($fax['destination_number'] ?? '?') . " (" . ($fax['created_at'] ?? '') . ", silindi)", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => 'Giden faks kaydı silindi.'];
    }

    /**
     * Başarısız (FAILED) bir giden faksı, ZATEN üretilmiş TIFF dosyasını
     * yeniden kullanarak tekrar gönderim kuyruğuna ekler — PDF->TIFF
     * dönüşümü tekrarlanmaz, sadece yeni bir Asterisk .call dosyası üretilir.
     * Orijinal başarısız kayıt (hata mesajı dahil) SİLİNMEZ/ÜZERİNE
     * YAZILMAZ — tarihçe olarak korunsun diye yeni bir fax_sent satırı açılır
     * (2026-08-31, kullanıcı isteği: "timeout/hata durumunda yeniden gönder").
     * Sahiplik kontrolü deleteSentFax() ile aynı: admin olmayan kullanıcı
     * sadece kendi gönderdiği faksı yeniden gönderebilir.
     */
    public static function resendFax($faxId, $csrfToken, string $userRole, int $userId): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }

        $fax_id = intval($faxId);
        if ($fax_id <= 0) {
            return ['success' => false, 'error' => 'Geçersiz faks kaydı.'];
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
            return ['success' => false, 'error' => 'Bu faks kaydına erişim yetkiniz yok veya kayıt bulunamadı.'];
        }
        if ($fax['status'] !== 'FAILED') {
            return ['success' => false, 'error' => 'Sadece başarısız (FAILED) faks kayıtları yeniden gönderilebilir.'];
        }
        if (empty($fax['tif_path']) || !file_exists($fax['tif_path'])) {
            return ['success' => false, 'error' => 'Kaynak faks dosyası artık sunucuda bulunamadığı için yeniden gönderilemiyor.'];
        }
        if (AsteriskHelper::getPrimaryTrunkName() === null) {
            return ['success' => false, 'error' => 'Tanımlı/aktif bir dış hat (trunk) bulunamadı. Faks gönderebilmek için önce Dış Hat Ayarları\'ndan bir trunk tanımlamalısınız.'];
        }

        $stmt2 = $db->prepare('INSERT INTO fax_sent (user_id, sender_extension, destination_number, pdf_path, tif_path, pages, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "PENDING", NOW())');
        $stmt2->execute([$fax['user_id'], $fax['sender_extension'], $fax['destination_number'], $fax['pdf_path'], $fax['tif_path'], $fax['pages']]);
        $new_fax_id = $db->lastInsertId();

        FaxSendService::submitCallFile($new_fax_id, $fax['destination_number'], $fax['sender_extension'], $fax['tif_path']);

        writeAuditLog(null, 'fax_sent', $new_fax_id, "Giden Faks yeniden gönderildi: " . $fax['sender_extension'] . " -> " . $fax['destination_number'] . " (orijinal kayıt #$fax_id)", 'resend', $_SESSION['user_id'] ?? null);

        return ['success' => true, 'message' => "Faks yeniden gönderim kuyruğuna eklendi! (Yeni İşlem ID: #$new_fax_id)"];
    }
}
