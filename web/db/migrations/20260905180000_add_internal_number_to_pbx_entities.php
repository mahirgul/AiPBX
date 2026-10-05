<?php
use Phinx\Migration\AbstractMigration;

/**
 * Internal destination number field: IVR, queue, time condition,
 * announcement and call-ending definitions can optionally carry an internal
 * number. When filled, src/sync/SyncInternalNumbers.php writes it to the
 * dialplan.
 *
 * It can stay NULL (the field is optional). A UNIQUE index does not count
 * NULLs (MySQL/MariaDB behaviour) — so the number of "numberless" records is
 * unlimited, but the same number cannot be used twice in the same table.
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
