<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Reset Password (Şifre Sıfırlama) Service
 */
class ResetPasswordService {
    /**
     * @return array{success:bool, error?:string}
     */
    public static function resetPassword(array $post, ?array $user): array
    {
        $csrf_token = $post['csrf_token'] ?? '';
        $new_password = (string)($post['new_password'] ?? '');
        $confirm_password = (string)($post['confirm_password'] ?? '');

        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => 'Güvenlik doğrulaması (CSRF) başarısız! Lütfen sayfayı yenileyip tekrar deneyin.'];
        }
        if (!$user) {
            return ['success' => false, 'error' => 'Bağlantının süresi dolmuş veya geçersiz. Lütfen tekrar giriş yapmayı deneyip yeni bir sıfırlama bağlantısı isteyin.'];
        }
        if (mb_strlen($new_password) < 8) {
            return ['success' => false, 'error' => 'Yeni şifre en az 8 karakter olmalıdır.'];
        }
        if (strcasecmp($new_password, $user['username']) === 0) {
            return ['success' => false, 'error' => 'Yeni şifre kullanıcı adınızla aynı olamaz.'];
        }
        if ($new_password !== $confirm_password) {
            return ['success' => false, 'error' => 'Şifreler birbiriyle eşleşmiyor.'];
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $db = getDB();
        $upd = $db->prepare('UPDATE sys_users SET password_hash = ?, must_reset_password = 0, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $upd->execute([$new_hash, $user['id']]);
        unset($_SESSION['pending_reset_user_id']);

        // Kimlik bilgisi değişikliği güvenlik açısından önemli bir olay ama
        // hiçbir yerde iz bırakmıyordu (2026-08-31 denetiminde bulundu —
        // admin'in yaptığı şifre sıfırlama UserService'te zaten loglanıyor,
        // eksik olan self-servis akıştı). user_id NULL: oturum yok, işlemi
        // token sahibi kullanıcı kendisi yapıyor (entity_id o kullanıcı).
        writeAuditLog(null, 'system_users', $user['id'], "Şifre self-servis olarak sıfırlandı: " . ($user['username'] ?? '?'), 'password_reset', null);

        return ['success' => true];
    }
}
