<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per user recording: '' follows the system settings, 'always' records the user's outside
 * calls and the calls made to and by them directly, 'never' leaves their own outside calls
 * unrecorded. Recordings a route or a queue starts are not affected.
 */
final class AddRecordPolicyToSysUsers extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('sys_users');
        if (!$t->hasColumn('record_policy')) {
            $t->addColumn('record_policy', 'string', ['limit' => 10, 'default' => '', 'null' => false, 'after' => 'pbx_language'])
              ->update();
        }
    }
}
