<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ertelenmiş Asterisk reload sistemi için bekleyen değişiklik listesi.
 * Bir admin bir PBX ayarını kaydettiğinde/sildiğinde DB'ye hemen yazılır
 * ama Asterisk config dosyası hemen yeniden üretilip reload EDİLMEZ —
 * bunun yerine bu tabloya "hangi kayıt değişti" diye bir satır eklenir.
 * Sidebar'daki "Uygula" sayfası bu tabloyu listeler; admin "Gönder"e
 * basınca ilgili domain'lerin gerçek sync+reload'ı çalışır ve o domain'e
 * ait satırlar silinir. domain, src/asterisk_sync.php'deki withSyncLock()
 * kilit adlarıyla (extensions/queues/ivrs/trunks/...) birebir eşleşir —
 * apply akışı domain -> syncXxx() eşlemesini kullanır.
 *
 * (domain, entity_type, entity_id) benzersiz: aynı kayıt Uygula'dan önce
 * birden fazla kez düzenlenirse satır güncellenir (tekilleşir), her
 * düzenlemede ayrı satır birikmez.
 */
final class CreateSysPendingSync extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_pending_sync', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('domain', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('entity_label', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('changed_by', 'integer', ['null' => true])
            ->addColumn('changed_at', 'datetime', ['null' => false])
            ->addIndex(['domain', 'entity_type', 'entity_id'], ['unique' => true, 'name' => 'uniq_pending_entity'])
            ->addIndex(['domain'])
            ->create();
    }
}
