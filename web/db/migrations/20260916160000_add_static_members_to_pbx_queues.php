<?php
use Phinx\Migration\AbstractMigration;

final class AddStaticMembersToPbxQueues extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pbx_queues');
        if (!$table->hasColumn('static_members_json')) {
            $table->addColumn('static_members_json', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'members_json',
                'comment' => 'Statik kuyruk temsilcileri (kuyruktan cikamaz, sadece mola alabilir)',
            ])->update();
        }
    }
}
