<?php

use Phinx\Migration\AbstractMigration;

final class AddMyPhoneAndChatToRolePermissions extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("
                INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                VALUES 
                    ('admin', 'my_phone', 1, 1, 1, 1),
                    ('admin', 'chat', 1, 1, 1, 1),
                    ('read_only_admin', 'my_phone', 1, 1, 0, 0),
                    ('read_only_admin', 'chat', 1, 1, 0, 0)
                ON DUPLICATE KEY UPDATE 
                    can_view = VALUES(can_view), 
                    can_access = VALUES(can_access), 
                    can_edit = VALUES(can_edit), 
                    can_delete = VALUES(can_delete)
            ");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("DELETE FROM sys_role_permissions WHERE module_key IN ('my_phone', 'chat')");
        }
    }
}
