<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/UserService.php';

/**
 * Extra roles (sys_user_roles): their permissions add to the main role's — an agent who
 * also sends faxes, an administrator who also takes queue calls.
 */
final class MultiRoleTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->cleanUp();
        foreach (['cc_agent', 'fax_user', 'user', 'read_only_admin', 'admin'] as $r) {
            $this->db->prepare('INSERT IGNORE INTO sys_roles (role_key, role_name, is_system) VALUES (?, ?, 1)')->execute([$r, $r]);
        }
        $perm = $this->db->prepare('INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, 0)');
        $perm->execute(['cc_agent', 'mrtest_cc', 1, 1, 0]);
        $perm->execute(['fax_user', 'mrtest_fax', 1, 1, 1]);
        $perm->execute(['cc_agent', 'mrtest_fax', 0, 0, 0]);
        getRolePermissionsMap(null, true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
        getRolePermissionsMap(null, true);
    }

    private function cleanUp(): void
    {
        $this->db->exec("DELETE FROM sys_role_permissions WHERE module_key LIKE 'mrtest\\_%'");
        $this->db->exec("DELETE FROM sys_users WHERE username = 'mrtest_user'");
    }

    private function signIn(array $roles): void
    {
        $_SESSION['user_role'] = $roles[0];
        $_SESSION['user_roles'] = $roles;
    }

    public function testAnExtraRoleAddsItsPermissions(): void
    {
        $this->signIn(['cc_agent']);
        $this->assertTrue(hasModulePermission('mrtest_cc', 'access'));
        $this->assertFalse(hasModulePermission('mrtest_fax', 'access'), 'closed for the agent role');

        $this->signIn(['cc_agent', 'fax_user']);
        $this->assertTrue(hasModulePermission('mrtest_cc', 'access'));
        $this->assertTrue(hasModulePermission('mrtest_fax', 'edit'), 'the fax role opens it although the agent role closes it');
        $this->assertFalse(hasModulePermission('mrtest_cc', 'edit'), 'no role gives edit');
        $this->assertTrue(userHasRole('fax_user'));
        $this->assertSame('cc_agent', userEffectiveRole());
    }

    public function testAdminAsAnExtraRoleIsAdmin(): void
    {
        $this->signIn(['cc_agent', 'admin']);
        $this->assertTrue(hasModulePermission('roles', 'edit'), 'admin-only modules');
        $this->assertSame('admin', userEffectiveRole());
        $this->assertTrue(userHasRole('cc_agent'), 'still an agent');
    }

    public function testViewerWithAnotherRoleMayEditWhatThatRoleMay(): void
    {
        $this->signIn(['read_only_admin']);
        $this->assertFalse(hasModulePermission('mrtest_fax', 'edit'));
        $this->signIn(['read_only_admin', 'fax_user']);
        $this->assertTrue(hasModulePermission('mrtest_fax', 'edit'));
    }

    public function testStaleSessionListFallsBackToTheMainRole(): void
    {
        // The main role changed since the list was read: the list is not trusted.
        $_SESSION['user_role'] = 'user';
        $_SESSION['user_roles'] = ['admin', 'fax_user'];
        $this->assertSame(['user'], userRoles());
        $this->assertFalse(userHasRole('admin'));
    }

    public function testExtraRolesAreStoredWithoutTheMainRoleOrUnknownOnes(): void
    {
        $this->db->exec("INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active, is_device, token_epoch)
                         VALUES ('mrtest_user', '', 'MR', '', 'cc_agent', 1, 0, 0)");
        $id = (int) $this->db->lastInsertId();
        UserService::saveExtraRoles($id, 'cc_agent', ['fax_user', 'cc_agent', 'no_such_role', 'fax_user']);
        $this->assertSame(['cc_agent', 'fax_user'], loadUserRoles($id, 'cc_agent'));
        UserService::saveExtraRoles($id, 'cc_agent', []);
        $this->assertSame(['cc_agent'], loadUserRoles($id, 'cc_agent'));
    }
}
