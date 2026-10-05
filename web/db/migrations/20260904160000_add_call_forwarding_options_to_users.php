<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds advanced call forwarding alternatives to the sys_users table:
 * - cf_busy_number (forward when busy)
 * - cf_noanswer_number (forward on no answer)
 * - cf_noanswer_timeout (ring time before forwarding on no answer)
 */
final class AddCallForwardingOptionsToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_users')
            ->addColumn('cf_busy_number', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'call_forward_number',
            ])
            ->addColumn('cf_noanswer_number', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'cf_busy_number',
            ])
            ->addColumn('cf_noanswer_timeout', 'integer', [
                'limit' => 11,
                'null' => false,
                'default' => 20,
                'after' => 'cf_noanswer_number',
            ])
            ->update();
    }
}
