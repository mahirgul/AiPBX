<?php

use Phinx\Migration\AbstractMigration;

/**
 * Existing installations only (fresh installs get these rows from db/seed.sql,
 * whose fixed ids must not be taken first).
 *
 * Queue Report Centre: the roles that see the whole call centre's numbers.
 * Agents (cc_agent) do not get it by default: it compares agents with each
 * other; an admin can grant it on the Roles page.
 */
final class GrantQueueReports extends AbstractMigration
{
    private const GRANTS = [
        'admin' => [1, 1, 1, 1],
        'read_only_admin' => [1, 1, 0, 0],
        'cc_manager' => [1, 1, 0, 0],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $role => [$view, $access, $edit, $delete]) {
            $this->execute(
                "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                 SELECT '{$role}', 'queue_reports', {$view}, {$access}, {$edit}, {$delete} FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM sys_role_permissions WHERE role_key = '{$role}' AND module_key = 'queue_reports')
                   AND EXISTS (SELECT 1 FROM sys_roles WHERE role_key = '{$role}')"
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'queue_reports'");
    }
}
