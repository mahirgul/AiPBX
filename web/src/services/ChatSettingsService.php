<?php

/**
 * Chat settings an administrator can change on the chat page.
 *
 * chat_delete_window_minutes: how many minutes after sending the sender can
 * still delete a message; 0 (or missing) = always. The chat service reads it
 * on every delete (chat/message_delete.go).
 */
class ChatSettingsService
{
    public const DELETE_WINDOW_KEY = 'chat_delete_window_minutes';
    /** Upper limit of the setting: 30 days. */
    public const DELETE_WINDOW_MAX = 43200;

    public static function deleteWindowMinutes(): int
    {
        $stmt = getDB()->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([self::DELETE_WINDOW_KEY]);
        $v = $stmt->fetchColumn();
        return ($v !== false && ctype_digit(trim((string) $v))) ? min((int) $v, self::DELETE_WINDOW_MAX) : 0;
    }

    /** @return array{success: bool, message?: string, error?: string} */
    public static function saveDeleteWindow($value): array
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value) || (int) $value > self::DELETE_WINDOW_MAX) {
            return ['success' => false, 'error' => sprintf(t('chat.settings_err_window'), self::DELETE_WINDOW_MAX)];
        }
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::DELETE_WINDOW_KEY, (string) (int) $value]);
        writeAuditLog('chat', 'settings', 0, 'Chat: delete window ' . (int) $value . ' min', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('chat.settings_saved')];
    }
}
