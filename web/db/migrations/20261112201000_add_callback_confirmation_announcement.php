<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCallbackConfirmationAnnouncement extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_queues')->addColumn('callback_announce', 'string', ['limit' => 255, 'default' => ''])->update();
    }
}
