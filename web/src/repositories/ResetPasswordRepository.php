<?php

class ResetPasswordRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    /**
     * Süresi geçmemiş tüm aday token'lar çekilip hash_equals() ile zaman-sabit
     * karşılaştırılıyor — SQL WHERE eşleşmesi (önceki hali) sabit-zamanlı
     * değildi. Önceden reset_password.php'nin içinde bağımsız bir fonksiyondu.
     */
    public static function lookupResetUser(string $token): ?array
    {
        if ($token === '') return null;
        $stmt = static::db()->query("SELECT id, username, email, reset_token FROM sys_users WHERE reset_token IS NOT NULL AND reset_token_expires > NOW()");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (hash_equals((string)$row['reset_token'], $token)) {
                unset($row['reset_token']);
                return $row;
            }
        }
        return null;
    }
}
