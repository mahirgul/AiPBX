<?php

class ForceResetRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    public static function findPendingResetUser(int $userId): ?array
    {
        $stmt = static::db()->prepare('SELECT id, username, email, must_reset_password FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
