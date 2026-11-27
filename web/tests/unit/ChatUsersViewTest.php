<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';

/**
 * The chat service reads users through v_chat_users, not sys_users: the view's columns are the contract between the two.
 * Every column below is read by chat/*.go; a column added to sys_users reaches chat only when it is added here on purpose.
 */
final class ChatUsersViewTest extends TestCase
{
    private const COLUMNS = ['id', 'username', 'extension', 'full_name', 'email', 'role', 'is_active', 'is_device', 'extension_type', 'token_epoch'];

    protected function setUp(): void
    {
        Fixtures::load();
    }

    public function testTheViewHoldsExactlyWhatChatReads(): void
    {
        $cols = getDB()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_chat_users' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(self::COLUMNS, $cols);
        $view = (string) getDB()->query("SELECT SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_chat_users'")->fetchColumn();
        $this->assertSame('INVOKER', $view, 'the view must not give chat more than its own database user may read');
    }

    public function testChatSourceNamesNoUserTableButTheView(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/chat/*.go');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            if (str_ends_with($file, '_test.go')) {
                continue;
            }
            $this->assertStringNotContainsString('sys_users', (string) file_get_contents($file), basename($file) . ' reads sys_users directly');
        }
    }

    public function testViewRowsFollowTheUserTable(): void
    {
        $db = getDB();
        $row = $db->query("SELECT id, extension, role FROM sys_users WHERE extension IS NOT NULL AND extension != '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'the fixtures hold a user with an extension');
        $st = $db->prepare('SELECT extension, role FROM v_chat_users WHERE id = ?');
        $st->execute([$row['id']]);
        $this->assertSame([(string) $row['extension'], (string) $row['role']], array_map('strval', array_values((array) $st->fetch(PDO::FETCH_ASSOC))));
    }
}
