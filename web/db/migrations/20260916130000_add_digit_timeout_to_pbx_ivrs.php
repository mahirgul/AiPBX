<?php

use Phinx\Migration\AbstractMigration;

/**
 * IVR menülerine tuşlama bekleme süresi (digit_timeout) alanı ekler.
 *
 * Doğrudan dahili arama (allow_direct_dial) açıkken arayanın tuşladığı
 * rakamlar arasında veya tuşlama sonrasında aktarım yapılmadan önce beklenecek
 * süreyi (TIMEOUT(digit)) belirler. Varsayılan 3 saniyedir.
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
