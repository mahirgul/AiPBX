<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';

/**
 * The privilege-escalation CIRCUIT BREAKER (auth.php::hasModulePermission).
 *
 * The 'roles' / 'system_users' / 'firewall' / 'fail2ban' modules must be open
 * only to admin WHATEVER sys_role_permissions says. The reason is a real hole
 * (2026-08-21): the roles.php permission matrix offered these modules like any
 * other and the read_only_admin role was configured in the DB with
 * can_access=1 for 'roles' — so that role could make its own role admin and
 * escalate to full privileges.
 *
 * WHY HERE AND NOT IN THE SMOKE TEST: on the live database no role has
 * can_access=1 for these modules, so the page is closed through the normal
 * permission path too — the smoke test CANNOT ISOLATE the circuit breaker
 * (verified by measuring on bin/smoke.php). Here we can write the permission
 * rows ourselves, so the real test is possible.
 */
final class RbacTest extends TestCase
{
    private const LOCKED = [
        'roles',
        'system_users',
        'firewall',
        'fail2ban',
        'mail_settings',
        'system_update',
        'certificates',
        'privacy',
        'phones',
        'web_widgets',
        'plugins',
        'api_keys',
        'webhooks',
        'google_integration',
        'file_storage',
        'ai_models',
        'ai_cloud',
        'ai_apps',
        'assistant',
        'health',
        'call_quality',
    ];

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('tests must run only on asterisk_test');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $db = getDB();
        $db->exec('TRUNCATE TABLE sys_role_permissions');

        // CRITICAL SETUP: explicitly give FULL access in the DB to non-admin
        // roles for the locked modules. If the circuit breaker works, these
        // rows must have no effect.
        $stmt = $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        );
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user', 'user'] as $role_key) {
            foreach (self::LOCKED as $module) {
                $stmt->execute([$role_key, $module]);
            }
        }
        getRolePermissionsMap(null, true);
    }

    public static function lockedModules(): array
    {
        return array_map(static fn(string $m): array => [$m], self::LOCKED);
    }

    public function testAdminOnlyModulesMatchRoleRepositoryDefinition(): void
    {
        require_once dirname(__DIR__, 2) . '/src/repositories/RoleRepository.php';
        $repoLocked = RoleRepository::adminOnlyModules();
        $expected   = self::LOCKED;
        sort($repoLocked);
        sort($expected);
        $this->assertSame($expected, $repoLocked);
    }

    #[DataProvider('lockedModules')]
    public function testNonAdminRolesAreRefusedEvenIfTheDatabaseAllows(string $module): void
    {
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user', 'user'] as $role_key) {
            $_SESSION['user_role'] = $role_key;

            $this->assertFalse(
                hasModulePermission($module, 'access'),
                "role '{$role_key}' can access module '{$module}' — THE CIRCUIT BREAKER IS LOOSE! "
                . 'This allows privilege escalation.'
            );
        }
    }

    #[DataProvider('lockedModules')]
    public function testAdminAlwaysSeesLockedModules(string $module): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertTrue(
            hasModulePermission($module, 'access'),
            "admin cannot access module '{$module}' — risk of locking yourself out!"
        );
    }

    public function testUnknownRoleCannotSeeLockedModules(): void
    {
        $_SESSION['user_role'] = '__missing_role__';
        foreach (self::LOCKED as $module) {
            $this->assertFalse(hasModulePermission($module, 'access'));
        }
    }

    public function testUserWithoutSessionCannotSeeLockedModules(): void
    {
        unset($_SESSION['user_role']);
        foreach (self::LOCKED as $module) {
            $this->assertFalse(hasModulePermission($module, 'access'));
        }
    }

    /**
     * Read-only viewer (read_only_admin) rule:
     * it can NEVER edit ('edit') or delete ('delete') in any module, even when
     * the DB explicitly allows it. It must stay a viewer only.
     */
    public function testReadOnlyAdminCannotEditOrDeleteAnywhere(): void
    {
        $_SESSION['user_role'] = 'read_only_admin';
        $modules = ['trunks', 'extensions', 'queues', 'asterisk_settings', 'brand_settings', 'pending_sync', 'push_settings', 'fax_send', 'fax_sent', 'my_phone', 'chat'];

        foreach ($modules as $m) {
            $this->assertFalse(
                hasModulePermission($m, 'edit'),
                "read_only_admin can edit in module '{$m}' — a viewer must not change settings!"
            );
            $this->assertFalse(
                hasModulePermission($m, 'delete'),
                "read_only_admin can delete in module '{$m}' — a viewer must not delete!"
            );
        }
    }

    public function testIsPostDeleteRequestDetection(): void
    {
        $_POST = [];
        $_GET = [];
        $this->assertFalse(isPostDeleteRequest());

        $_POST = ['save_trunk' => '1'];
        $this->assertFalse(isPostDeleteRequest());

        $_POST = ['delete_trunk' => '1'];
        $this->assertTrue(isPostDeleteRequest());

        $_POST = ['remove_extension' => '1'];
        $this->assertTrue(isPostDeleteRequest());

        $_POST = [];
        $_POST['action'] = 'delete_mapping';
        $this->assertTrue(isPostDeleteRequest());

        $_POST = [];
        $_GET['action'] = 'delete_mapping';
        $this->assertTrue(isPostDeleteRequest());

        $_POST = [];
        $_GET = [];
    }

    public function testDeleteControlsAreHiddenWithoutDeletePermission(): void
    {
        require_once dirname(__DIR__, 2) . '/src/ui_helpers.php';

        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 0)'
        )->execute(['editor_only', 'trunks']);
        getRolePermissionsMap(null, true);

        $_SESSION['user_role'] = 'editor_only';
        $_SERVER['PHP_SELF'] = '/trunks.php';

        $this->assertTrue(hasModulePermission('trunks', 'edit'));
        $this->assertFalse(hasModulePermission('trunks', 'delete'));

        // can_delete=0, so uiDeleteForm must return empty
        $html = uiDeleteForm(1, 'trunk_id', 'delete_trunk');
        $this->assertSame('', $html, 'uiDeleteForm must not print a delete button for a user with can_delete=0!');

        // uiRowActions must not contain the delete button either
        $actionsHtml = uiRowActions(['id' => 1], 'openEditTrunkModal', 'trunk_id', 'delete_trunk');
        $this->assertStringNotContainsString('delete_trunk', $actionsHtml);
    }

    public function testPluginBackedModulesRequireEnabledPluginForNonAdminRoles(): void
    {
        require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';

        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        )->execute(['cc_manager', 'ai_tts']);
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        )->execute(['cc_manager', 'ms_teams']);
        getRolePermissionsMap(null, true);

        $csrf = $_SESSION['csrf_token'] = 'test-csrf';
        PluginManager::disable('ai-tts', $csrf);
        PluginManager::disable('ms-teams', $csrf);

        $_SESSION['user_role'] = 'cc_manager';
        $this->assertFalse(hasModulePermission('ai_tts', 'access'));
        $this->assertFalse(hasModulePermission('ms_teams', 'access'));

        $_SESSION['user_role'] = 'read_only_admin';
        $this->assertFalse(hasModulePermission('ai_tts', 'access'));
        $this->assertFalse(hasModulePermission('ms_teams', 'access'));

        PluginManager::enable('ai-tts', $csrf);
        PluginManager::enable('ms-teams', $csrf);

        $_SESSION['user_role'] = 'cc_manager';
        $this->assertTrue(hasModulePermission('ai_tts', 'access'));
        $this->assertTrue(hasModulePermission('ms_teams', 'access'));

        $_SESSION['user_role'] = 'read_only_admin';
        $this->assertTrue(hasModulePermission('ai_tts', 'access'));
        $this->assertFalse(hasModulePermission('ai_tts', 'edit'));
        $this->assertTrue(hasModulePermission('ms_teams', 'access'));
        $this->assertFalse(hasModulePermission('ms_teams', 'edit'));

        PluginManager::disable('ai-tts', $csrf);
        PluginManager::disable('ms-teams', $csrf);
    }
}

