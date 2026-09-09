<?php

class MyPhoneRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    /**
     * Get user details and extension info
     */
    public static function getUserExtensionDetails(int $userId): ?array
    {
        $stmt = static::db()->prepare(
            "SELECT id, username, full_name, email, extension, sip_password, sip_auth_digest,
                    role, allowed_phone_mode, dnd_enabled, call_forward_number,
                    cf_busy_number, cf_noanswer_number, cf_noanswer_timeout,
                    cid_internal, cid_external, outbound_group, is_active
             FROM sys_users
             WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get call statistics for a specific extension
     */
    public static function getCallStats(string $ext): array
    {
        if ($ext === '') {
            return [
                'total_calls' => 0,
                'incoming_calls' => 0,
                'outgoing_calls' => 0,
                'missed_calls' => 0,
                'total_billsec' => 0,
            ];
        }

        $allCalls = static::getRecentCalls($ext, null, null, 500);
        $total = count($allCalls);
        $in = 0;
        $out = 0;
        $missed = 0;
        $billsec = 0;

        foreach ($allCalls as $c) {
            if ($c['direction'] === 'in') {
                $in++;
                $billsec += (int)$c['billsec'];
            } elseif ($c['direction'] === 'out') {
                $out++;
                $billsec += (int)$c['billsec'];
            } elseif ($c['direction'] === 'missed') {
                $missed++;
            }
        }

        return [
            'total_calls' => $total,
            'incoming_calls' => $in,
            'outgoing_calls' => $out,
            'missed_calls' => $missed,
            'total_billsec' => $billsec,
        ];
    }

    /**
     * Get recent calls for a specific extension with filtering
     */
    public static function getRecentCalls(string $ext, ?string $filter = null, ?string $search = null, int $limit = 50): array
    {
        if ($ext === '') {
            return [];
        }

        // ONCELIK ACIK: ayni deger bir kullanicinin extension'i, baskasinin
        // cid_internal'i olabilir. ORDER BY olmadan LIMIT 1 hangi satirin
        // donecegini garanti etmiyordu ve tum CDR sorgusu bu satirdan
        // turetiliyor — yanlis satir, BASKASININ cagri gecmisi demek
        // (2026-09-05 denetimi, bulgu2.md B2-2). Gercek dahili her zaman kazanir.
        $userStmt = static::db()->prepare(
            "SELECT extension, cid_internal FROM sys_users
              WHERE extension = ? OR cid_internal = ?
              ORDER BY (extension = ?) DESC, id ASC
              LIMIT 1"
        );
        $userStmt->execute([$ext, $ext, $ext]);
        $u = $userStmt->fetch(PDO::FETCH_ASSOC);
        $extPrimary = !empty($u['extension']) ? $u['extension'] : $ext;
        $cidInternal = !empty($u['cid_internal']) ? $u['cid_internal'] : $extPrimary;

        $extList = array_values(array_unique(array_filter([$extPrimary, $cidInternal])));
        $inPlaceholders = implode(',', array_fill(0, count($extList), '?'));

        // Fetch user full_name directory map for resolving party names
        $nameRows = static::db()->query("SELECT extension, full_name FROM sys_users WHERE full_name IS NOT NULL AND full_name != ''")->fetchAll(PDO::FETCH_KEY_PAIR);

        $fetchLimit = max((int)$limit * 3, 100);
        $sql = "SELECT id, calldate, clid, src, dst, duration, billsec, disposition, channel, dstchannel, linkedid, uniqueid, userfield
                FROM asteriskcdr
                WHERE (
                    src IN ($inPlaceholders)
                    OR dst IN ($inPlaceholders)
                    OR channel LIKE ?
                    OR dstchannel LIKE ?
                )
                AND channel NOT LIKE 'Local/%'
                AND channel NOT LIKE 'CLIEval/%'
                AND dst != 's'
                ORDER BY calldate DESC LIMIT " . (int)$fetchLimit;

        $params = array_merge($extList, $extList, ["PJSIP/{$extPrimary}-%", "PJSIP/{$extPrimary}-%"]);
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Deduplicate multi-leg records of the same call (linkedid/uniqueid)
        $dedup = [];
        foreach ($rows as $row) {
            $linkId = !empty($row['linkedid']) ? $row['linkedid'] : $row['uniqueid'];
            if (!isset($dedup[$linkId])) {
                $dedup[$linkId] = $row;
            } else {
                if ($row['disposition'] === 'ANSWERED' && $dedup[$linkId]['disposition'] !== 'ANSWERED') {
                    $dedup[$linkId] = $row;
                } elseif ((int)$row['billsec'] > (int)$dedup[$linkId]['billsec']) {
                    $dedup[$linkId] = $row;
                }
            }
        }

        $calls = [];
        foreach ($dedup as $row) {
            $isOutgoing = (
                in_array($row['src'], $extList, true)
                || (isset($row['channel']) && strpos($row['channel'], "PJSIP/{$extPrimary}-") === 0)
            );

            if ($isOutgoing) {
                $dir = 'out';
                $party = $row['dst'];
            } else {
                $isMissed = ($row['disposition'] !== 'ANSWERED');
                $dir = $isMissed ? 'missed' : 'in';
                $party = $row['src'];
                if ($party === '' && !empty($row['clid']) && preg_match('/<(\+?[0-9]+)>/', $row['clid'], $m)) {
                    $party = $m[1];
                }
            }

            if (empty($party) || $party === 's') {
                continue;
            }

            // Apply filter
            if ($filter === 'in' && $dir !== 'in') {
                continue;
            }
            if ($filter === 'out' && $dir !== 'out') {
                continue;
            }
            if ($filter === 'missed' && $dir !== 'missed') {
                continue;
            }

            // Extract caller name if in clid: "Name" <num> or from user directory
            $partyName = '';
            if (!empty($row['clid']) && preg_match('/"([^"]+)"/', $row['clid'], $m)) {
                $partyName = trim($m[1]);
            }
            if (empty($partyName) && isset($nameRows[$party])) {
                $partyName = $nameRows[$party];
            }

            // Apply search
            if (!empty($search)) {
                $q = mb_strtolower($search, 'UTF-8');
                $matched = (
                    str_contains(mb_strtolower($party, 'UTF-8'), $q) ||
                    str_contains(mb_strtolower($partyName, 'UTF-8'), $q)
                );
                if (!$matched) {
                    continue;
                }
            }

            $dev = '';
            $allChans = ($row['channel'] ?? '') . ' ' . ($row['dstchannel'] ?? '');
            if (str_contains($allChans, '-mob-webrtc')) {
                $dev = 'mobil';
            } elseif (str_contains($allChans, '-webrtc')) {
                $dev = 'webrtc';
            } elseif (str_contains($allChans, '-sip')) {
                $dev = 'sip';
            }

            $recPath = $row['userfield'] ?? '';
            $hasRec = !empty($recPath) && file_exists($recPath);
            $dur = (int)$row['duration'];
            $bill = (int)$row['billsec'];
            $totalDur = max($dur, $bill);
            $ringSec = max(0, $totalDur - $bill);

            $calls[] = [
                'id' => (int)$row['id'],
                'calldate' => $row['calldate'],
                'direction' => $dir,
                'party' => $party,
                'party_name' => $partyName,
                'duration' => $totalDur,
                'billsec' => $bill,
                'ring_sec' => $ringSec,
                'disposition' => $row['disposition'],
                'channel' => $row['channel'] ?? '',
                'dstchannel' => $row['dstchannel'] ?? '',
                'device_type' => $dev,
                'has_recording' => $hasRec,
                'recording_path' => $recPath,
            ];

            if (count($calls) >= $limit) {
                break;
            }
        }

        return $calls;
    }

    /**
     * Update user phone preferences
     */
    public static function updatePhoneSettings(
        int $userId,
        int $dnd,
        string $forward,
        string $mode,
        string $forwardBusy = '',
        string $forwardNoAnswer = '',
        int $noAnswerTimeout = 20
    ): bool {
        $stmt = static::db()->prepare(
            "UPDATE sys_users
             SET dnd_enabled = ?,
                 call_forward_number = ?,
                 cf_busy_number = ?,
                 cf_noanswer_number = ?,
                 cf_noanswer_timeout = ?,
                 allowed_phone_mode = ?
             WHERE id = ?"
        );
        return $stmt->execute([
            $dnd,
            $forward !== '' ? $forward : null,
            $forwardBusy !== '' ? $forwardBusy : null,
            $forwardNoAnswer !== '' ? $forwardNoAnswer : null,
            max(5, min(120, $noAnswerTimeout)),
            $mode,
            $userId
        ]);
    }

    /**
     * Get internal directory of all active extensions
     */
    public static function getInternalDirectory(): array
    {
        $stmt = static::db()->query(
            "SELECT extension, full_name, role
             FROM sys_users
             WHERE extension IS NOT NULL AND extension != '' AND is_active = 1
             ORDER BY extension ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get active mobile devices registered for user
     */
    public static function getUserMobileDevices(int $userId): array
    {
        $stmt = static::db()->prepare(
            "SELECT id, device_id, device_name, platform, app_version, is_active, updated_at
             FROM sys_mobile_devices
             WHERE user_id = ? AND is_active = 1
             ORDER BY updated_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
