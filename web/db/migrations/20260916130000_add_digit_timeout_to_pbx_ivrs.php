<?php

use Phinx\Migration\AbstractMigration;

/**
 * Adds the key-press wait time (digit_timeout) field to IVR menus.
 *
 * With direct extension dialing (allow_direct_dial) on, it sets the time
 * (TIMEOUT(digit)) waited between the digits the caller types, or after
 * typing before transferring. The default is 3 seconds.
 */
final class AddDigitTimeoutToPbxIvrs extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_ivrs')) {
            $table = $this->table('pbx_ivrs');
            if (!$table->hasColumn('digit_timeout')) {
                $table->addColumn('digit_timeout', 'integer', [
                    'default' => 3,
                    'null' => false,
                    'after' => 'allow_direct_dial',
                    'comment' => 'Tuslama bekleme suresi (TIMEOUT(digit)) saniye'
                ])->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_ivrs')) {
            $table = $this->table('pbx_ivrs');
            if ($table->hasColumn('digit_timeout')) {
                $table->removeColumn('digit_timeout')->update();
            }
        }
    }
}
