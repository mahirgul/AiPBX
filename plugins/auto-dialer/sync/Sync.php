<?php
/**
 * Asterisk Sync Hook for `auto-dialer` plugin.
 * Generates `/etc/asterisk/pbx/extensions_plugin_auto-dialer.conf`.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static function (string $slug, array $settings): void {
    AutoDialerService::syncDialplan($settings);
};
