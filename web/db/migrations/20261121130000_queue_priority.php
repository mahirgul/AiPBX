<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class QueuePriority extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_queues')) {
            return;
        }
        $table = $this->table('pbx_queues');
        if (!$table->hasColumn('default_priority')) {
            $table->addColumn('default_priority', 'integer', ['null' => false, 'default' => 0, 'after' => 'max_wait_seconds'])
                ->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('pbx_queues')) {
            return;
        }
        $table = $this->table('pbx_queues');
        if ($table->hasColumn('default_priority')) {
            $table->removeColumn('default_priority')->update();
        }
    }
}
