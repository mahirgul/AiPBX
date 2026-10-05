<?php

class ResetPasswordRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    /**
     * All unexpired candidate tokens are fetched and compared in constant time
     * with hash_equals() — the SQL WHERE match (the old version) was not
     * constant-time. It used to be a standalone function inside reset_password.php.
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
