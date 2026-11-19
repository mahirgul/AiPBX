<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * More than one role per user: sys_users.role stays the main role (home page, the name
 * shown); the extra roles here add their permissions, e.g. an agent who also sends faxes
 * or an administrator who also takes queue calls.
 */
final class CreateSysUserRoles extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_user_roles')) {
            return;
        }
        $this->table('sys_user_roles', ['id' => false, 'primary_key' => ['user_id', 'role_key'],
                'collation' => 'utf8mb4_general_ci'])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('role_key', 'string', ['limit' => 64, 'null' => false])
            ->addIndex(['role_key'])
            ->addForeignKey('user_id', 'sys_users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('role_key', 'sys_roles', 'role_key', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('sys_user_roles')) {
            $this->table('sys_user_roles')->drop()->save();
        }
    }
}
