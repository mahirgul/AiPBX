<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIconToCcPauseReasons extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('cc_pause_reasons')) {
            $table = $this->table('cc_pause_reasons');
            if (!$table->hasColumn('icon')) {
                $table->addColumn('icon', 'string', ['limit' => 16, 'null' => true, 'default' => '☕', 'after' => 'code'])
                    ->update();

                $defaults = ['1' => '☕', '2' => '🍲', '3' => '📋', '4' => '🎓'];
                foreach ($defaults as $code => $emoji) {
                    $this->execute("UPDATE cc_pause_reasons SET icon = " . $this->getAdapter()->getConnection()->quote((string) $emoji) . " WHERE code = " . $this->getAdapter()->getConnection()->quote((string) $code));
                }
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('cc_pause_reasons')) {
            $table = $this->table('cc_pause_reasons');
            if ($table->hasColumn('icon')) {
                $table->removeColumn('icon')->update();
            }
        }
    }
}
