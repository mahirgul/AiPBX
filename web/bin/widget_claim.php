#!/usr/bin/env php
<?php
/**
 * Called by the dialplan when a website widget call arrives (see
 * src/sync/SyncWidgets.php): spends the call's one-time token.
 *
 * Usage: widget_claim.php <widget id> <token>
 * Prints "OK|<caller name>" or "DENY|<reason>" without a newline (read with
 * SHELL() and CUT() in the dialplan).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/WebWidgetService.php';

if (php_sapi_name() !== 'cli') {
    exit(1);
}

$widgetId = (int) ($argv[1] ?? 0);
$token = strtolower((string) ($argv[2] ?? ''));

try {
    echo WebWidgetService::claim($widgetId, $token);
} catch (\Throwable $e) {
    fwrite(STDERR, 'widget_claim: ' . $e->getMessage() . "\n");
    echo 'DENY|error';
}
