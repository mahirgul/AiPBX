<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Signaling capture is part of AiPBX itself from 1.50.8 (PBX settings →
 * Signaling), no longer the bundled signaling-capture plugin.
 *
 * The plugin pointed Asterisk's HEP output at 127.0.0.1:9060, where nothing
 * listens, so turning it on stopped the local capture instead of starting it.
 * An installation that had it on keeps capture on (the setting below); the
 * plugin's rows go. Its generated hep_plugin_signaling-capture.conf is
 * removed by syncSignalingCapture() on the update's full sync.
 */
final class RetireSignalingCapturePlugin extends AbstractMigration
{
    private const SLUG = 'signaling-capture';

    public function up(): void
    {
        if (!$this->hasTable('sys_plugins') || !$this->hasTable('sys_settings')) {
            return;
        }
        $on = $this->fetchRow("SELECT enabled FROM sys_plugins WHERE slug = '" . self::SLUG . "'");
        $hep = $this->hasTable('sys_plugin_settings')
            ? $this->fetchRow("SELECT setting_value FROM sys_plugin_settings WHERE plugin_slug = '" . self::SLUG . "' AND setting_key = 'hep_enabled'")
            : false;
        if ($on && (int) $on['enabled'] === 1 && (!$hep || $hep['setting_value'] !== '0')) {
            $this->execute("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('signaling_capture', '1')
                ON DUPLICATE KEY UPDATE setting_value = setting_value");
        }
        foreach (['sys_plugin_settings', 'sys_plugin_kv'] as $table) {
            if ($this->hasTable($table)) {
                $this->execute("DELETE FROM {$table} WHERE plugin_slug = '" . self::SLUG . "'");
            }
        }
        $this->execute("DELETE FROM sys_plugins WHERE slug = '" . self::SLUG . "'");
    }

    public function down(): void
    {
        // The plugin is gone from the code; nothing to bring back.
    }
}
