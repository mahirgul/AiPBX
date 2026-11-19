<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The welcome card on a user's start page stays until that user closes it. Users who
 * have signed in before this release do not get it: it is for new users.
 */
final class AddWelcomeDismissedToSysUsers extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('sys_users');
        if ($t->hasColumn('welcome_dismissed')) {
            return;
        }
        $t->addColumn('welcome_dismissed', 'boolean', ['default' => false, 'null' => false])->update();
        $this->execute('UPDATE sys_users SET welcome_dismissed = 1 WHERE last_seen_at IS NOT NULL');
    }

    public function down(): void
    {
        if ($this->table('sys_users')->hasColumn('welcome_dismissed')) {
            $this->table('sys_users')->removeColumn('welcome_dismissed')->update();
        }
    }
}
