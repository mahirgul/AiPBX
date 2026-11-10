<?php
/** Sends enabled Teams event notifications through the validated shared transport. */
require_once dirname(__DIR__, 3) . '/web/src/services/MsTeamsService.php';
return static function (string $eventName, array $envelope, array $settings): void {
    MsTeamsService::notifyEvent($eventName, $envelope);
};
