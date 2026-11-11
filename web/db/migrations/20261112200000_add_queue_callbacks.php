<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddQueueCallbacks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_queues')->addColumn('callback_enabled', 'boolean', ['default' => false])->update();
        $this->table('cc_callbacks')
            ->addColumn('queue_id', 'integer', ['signed' => false])
            ->addColumn('phone', 'string', ['limit' => 32])
            ->addColumn('dial_number', 'string', ['limit' => 32])
            ->addColumn('requested_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('claimed_until', 'datetime', ['null' => true])
            ->addColumn('claimed_by', 'string', ['limit' => 32, 'null' => true])
            ->addIndex(['queue_id', 'phone'], ['unique' => true])
            ->addIndex(['requested_at'])
            ->create();
    }
}
