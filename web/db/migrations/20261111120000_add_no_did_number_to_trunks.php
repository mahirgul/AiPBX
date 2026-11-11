<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** pbx_trunks.no_did_number: the number a call without a DID is routed as (empty = none). */
final class AddNoDidNumberToTrunks extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pbx_trunks');
        if (!$table->hasColumn('no_did_number')) {
            $table->addColumn('no_did_number', 'string', [
                'limit' => 32,
                'null' => true,
                'default' => null,
                'after' => 'did_trim_digits',
            ])->update();
        }
    }
}
