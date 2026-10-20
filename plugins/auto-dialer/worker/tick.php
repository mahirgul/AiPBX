<?php
/**
 * Background Worker Tick Hook for `auto-dialer` plugin.
 * Invoked periodically by `web/bin/webhook_worker.php` to pace and originate
 * outbound campaign calls.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static function (string $slug, array $settings): void {
    AutoDialerService::tickCampaigns($settings);
};
