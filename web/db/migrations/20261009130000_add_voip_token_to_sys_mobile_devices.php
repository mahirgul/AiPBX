<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * iOS devices register two push tokens: the regular APNs token (stored in
 * fcm_token, push_type = 'apns') for chat/test notifications, and the PushKit
 * VoIP token used to wake the app for an incoming call (CallKit).
 */
final class AddVoipTokenToSysMobileDevices extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('sys_mobile_devices');
        if (!$table->hasColumn('voip_token')) {
            $table->addColumn('voip_token', 'string', ['limit' => 512, 'null' => true, 'default' => null, 'after' => 'fcm_token'])
                  ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('sys_mobile_devices');
        if ($table->hasColumn('voip_token')) {
            $table->removeColumn('voip_token')->update();
        }
    }
}
