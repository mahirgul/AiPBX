<?php

use Phinx\Migration\AbstractMigration;

final class AddMeetingsRolePermissions extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_role_permissions')) {
            $defaults = [
                ['admin', 1, 1, 1, 1],
                ['read_only_admin', 1, 1, 0, 0],
                ['user', 1, 1, 1, 1],
                ['cc_manager', 1, 1, 1, 1],
                ['cc_agent', 1, 1, 0, 0],
            ];
            foreach ($defaults as [$role, $view, $access, $edit, $del]) {
                $this->execute(sprintf(
                    "INSERT INTO `sys_role_permissions` (`role_key`, `module_key`, `can_view`, `can_access`, `can_edit`, `can_delete`)
                     VALUES ('%s', 'meetings', %d, %d, %d, %d)
                     ON DUPLICATE KEY UPDATE `can_view` = VALUES(`can_view`), `can_access` = VALUES(`can_access`)",
                    $role,
                    $view,
                    $access,
                    $edit,
                    $del
                ));
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("DELETE FROM `sys_role_permissions` WHERE `module_key` = 'meetings'");
        }
    }
}
