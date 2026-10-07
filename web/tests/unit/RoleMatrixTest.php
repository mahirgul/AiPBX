<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/BaseRepository.php';
require_once dirname(__DIR__, 2) . '/src/repositories/RoleRepository.php';

/**
 * The Roles page's permission matrix must describe the real system: every
 * page is in it, in the sidebar's group and order, with exactly the actions
 * the code checks, and admin-only pages locked. It had drifted (pages
 * missing, call reports under the wrong group, edit/delete boxes on
 * read-only pages, admin-only pages offered to other roles).
 */
final class RoleMatrixTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Pages that are not permission-controlled modules (login flow, own account, OAuth). */
    private const NOT_MODULES = ['login', 'login_2fa', 'logout', 'force_reset', 'reset_password', 'mobile_login', 'security', 'google_auth', 'google_auth_callback'];

    /** Sidebar group id => matrix group. */
    private const SIDEBAR_GROUPS = [
        'group-dashboard' => 'General', 'group-trunks' => 'Outbound Line Management', 'group-pbx' => 'PBX Management',
        'group-admin' => 'Administration', 'group-security' => 'Security', 'group-integrations' => 'Integrations', 'group-ai' => 'AI',
        'group-fax' => 'Fax System', 'group-cc' => 'Call Center',
    ];

    /** @return array<string, string> route path => module key */
    private static function routeModules(): array
    {
        $routes = require self::ROOT . '/src/routes.php';
        $out = [];
        foreach ($routes as $path => $r) {
            if (is_array($r)) {
                $_SERVER['PHP_SELF'] = '/' . $r['module'];
                $out[$path] = getModuleKeyForPage();
            }
        }
        return $out;
    }

    public function testEveryPageIsInTheMatrix(): void
    {
        $def = RoleRepository::modulesDefinition();
        foreach (self::routeModules() as $path => $module) {
            if (in_array($module, self::NOT_MODULES, true)) {
                continue;
            }
            $this->assertArrayHasKey($module, $def, "{$path} ({$module}) is missing from the roles matrix");
        }
    }

    public function testMatrixFollowsTheSidebar(): void
    {
        $def = RoleRepository::modulesDefinition();
        $byPath = self::routeModules();
        $group = null;
        $order = [];
        foreach (file(self::ROOT . '/templates/sidebar_menu.php') as $line) {
            if (preg_match("/'id' => '([a-z]+)'/", $line, $m)) {
                $group = self::SIDEBAR_GROUPS['group-' . $m[1]] ?? null;
            }
            if ($group && preg_match("#'href' => '(/[a-z0-9/-]+)'#", $line, $m) && isset($byPath[$m[1]], $def[$byPath[$m[1]]])) {
                $module = $byPath[$m[1]];
                $this->assertSame($group, $def[$module]['group'], "{$module} is under \"{$group}\" in the sidebar");
                $order[] = $module;
            }
        }
        $order = array_values(array_unique($order));
        $matrixOrder = array_values(array_intersect(array_keys($def), $order));
        $this->assertSame($order, $matrixOrder, 'matrix rows are in the sidebar order');
        // Groups appear in the sidebar's order too.
        $this->assertSame(array_values(array_unique(array_column($def, 'group'))), array_values(array_unique(array_map(fn($k) => $def[$k]['group'], $order))));
    }

    public function testEverySidebarLinkIsARoute(): void
    {
        $routes = require self::ROOT . '/src/routes.php';
        preg_match_all("#'href' => '([^']+)'#", (string) file_get_contents(self::ROOT . '/templates/sidebar_menu.php'), $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $href) {
            $this->assertArrayHasKey($href, $routes, "sidebar link {$href} has no route");
        }
    }

    public function testActionsMatchWhatTheCodeChecks(): void
    {
        $def = RoleRepository::modulesDefinition();
        $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT)), '/\.php$/');
        foreach ($files as $f) {
            $path = (string) $f;
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            preg_match_all("/(?:hasModulePermission|requireModulePermission|requireModule)\(\s*'([a-z_]+)'\s*,\s*'(edit|delete)'/", (string) file_get_contents($path), $m, PREG_SET_ORDER);
            foreach ($m as [, $module, $action]) {
                if (isset($def[$module])) {
                    $this->assertContains($action, $def[$module]['actions'], "{$module}: '{$action}' is checked in " . basename($path) . ' but has no column');
                }
            }
        }
        foreach ($def as $key => $m) {
            $this->assertContains('view', $m['actions'], $key);
            $this->assertEmpty(array_diff($m['actions'], RoleRepository::ALL_ACTIONS), $key);
        }
    }

    public function testAdminOnlyModulesMatchTheCircuitBreaker(): void
    {
        $auth = (string) file_get_contents(self::ROOT . '/auth.php');
        $this->assertMatchesRegularExpression("/if \(in_array\(\\\$module_key, \[([^\]]+)\], true\)\) \{\s*return \\\$role === 'admin';/", $auth);
        preg_match("/if \(in_array\(\\\$module_key, \[([^\]]+)\], true\)\) \{\s*return \\\$role === 'admin';/", $auth, $m);
        $locked = array_map(fn($s) => trim($s, " '"), explode(',', $m[1]));
        sort($locked);
        $flagged = RoleRepository::adminOnlyModules();
        sort($flagged);
        $this->assertSame($locked, $flagged);

        // And the circuit breaker really refuses them to everyone else.
        $prev = $_SESSION['user_role'] ?? null;
        $_SESSION['user_role'] = 'cc_manager';
        foreach ($flagged as $module) {
            $this->assertFalse(hasModulePermission($module, 'view'), $module);
        }
        $_SESSION['user_role'] = $prev;
    }
}
