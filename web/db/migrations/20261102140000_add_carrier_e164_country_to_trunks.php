<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A trunk can send dialled numbers in E.164: the calling code whose dialling plan converts them.
 */
final class AddCarrierE164CountryToTrunks extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pbx_trunks');
        if (!$table->hasColumn('carrier_e164_country')) {
            $table->addColumn('carrier_e164_country', 'string', ['limit' => 4, 'null' => true, 'after' => 'carrier_add_prefix'])
                ->update();
        }
    }
}
