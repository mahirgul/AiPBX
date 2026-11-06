<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/db_helper.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/UserService.php';
require_once dirname(__DIR__, 2) . '/auth.php';

/** #156: the installer's "admin" can be deleted like any administrator, but nobody deletes their own account. */
final class UserDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'test';
        $this->clean();
        $db = getDB();
        foreach (['zzdel_a', 'zzdel_b'] as $u) {
            $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, is_active) VALUES (?, 'x', ?, 'admin', 1)")->execute([$u, $u]);
        }
    }

    protected function tearDown(): void
    {
        $this->clean();
        unset($_SESSION['user_id']);
    }

    private function clean(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username LIKE 'zzdel_%'");
    }

    private function id(string $u): int
    {
        return (int) DBHelper::fetchColumn('SELECT id FROM sys_users WHERE username = ?', [$u]);
    }

    public function testOwnAccountIsNotDeleted(): void
    {
        $_SESSION['user_id'] = $this->id('zzdel_a');
        $res = UserService::deleteUser($this->id('zzdel_a'), 'test');
        $this->assertFalse($res['success'] ?? false, json_encode($res));
        $this->assertGreaterThan(0, $this->id('zzdel_a'));
    }

    public function testAnotherAdministratorIsDeleted(): void
    {
        $_SESSION['user_id'] = $this->id('zzdel_a');
        $res = UserService::deleteUser($this->id('zzdel_b'), 'test');
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $this->assertSame(0, $this->id('zzdel_b'));
    }
}
