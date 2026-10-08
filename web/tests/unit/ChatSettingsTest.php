<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/src/services/ChatSettingsService.php';

/**
 * The admin setting "messages can be deleted for N minutes" that the chat
 * service reads on every delete (chat/message_delete.go).
 */
final class ChatSettingsTest extends TestCase
{
    private ?string $before = null;

    protected function setUp(): void
    {
        $stmt = getDB()->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        $stmt->execute([ChatSettingsService::DELETE_WINDOW_KEY]);
        $v = $stmt->fetchColumn();
        $this->before = $v === false ? null : (string) $v;
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([ChatSettingsService::DELETE_WINDOW_KEY]);
        if ($this->before !== null) {
            $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)')
               ->execute([ChatSettingsService::DELETE_WINDOW_KEY, $this->before]);
        }
    }

    public function testMissingSettingMeansNoLimit(): void
    {
        getDB()->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([ChatSettingsService::DELETE_WINDOW_KEY]);
        $this->assertSame(0, ChatSettingsService::deleteWindowMinutes());
    }

    public function testValidValuesAreSaved(): void
    {
        foreach (['15', '0', ' 60 ', (string) ChatSettingsService::DELETE_WINDOW_MAX] as $value) {
            $res = ChatSettingsService::saveDeleteWindow($value);
            $this->assertTrue($res['success'], "'{$value}' should be accepted");
            $this->assertSame((int) trim($value), ChatSettingsService::deleteWindowMinutes());
        }
    }

    public function testInvalidValuesAreRejected(): void
    {
        ChatSettingsService::saveDeleteWindow('30');
        foreach (['', '-5', '1.5', 'abc', '10 minutes', (string) (ChatSettingsService::DELETE_WINDOW_MAX + 1)] as $value) {
            $res = ChatSettingsService::saveDeleteWindow($value);
            $this->assertFalse($res['success'], "'{$value}' should be rejected");
            $this->assertNotEmpty($res['error']);
        }
        $this->assertSame(30, ChatSettingsService::deleteWindowMinutes(), 'a rejected value must not change the setting');
    }
}
