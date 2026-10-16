<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A settings version per desk phone (PhoneProvisionService::withConfigVersion).
 *
 * NEC DT700/DT800 phones apply their local override file only when its
 * <VERSION Version="n"/> is higher than the one they applied last; an
 * unchanged number makes them ignore a changed file. config_hash is the
 * hash of the phone's files without that number: when it changes, the
 * version goes up by one. Starts at 2: the NEC phones in use were set up
 * with files of version 2, so the first file from AiPBX gets 3.
 */
final class AddConfigVersionToPbxPhones extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('pbx_phones');
        if (!$t->hasColumn('config_version')) {
            $t->addColumn('config_version', 'integer', ['signed' => false, 'null' => false, 'default' => 2])
                ->addColumn('config_hash', 'char', ['limit' => 64, 'null' => true, 'default' => null])
                ->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('pbx_phones');
        if ($t->hasColumn('config_version')) {
            $t->removeColumn('config_version')->removeColumn('config_hash')->update();
        }
    }
}
