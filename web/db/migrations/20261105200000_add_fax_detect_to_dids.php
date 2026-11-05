<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** An inbound route can listen for a fax tone before it goes to its destination. Off for every route. */
final class AddFaxDetectToDids extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_dids');
        if (!$table->hasColumn('fax_detect')) {
            $table->addColumn('fax_detect', 'boolean', ['default' => false])
                ->addColumn('fax_detect_seconds', 'integer', ['default' => 5])
                ->addColumn('fax_target', 'string', ['limit' => 32, 'default' => ''])
                ->update();
        }
    }

    public function down(): void
    {
        $this->table('pbx_dids')->removeColumn('fax_detect')->removeColumn('fax_detect_seconds')->removeColumn('fax_target')->update();
    }
}
