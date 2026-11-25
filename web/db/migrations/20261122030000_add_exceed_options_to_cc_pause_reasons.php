<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddExceedOptionsToCcPauseReasons extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('cc_pause_reasons')) {
            $table = $this->table('cc_pause_reasons');
            if (!$table->hasColumn('warn_on_exceed')) {
                $table->addColumn('warn_on_exceed', 'boolean', ['null' => false, 'default' => true, 'after' => 'max_minutes']);
            }
            if (!$table->hasColumn('auto_unpause')) {
                $table->addColumn('auto_unpause', 'boolean', ['null' => false, 'default' => false, 'after' => 'warn_on_exceed']);
            }
            $table->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('cc_pause_reasons')) {
            $table = $this->table('cc_pause_reasons');
            if ($table->hasColumn('auto_unpause')) {
                $table->removeColumn('auto_unpause');
            }
            if ($table->hasColumn('warn_on_exceed')) {
                $table->removeColumn('warn_on_exceed');
            }
            $table->update();
        }
    }
}
