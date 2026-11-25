<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAutomaticQueueCallbacks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_queues')
            ->addColumn('callback_auto_enabled', 'boolean', ['null' => false, 'default' => false])
            ->update();
        $this->table('cc_callbacks')
            ->addColumn('auto_attempts', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addIndex(['claimed_by', 'claimed_until'])
            ->update();
    }
}
