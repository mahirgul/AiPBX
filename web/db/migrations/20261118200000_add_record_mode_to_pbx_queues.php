<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * When a queue's recording starts: 'answer' (an agent answers; hold music stays out)
 * or 'enter' (the caller enters the queue, as before 1.80).
 */
final class AddRecordModeToPbxQueues extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('pbx_queues');
        if (!$t->hasColumn('record_mode')) {
            $t->addColumn('record_mode', 'string', ['limit' => 10, 'default' => 'answer', 'null' => false, 'after' => 'record_format'])
              ->update();
        }
    }
}
