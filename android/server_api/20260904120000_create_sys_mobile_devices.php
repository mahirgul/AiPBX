<?php

use Phinx\Migration\AbstractMigration;

/**
 * Mobil cihaz FCM push bildirim tokenlari tablosu.
 */
final class CreateSysMobileDevices extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_mobile_devices')) {
            $table = $this->table('sys_mobile_devices');
            $table->addColumn('user_id', 'integer', ['null' => false])
                  ->addColumn('extension', 'string', ['limit' => 20, 'null' => false])
                  ->addColumn('fcm_token', 'string', ['limit' => 512, 'null' => false])
                  ->addColumn('device_id', 'string', ['limit' => 128, 'null' => true])
                  ->addColumn('device_name', 'string', ['limit' => 128, 'null' => true])
                  ->addColumn('platform', 'string', ['limit' => 32, 'default' => 'android', 'null' => false])
                  ->addColumn('app_version', 'string', ['limit' => 32, 'null' => true])
                  ->addColumn('is_active', 'integer', ['limit' => 1, 'default' => 1, 'null' => false])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['user_id'])
                  ->addIndex(['extension'])
                  ->addIndex(['fcm_token'], ['limit' => 255])
                  ->addIndex(['device_id'])
                  ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_mobile_devices')) {
            $this->table('sys_mobile_devices')->drop()->save();
        }
    }
}
