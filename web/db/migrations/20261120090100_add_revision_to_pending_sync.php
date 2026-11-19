<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A pending change edited again gets a new revision: "Apply" removes only the rows (and
 * revisions) it has really applied, so a change saved while Apply runs stays pending.
 */
final class AddRevisionToPendingSync extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('sys_pending_sync');
        if (!$t->hasColumn('revision')) {
            $t->addColumn('revision', 'integer', ['signed' => false, 'default' => 1, 'null' => false])->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('sys_pending_sync');
        if ($t->hasColumn('revision')) {
            $t->removeColumn('revision')->update();
        }
    }
}
