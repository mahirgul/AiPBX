<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddQueueCallbackOutcomes extends AbstractMigration
{
    public function change(): void
    {
        $this->table('cc_callbacks')
            ->addColumn('last_auto_result', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('last_auto_attempt_at', 'datetime', ['null' => true])
            ->update();
    }
}
