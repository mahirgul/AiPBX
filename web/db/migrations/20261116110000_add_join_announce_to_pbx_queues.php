<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds an optional first-entry announcement (join_announce) to pbx_queues,
 * played once via Playback() right before Queue() starts.
 */
final class AddJoinAnnounceToPbxQueues extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('pbx_queues');
        if (!$t->hasColumn('join_announce')) {
            $t->addColumn('join_announce', 'string', ['limit' => 255, 'default' => '', 'null' => false])
              ->update();
        }
    }
}
