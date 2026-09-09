<?php

use Phinx\Migration\AbstractMigration;

final class ExpandAllowedPhoneMode extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_users')) {
            $table = $this->table('sys_users');
            $table->changeColumn('allowed_phone_mode', 'string', [
                'limit' => 100,
                'default' => 'web,mobil,sip,video',
                'null' => true
            ]);
            $table->update();

            $this->execute("UPDATE sys_users SET allowed_phone_mode = 'web,mobil,sip,video' WHERE allowed_phone_mode = 'both' OR allowed_phone_mode IS NULL OR allowed_phone_mode = ''");
            $this->execute("UPDATE sys_users SET allowed_phone_mode = 'web,mobil,video' WHERE allowed_phone_mode = 'webrtc_only'");
            $this->execute("UPDATE sys_users SET allowed_phone_mode = 'sip' WHERE allowed_phone_mode = 'sip_only'");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_users')) {
            $this->execute("UPDATE sys_users SET allowed_phone_mode = 'sip_only' WHERE allowed_phone_mode = 'sip'");
            $this->execute("UPDATE sys_users SET allowed_phone_mode = 'webrtc_only' WHERE allowed_phone_mode LIKE '%web%' AND allowed_phone_mode NOT LIKE '%sip%'");
            $this->execute("UPDATE sys_users SET allowed_phone_mode = 'both' WHERE allowed_phone_mode LIKE '%sip%' AND (allowed_phone_mode LIKE '%web%' OR allowed_phone_mode LIKE '%mobil%')");

            $table = $this->table('sys_users');
            $table->changeColumn('allowed_phone_mode', 'string', [
                'limit' => 20,
                'default' => 'both',
                'null' => true
            ]);
            $table->update();
        }
    }
}
