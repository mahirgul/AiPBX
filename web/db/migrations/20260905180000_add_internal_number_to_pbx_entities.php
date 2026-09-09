<?php
use Phinx\Migration\AbstractMigration;

/**
 * Dahili hedef numarası alanı: IVR, kuyruk, zaman koşulu, anons ve çağrı
 * sonlandırma tanımları isteğe bağlı olarak bir dahili numara taşıyabilir.
 * Doldurulmuşsa src/sync/SyncInternalNumbers.php bunu dialplan'a yazar.
 *
 * NULL kalabilir (alan zorunlu değil). UNIQUE index NULL'ları saymaz
 * (MySQL/MariaDB davranışı) — yani "numarasız" kayıt sayısı sınırsız,
 * ama aynı tabloda aynı numara iki kez kullanılamaz.
 */
final class AddInternalNumberToPbxEntities extends AbstractMigration
{
    private const TABLOLAR = [
        'pbx_ivrs',
        'pbx_queues',
        'pbx_time_conditions',
        'pbx_announcements',
        'pbx_hangup_actions',
    ];

    public function up(): void
    {
        foreach (self::TABLOLAR as $t) {
            $this->table($t)
                ->addColumn('internal_number', 'string', [
                    'limit'   => 10,
                    'null'    => true,
                    'default' => null,
                    'comment' => 'Dahili telefonlardan dogrudan aranabilen numara (istege bagli)',
                ])
                ->addIndex(['internal_number'], ['unique' => true, 'name' => 'uniq_internal_number'])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::TABLOLAR as $t) {
            $this->table($t)
                ->removeIndexByName('uniq_internal_number')
                ->removeColumn('internal_number')
                ->update();
        }
    }
}
