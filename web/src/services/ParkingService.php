<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../internal_numbers.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Call Parking configuration service (pbx_parking / res_parking.conf).
 */
class ParkingService
{
    public const DEFAULT_CONFIG = [
        'id' => 1,
        'is_active' => 0,
        'parkext' => '700',
        'slot_start' => 701,
        'slot_end' => 710,
        'parking_time' => 45,
        'findslot' => 'next',
        'comeback_to_origin' => 1,
        'comeback_dial_time' => 30,
        'timeout_dest_type' => null,
        'timeout_dest_id' => null,
        'moh_class' => 'default',
        'parked_play' => 'caller',
    ];

    public static function getConfig(): array
    {
        try {
            $row = getDB()->query('SELECT * FROM pbx_parking ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            return $row ? array_merge(self::DEFAULT_CONFIG, $row) : self::DEFAULT_CONFIG;
        } catch (\Throwable $e) {
            return self::DEFAULT_CONFIG;
        }
    }

    public static function save(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }

        $isActive = !empty($post['is_active']) ? 1 : 0;
        $parkext = internalNumberSanitize((string) ($post['parkext'] ?? '700'));
        $slotStart = (int) ($post['slot_start'] ?? 701);
        $slotEnd = (int) ($post['slot_end'] ?? 710);
        $parkingTime = (int) ($post['parking_time'] ?? 45);
        $findslot = (($post['findslot'] ?? 'next') === 'first') ? 'first' : 'next';
        $comebackToOrigin = !empty($post['comeback_to_origin']) ? 1 : 0;
        $comebackDialTime = (int) ($post['comeback_dial_time'] ?? 30);
        $mohClass = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) ($post['moh_class'] ?? 'default'))) ?: 'default';
        $parkedPlay = (string) ($post['parked_play'] ?? 'caller');
        if (!in_array($parkedPlay, ['caller', 'parked', 'both', 'no'], true)) {
            $parkedPlay = 'caller';
        }

        $timeoutDestType = trim((string) ($post['timeout_dest_type'] ?? ''));
        $timeoutDestId = trim((string) ($post['timeout_dest_id'] ?? ''));
        if ($timeoutDestType === '') {
            $timeoutDestType = null;
            $timeoutDestId = null;
        } else {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $timeoutDestType)) {
                return ['success' => false, 'error' => t('parking.err_invalid_dest')];
            }
            if ($timeoutDestId === '') {
                return ['success' => false, 'error' => t('parking.err_invalid_dest')];
            }
        }

        if ($parkext === '' || strlen($parkext) < 2 || strlen($parkext) > 8) {
            return ['success' => false, 'error' => t('parking.err_parkext')];
        }
        if ($slotStart < 10 || $slotEnd < $slotStart || $slotEnd > 99999999) {
            return ['success' => false, 'error' => t('parking.err_slot_range')];
        }
        if (($slotEnd - $slotStart) > 49) {
            return ['success' => false, 'error' => t('parking.err_slot_count')];
        }
        $parkextInt = (int) $parkext;
        if ($parkextInt >= $slotStart && $parkextInt <= $slotEnd) {
            return ['success' => false, 'error' => t('parking.err_parkext_in_slots')];
        }
        if ($parkingTime < 10 || $parkingTime > 600) {
            return ['success' => false, 'error' => t('parking.err_parking_time')];
        }
        if ($comebackDialTime < 5 || $comebackDialTime > 120) {
            return ['success' => false, 'error' => t('parking.err_comeback_time')];
        }

        if ($isActive === 1) {
            $numbersToCheck = [$parkext];
            for ($s = $slotStart; $s <= $slotEnd; $s++) {
                $numbersToCheck[] = (string) $s;
            }
            foreach ($numbersToCheck as $num) {
                $owner = internalNumberOwner($num, 'parking');
                if ($owner !== null) {
                    return ['success' => false, 'error' => sprintf(t('parking.err_conflict'), $num, $owner)];
                }
            }
        }

        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO pbx_parking (
                id, is_active, parkext, slot_start, slot_end, parking_time,
                findslot, comeback_to_origin, comeback_dial_time,
                timeout_dest_type, timeout_dest_id, moh_class, parked_play
            ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_active = VALUES(is_active),
                parkext = VALUES(parkext),
                slot_start = VALUES(slot_start),
                slot_end = VALUES(slot_end),
                parking_time = VALUES(parking_time),
                findslot = VALUES(findslot),
                comeback_to_origin = VALUES(comeback_to_origin),
                comeback_dial_time = VALUES(comeback_dial_time),
                timeout_dest_type = VALUES(timeout_dest_type),
                timeout_dest_id = VALUES(timeout_dest_id),
                moh_class = VALUES(moh_class),
                parked_play = VALUES(parked_play)
        ");
        $stmt->execute([
            $isActive,
            $parkext,
            $slotStart,
            $slotEnd,
            $parkingTime,
            $findslot,
            $comebackToOrigin,
            $comebackDialTime,
            $timeoutDestType,
            $timeoutDestId,
            $mohClass,
            $parkedPlay,
        ]);

        markPendingSync('parking', 'parking', 1, "Call Parking ({$parkext} / {$slotStart}-{$slotEnd})", 'update', $_SESSION['user_id'] ?? null);
        markPendingSync('general_dialplan', 'parking', 1, "Call Parking ({$parkext} / {$slotStart}-{$slotEnd})", 'update', $_SESSION['user_id'] ?? null);
        writeAuditLog('pbx', 'parking', 1, "Call Parking ({$parkext} / {$slotStart}-{$slotEnd}, active={$isActive})", 'update', $_SESSION['user_id'] ?? null);

        return ['success' => true, 'message' => t('parking.msg_saved')];
    }

    /**
     * The calls parked now, from "parking show default" (Asterisk 22 prints one
     * "Space : / Channel : / Parker Dial String :" block per call).
     * @return list<array{space: string, channel: string, from: string}>
     */
    public static function liveParkedCalls(): array
    {
        try {
            $res = AsteriskHelper::execCLI('parking show default');
            return self::parseParkedCalls(!empty($res['success']) ? (string) ($res['output'] ?? '') : '');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return list<array{space: string, channel: string, from: string}> */
    public static function parseParkedCalls(string $output): array
    {
        $calls = [];
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^\s*(Space|Channel|Parker Dial String)\s*:\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }
            if ($m[1] === 'Space') {
                $calls[] = ['space' => $m[2], 'channel' => '', 'from' => ''];
            } elseif ($calls) {
                $calls[count($calls) - 1][$m[1] === 'Channel' ? 'channel' : 'from'] = $m[2];
            }
        }
        return $calls;
    }
}
