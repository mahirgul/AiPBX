<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Calls per minute a trunk may carry (inbound and outbound together, 0 = no
 * limit). A call over it is refused and the health check "Trunk limits" warns:
 * a call loop between two PBXes once made 6 000 calls an hour on one trunk.
 */
final class AddMaxCallsPerMinuteToTrunks extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_trunks');
        if (!$table->hasColumn('max_calls_per_minute')) {
            $table->addColumn('max_calls_per_minute', 'integer', [
                'signed' => false, 'default' => 0, 'null' => false, 'after' => 'max_channels',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('pbx_trunks');
        if ($table->hasColumn('max_calls_per_minute')) {
            $table->removeColumn('max_calls_per_minute')->update();
        }
    }
}
