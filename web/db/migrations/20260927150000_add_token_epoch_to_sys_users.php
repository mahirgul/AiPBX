<?php

use Phinx\Migration\AbstractMigration;

/**
 * Mobil/sohbet oturum token'larını geri çekebilmek için kullanıcı başına
 * sayaç. İmza 0 iken eski biçimle aynıdır (mevcut token'lar geçerli kalır);
 * şifre sıfırlanınca artar ve o ana kadar verilmiş tüm token'lar geçersizleşir.
 * Hem PHP (api/mobile/auth_helper.php) hem Go sohbet servisi (chat/auth.go) okur.
 */
final class AddTokenEpochToSysUsers extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('sys_users');
        if (!$table->hasColumn('token_epoch')) {
            $table->addColumn('token_epoch', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
                  ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('sys_users');
        if ($table->hasColumn('token_epoch')) {
            $table->removeColumn('token_epoch')->update();
        }
    }
}
