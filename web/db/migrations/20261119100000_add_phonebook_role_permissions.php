<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The address book gets its own permission (it came with "Phones", admin only): everyone
 * may look a contact up, agents may also save a caller, managers may change and delete.
 * The LDAP and matching settings on the page stay with "Phones".
 */
final class AddPhonebookRolePermissions extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_role_permissions')) {
            return;
        }
        $defaults = [
            'admin' => [1, 1, 1, 1],
            'cc_manager' => [1, 1, 1, 1],
            'cc_agent' => [1, 1, 1, 0],
            'user' => [1, 1, 0, 0],
            'fax_user' => [1, 1, 0, 0],
            'read_only_admin' => [1, 1, 0, 0],
        ];
        $roles = array_column($this->fetchAll('SELECT role_key FROM sys_roles'), 'role_key');
        foreach ($defaults as $role => [$view, $access, $edit, $del]) {
            if (!in_array($role, $roles, true)) {
                continue;
            }
            $this->execute(sprintf(
                "INSERT IGNORE INTO `sys_role_permissions` (`role_key`, `module_key`, `can_view`, `can_access`, `can_edit`, `can_delete`)
                 VALUES ('%s', 'phonebook', %d, %d, %d, %d)",
                $role, $view, $access, $edit, $del
            ));
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("DELETE FROM `sys_role_permissions` WHERE `module_key` = 'phonebook'");
        }
    }
}
