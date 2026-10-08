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
    private const KILITLI = ['roles', 'system_users', 'firewall', 'fail2ban', 'mail_settings', 'phones'];

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('testler yalnizca asterisk_test uzerinde kosmali');
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
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user', 'user'] as $rol) {
            foreach (self::KILITLI as $modul) {
                $stmt->execute([$rol, $modul]);
            }
        }
    }

    public static function kilitliModuller(): array
    {
        return [['roles'], ['system_users'], ['firewall'], ['fail2ban'], ['mail_settings'], ['phones']];
    }

    #[DataProvider('kilitliModuller')]
    public function testDbdeACIKCA_IZIN_VERILSE_BILE_adminDisindakiRollerReddedilir(string $modul): void
    {
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user', 'user'] as $rol) {
            $_SESSION['user_role'] = $rol;

            $this->assertFalse(
                hasModulePermission($modul, 'access'),
                "'{$rol}' rolu '{$modul}' modulune erisebiliyor — DEVRE KESICI GEVSEMIS! "
                . 'Bu, yetki yukseltmesine acik bir durum.'
            );
        }
    }

    #[DataProvider('kilitliModuller')]
    public function testAdminKilitliModulleriHerZamanGorebilir(string $modul): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertTrue(
            hasModulePermission($modul, 'access'),
            "admin '{$modul}' modulune erisemiyor — kendini disarida birakma (kilitlenme) riski!"
        );
    }

    public function testBilinmeyenRolKilitliModulleriGoremez(): void
    {
        $_SESSION['user_role'] = '__olmayan_rol__';
        foreach (self::KILITLI as $modul) {
            $this->assertFalse(hasModulePermission($modul, 'access'));
        }
    }

    public function testOturumsuzKullaniciKilitliModulleriGoremez(): void
    {
        unset($_SESSION['user_role']);
        foreach (self::KILITLI as $modul) {
            $this->assertFalse(hasModulePermission($modul, 'access'));
        }
    }

    /**
     * Read-only viewer (read_only_admin) rule:
     * it can NEVER edit ('edit') or delete ('delete') in any module, even when
     * the DB explicitly allows it. It must stay a viewer only.
     */
    public function testReadOnlyAdminHicbirModuldeDuzenlemeVeSilmeYapamaz(): void
    {
        $_SESSION['user_role'] = 'read_only_admin';
        $modules = ['trunks', 'extensions', 'queues', 'asterisk_settings', 'brand_settings', 'pending_sync', 'push_settings', 'fax_send', 'fax_sent', 'my_phone', 'chat'];

        foreach ($modules as $m) {
            $this->assertFalse(
                hasModulePermission($m, 'edit'),
                "read_only_admin '{$m}' modulunde edit yapabiliyor — Izleyici ayar yapamamali!"
            );
            $this->assertFalse(
                hasModulePermission($m, 'delete'),
                "read_only_admin '{$m}' modulunde delete yapabiliyor — Izleyici silme yapamamali!"
            );
        }
    }

    public function testIsPostDeleteRequestAlgilama(): void
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

    public function testUiDeleteFormVeUiRowActionsSilmeYetkisiYoksaGorunmez(): void
    {
        require_once dirname(__DIR__, 2) . '/src/ui_helpers.php';

        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 0)'
        )->execute(['editor_only', 'trunks']);

        $_SESSION['user_role'] = 'editor_only';
        $_SERVER['PHP_SELF'] = '/trunks.php';

        $this->assertTrue(hasModulePermission('trunks', 'edit'));
        $this->assertFalse(hasModulePermission('trunks', 'delete'));

        // can_delete=0, so uiDeleteForm must return empty
        $html = uiDeleteForm(1, 'trunk_id', 'delete_trunk');
        $this->assertSame('', $html, 'can_delete=0 olan kullaniciya uiDeleteForm silme butonu basmamali!');

        // uiRowActions must not contain the delete button either
        $actionsHtml = uiRowActions(['id' => 1], 'openEditTrunkModal', 'trunk_id', 'delete_trunk');
        $this->assertStringNotContainsString('delete_trunk', $actionsHtml);
    }
}

