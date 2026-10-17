<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/DialPermissionService.php';

/**
 * Rule deletion is scoped to its group, like the update in saveRule():
 * naming a rule id from another group must not delete it.
 */
final class PermissionRuleScopeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->db->exec("DELETE FROM pbx_permission_rules WHERE description = 'scope-probe'");
        $this->db->exec("DELETE FROM pbx_permission_groups WHERE group_name IN ('ScopeA','ScopeB')");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM pbx_permission_rules WHERE description = 'scope-probe'");
        $this->db->exec("DELETE FROM pbx_permission_groups WHERE group_name IN ('ScopeA','ScopeB')");
    }

    /** @return array{int,int} [groupA, groupB-with-rule, rule] */
    private function seed(): array
    {
        $this->db->prepare(
            "INSERT INTO pbx_permission_groups (group_name, default_action, is_active) VALUES ('ScopeA', 'allow', 1)"
        )->execute();
        $a = (int) $this->db->lastInsertId();
        $this->db->prepare(
            "INSERT INTO pbx_permission_groups (group_name, default_action, is_active) VALUES ('ScopeB', 'deny', 1)"
        )->execute();
        $b = (int) $this->db->lastInsertId();
        $this->db->prepare(
            "INSERT INTO pbx_permission_rules (group_id, pattern, pattern_type, action, priority, description)
             VALUES (?, '090', 'prefix', 'deny', 10, 'scope-probe')"
        )->execute([$b]);
        return [$a, $b, (int) $this->db->lastInsertId()];
    }

    private function ruleExists(int $rule): bool
    {
        return (bool) $this->db->query("SELECT 1 FROM pbx_permission_rules WHERE id = {$rule}")->fetchColumn();
    }

    public function testCrossGroupDeleteIsRefused(): void
    {
        [$a, $b, $rule] = $this->seed();

        $res = DialPermissionService::deleteRule($rule, $a, 'test-token');
        $this->assertFalse($res['success'], 'a rule from another group must not be deletable by id');
        $this->assertTrue($this->ruleExists($rule), 'the refused delete must leave the rule in place');
    }

    public function testOwnGroupDeleteSucceeds(): void
    {
        [$a, $b, $rule] = $this->seed();

        $res = DialPermissionService::deleteRule($rule, $b, 'test-token');
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertFalse($this->ruleExists($rule));
    }
}
