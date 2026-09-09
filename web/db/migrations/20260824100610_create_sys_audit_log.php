<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Kalıcı denetim kaydı (audit log) — 2026-08-24, kullanıcı isteği.
 * `sys_pending_sync`'ten (bekleyen listesi, Gönder'den sonra SATIRLARI SİLİNİR)
 * FARKLI: bu tablo INSERT-ONLY, hiçbir satır asla silinmez — "kim ne zaman
 * neyi değiştirdi" kalıcı olarak burada durur. markPendingSync() her
 * çağrıldığında (bir PBX ayarı kaydedilip/silindiğinde) buraya da bir satır
 * düşer; applyPendingSync() da (kim Gönder'e bastı, hangi domain, başarılı mı)
 * ayrıca loglar. username DENORMALIZE edilmiş (users.full_name'in o anki
 * anlık görüntüsü) — kullanıcı daha sonra silinse/adı değişse bile geçmiş
 * kayıt "o an kim yaptıysa" onu göstermeye devam eder.
 */
final class CreateSysAuditLog extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_audit_log', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('domain', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('entity_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('entity_label', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('username', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['created_at'])
            ->addIndex(['domain'])
            ->addIndex(['user_id'])
            ->create();
    }
}
