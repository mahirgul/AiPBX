<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/WelcomeService.php';

/** The welcome card: shortcuts by what the user may open (all roles), until the user closes it. */
final class WelcomeCardTest extends TestCase
{
    private PDO $db;
    private int $id = 0;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_users WHERE username = 'wctest'");
        $this->db->exec("DELETE FROM sys_role_permissions WHERE role_key IN ('cc_agent', 'fax_user') AND module_key IN ('fax_send', 'fax_inbox', 'cc_agent', 'my_phone', 'chat')");
        $perm = $this->db->prepare('INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete) VALUES (?, ?, 1, 1, 0, 0)');
        foreach ([['fax_user', 'fax_send'], ['fax_user', 'fax_inbox'], ['cc_agent', 'cc_agent']] as [$r, $m]) {
            $perm->execute([$r, $m]);
        }
        getRolePermissionsMap(null, true);
        $this->db->exec("INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active, is_device, token_epoch)
                         VALUES ('wctest', '', 'WC', '', 'cc_agent', 1, 0, 0)");
        $this->id = (int) $this->db->lastInsertId();
        $_SESSION = ['user_id' => $this->id, 'user_role' => 'cc_agent', 'user_roles' => ['cc_agent']];
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE username = 'wctest'");
        $this->db->exec("DELETE FROM sys_role_permissions WHERE role_key IN ('cc_agent', 'fax_user') AND module_key IN ('fax_send', 'fax_inbox', 'cc_agent')");
        getRolePermissionsMap(null, true);
        $_SESSION = [];
        unset($_SERVER['REQUEST_URI']);
    }

    private function titles(): array
    {
        return array_column(WelcomeService::groups(), 'title');
    }

    public function testShortcutsFollowEveryRoleOfTheUser(): void
    {
        $this->assertContains(t('welcome.group_agent'), $this->titles());
        $this->assertNotContains(t('welcome.group_fax'), $this->titles());
        $this->assertNotContains(t('welcome.group_setup'), $this->titles(), 'an agent sets nothing up');

        $_SESSION['user_roles'] = ['cc_agent', 'fax_user'];
        $this->assertContains(t('welcome.group_fax'), $this->titles(), 'an agent who also sends faxes');

        $_SESSION['user_roles'] = ['cc_agent', 'admin'];
        $this->assertContains(t('welcome.group_setup'), $this->titles());
        $this->assertContains(t('welcome.group_agent'), $this->titles(), 'an administrator who also takes queue calls');
    }

    public function testShownOnTheStartPageUntilClosed(): void
    {
        $_SERVER['REQUEST_URI'] = roleHomePath('cc_agent');
        $this->assertTrue(WelcomeService::shouldShow(), 'a new user sees it');
        $_SERVER['REQUEST_URI'] = '/chat';
        $this->assertFalse(WelcomeService::shouldShow(), 'only on the start page');
        $_SERVER['REQUEST_URI'] = roleHomePath('cc_agent') . '?tab=x';
        $this->assertTrue(WelcomeService::shouldShow(), 'again on the next visit');
        WelcomeService::dismiss($this->id);
        $this->assertFalse(WelcomeService::shouldShow(), 'closed for good');
    }
}
