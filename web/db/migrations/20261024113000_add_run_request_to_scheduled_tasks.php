<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Run now" from the portal only records the wish: the portal runs as
 * www-data and may not start a task itself. The dispatcher (root, every
 * minute) starts it in the sandbox and clears this.
 */
final class AddRunRequestToScheduledTasks extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_scheduled_tasks');
        if (!$table->hasColumn('run_requested_at')) {
            $table->addColumn('run_requested_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'last_status'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('pbx_scheduled_tasks');
        if ($table->hasColumn('run_requested_at')) {
            $table->removeColumn('run_requested_at')->update();
        }
    }
}
