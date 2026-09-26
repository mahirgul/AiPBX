<?php

use Phinx\Migration\AbstractMigration;

/**
 * Tek kullanımlık mobil giriş kodlarına amaç ekler:
 *  - screen: web "Dahilim" ekranındaki QR (10 dk)
 *  - email:  davet e-postasındaki "mobil uygulamaya giriş" bağlantısı (7 gün)
 *  - google: Google girişinin uygulamaya döndürdüğü kod (2 dk) — önceden
 *            oturum token'ı ve SIP şifresi aipbx://auth URL'sinde taşınıyordu.
 * Mevcut satırlar 'screen' olur; davranış değişmez.
 */
final class AddPurposeToSysUserQrTokens extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('sys_user_qr_tokens');
        if (!$table->hasColumn('purpose')) {
            $table->addColumn('purpose', 'string', ['limit' => 16, 'null' => false, 'default' => 'screen', 'after' => 'token'])
                  ->addIndex(['user_id', 'purpose'])
                  ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('sys_user_qr_tokens');
        if ($table->hasColumn('purpose')) {
            $table->removeIndex(['user_id', 'purpose'])
                  ->removeColumn('purpose')
                  ->update();
        }
    }
}
