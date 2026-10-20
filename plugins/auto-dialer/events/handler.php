<?php
/**
 * Event Hook for `auto-dialer` plugin (`call.ended`, `call.missed`).
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static function (string $eventName, array $envelope, array $settings): void {
    $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    $uid = trim((string)($data['uniqueid'] ?? ''));
    $billsec = (int)($data['billsec'] ?? 0);
    if ($uid !== '' && $billsec > 0) {
        try {
            $stmt = getDB()->prepare(
                'UPDATE plg_autodial_numbers SET duration = GREATEST(duration, ?) WHERE uniqueid = ?'
            );
            $stmt->execute([$billsec, $uid]);
        } catch (\Throwable $e) {
        }
    }
};
