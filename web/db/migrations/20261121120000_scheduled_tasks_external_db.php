<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ScheduledTasksExternalDb extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_scheduled_tasks')) {
            return;
        }
        $table = $this->table('pbx_scheduled_tasks');
        if (!$table->hasColumn('external_db_id')) {
            $table->addColumn('external_db_id', 'integer', ['null' => true, 'default' => null, 'after' => 'network_access'])
                ->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('pbx_scheduled_tasks')) {
            return;
        }
        $table = $this->table('pbx_scheduled_tasks');
        if ($table->hasColumn('external_db_id')) {
            $table->removeColumn('external_db_id')->update();
        }
    }
}
