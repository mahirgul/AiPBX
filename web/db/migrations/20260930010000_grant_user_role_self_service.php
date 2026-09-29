<?php

use Phinx\Migration\AbstractMigration;

/**
 * The "user" role needs My Phone (to save its own DND / forwarding /
 * voicemail settings) and Chat; without rows both are hidden and locked.
 */
final class GrantUserRoleSelfService extends AbstractMigration
{
    public function up(): void
    {
        foreach (['my_phone', 'chat'] as $module) {
            $this->execute(
                "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                 SELECT 'user', '{$module}', 1, 1, 1, 0 FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM sys_role_permissions WHERE role_key = 'user' AND module_key = '{$module}')"
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM sys_role_permissions WHERE role_key = 'user' AND module_key IN ('my_phone', 'chat')");
    }
}
