<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** sys_users.is_device: a SIP extension without a portal user (lobby, door, shared line). */
final class AddIsDeviceToUsers extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('sys_users');
        if (!$table->hasColumn('is_device')) {
            $table->addColumn('is_device', 'boolean', ['default' => false, 'null' => false, 'after' => 'is_active'])->update();
        }
    }
}
