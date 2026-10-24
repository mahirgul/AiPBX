<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * IVR: repeat the menu when no key is pressed (off for existing IVRs, so a
 * caller who presses nothing still goes straight to the timeout destination).
 * Queue: position announcement on/off, periodic announcement frequency and
 * file (until now fixed: on, 45 s, queue-youarenext + queue-thankyou).
 */
final class AddIvrTimeoutRepeatAndQueueAnnouncements extends AbstractMigration
{
    public function up(): void
    {
        $ivrs = $this->table('pbx_ivrs');
        if (!$ivrs->hasColumn('timeout_repeat')) {
            $ivrs->addColumn('timeout_repeat', 'boolean', ['default' => 0, 'null' => false, 'after' => 'max_failures'])->update();
        }
        $queues = $this->table('pbx_queues');
        if (!$queues->hasColumn('announce_position')) {
            $queues->addColumn('announce_position', 'string', ['limit' => 4, 'default' => 'yes', 'null' => false, 'after' => 'announce_holdtime']);
        }
        if (!$queues->hasColumn('periodic_frequency')) {
            $queues->addColumn('periodic_frequency', 'integer', ['default' => 45, 'null' => false, 'after' => 'announce_position']);
        }
        if (!$queues->hasColumn('periodic_announce')) {
            $queues->addColumn('periodic_announce', 'string', ['limit' => 255, 'default' => '', 'null' => false, 'after' => 'periodic_frequency']);
        }
        $queues->update();
    }

    public function down(): void
    {
        $ivrs = $this->table('pbx_ivrs');
        if ($ivrs->hasColumn('timeout_repeat')) {
            $ivrs->removeColumn('timeout_repeat')->update();
        }
        $queues = $this->table('pbx_queues');
        foreach (['announce_position', 'periodic_frequency', 'periodic_announce'] as $c) {
            if ($queues->hasColumn($c)) {
                $queues->removeColumn($c);
            }
        }
        $queues->update();
    }
}
