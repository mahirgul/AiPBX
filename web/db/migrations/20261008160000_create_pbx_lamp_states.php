<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Last state pushed to Asterisk for each BLF lamp of a desk phone
 * (Custom:DND1001, Custom:CF1001, Custom:QUEUE1001; LampService). Only
 * changes are sent, so a save does not issue one command per user.
 */
final class CreatePbxLampStates extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_lamp_states', ['id' => false, 'primary_key' => ['name']])
            ->addColumn('name', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('state', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
