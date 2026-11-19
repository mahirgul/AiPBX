<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTimezoneToPbxTimeConditions extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_time_conditions');
        if (!$table->hasColumn('timezone')) {
            $table->addColumn('timezone', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'internal_number',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('pbx_time_conditions');
        if ($table->hasColumn('timezone')) {
            $table->removeColumn('timezone')->update();
        }
    }
}
