<?php

use Phinx\Migration\AbstractMigration;

final class UpdateSysMobileDevicesFcmAndPushType extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_mobile_devices')) {
            $table = $this->table('sys_mobile_devices');
            
            // fcm_token kolonunu NULL olabilir yap
            $table->changeColumn('fcm_token', 'string', ['limit' => 512, 'null' => true, 'default' => null]);
            
            // push_type kolonu yoksa ekle
            if (!$table->hasColumn('push_type')) {
                $table->addColumn('push_type', 'string', ['limit' => 32, 'default' => 'none', 'null' => false, 'after' => 'platform']);
            }
            
            $table->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_mobile_devices')) {
            $table = $this->table('sys_mobile_devices');
            if ($table->hasColumn('push_type')) {
                $table->removeColumn('push_type');
            }
            $table->changeColumn('fcm_token', 'string', ['limit' => 512, 'null' => false]);
            $table->update();
        }
    }
}
