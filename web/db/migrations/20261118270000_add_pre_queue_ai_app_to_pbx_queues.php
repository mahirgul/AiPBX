<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPreQueueAiAppToPbxQueues extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_queues');
        if (!$table->hasColumn('pre_queue_ai_app_id')) {
            $table->addColumn('pre_queue_ai_app_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'record_mode',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('pbx_queues');
        if ($table->hasColumn('pre_queue_ai_app_id')) {
            $table->removeColumn('pre_queue_ai_app_id')->update();
        }
    }
}
