<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A fax number can have several users and a user several numbers; each user chooses whether incoming
 * faxes reach them by e-mail, and can keep cover templates of their own. Deleting received faxes
 * becomes something an administrator grants, by role.
 */
final class FaxSharedNumbersAndCoverTemplates extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_fax_number_users')) {
            $this->execute("CREATE TABLE pbx_fax_number_users (
                did_mapping_id INT NOT NULL,
                user_id INT NOT NULL,
                PRIMARY KEY (did_mapping_id, user_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->execute('INSERT IGNORE INTO pbx_fax_number_users (did_mapping_id, user_id)
                SELECT m.id, m.assigned_user_id FROM sys_did_mappings m JOIN sys_users u ON u.id = m.assigned_user_id');
        }
        if (!$this->table('sys_users')->hasColumn('fax_mail')) {
            $this->table('sys_users')->addColumn('fax_mail', 'boolean', ['default' => true])->update();
        }
        if (!$this->hasTable('pbx_fax_cover_templates')) {
            $this->execute("CREATE TABLE pbx_fax_cover_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(60) NOT NULL,
                company VARCHAR(120) NOT NULL DEFAULT '',
                notice TEXT NOT NULL,
                logo TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_user_name (user_id, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $this->execute("UPDATE sys_role_permissions SET can_delete = 0 WHERE role_key = 'fax_user' AND module_key = 'fax_inbox'");
    }

    public function down(): void
    {
        $this->execute("UPDATE sys_role_permissions SET can_delete = 1 WHERE role_key = 'fax_user' AND module_key = 'fax_inbox'");
        $this->execute('DROP TABLE IF EXISTS pbx_fax_cover_templates');
        $this->table('sys_users')->removeColumn('fax_mail')->update();
        $this->execute('DROP TABLE IF EXISTS pbx_fax_number_users');
    }
}
