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

        // Fetch user full_name directory map for resolving party names (both by extension and cid_internal)
        $nameRows = [];
        $users = static::db()->query("SELECT extension, cid_internal, full_name FROM sys_users WHERE full_name IS NOT NULL AND full_name != ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            $fn = trim($u['full_name'] ?? '');
            if ($fn === '') {
                continue;
            }
            if (!empty($u['extension'])) {
                $nameRows[$u['extension']] = $fn;
            }
            if (!empty($u['cid_internal'])) {
                $nameRows[$u['cid_internal']] = $fn;
            }
        }

        $fetchLimit = max((int)$limit * 3, 100);
        $sql = "SELECT id, calldate, clid, src, dst, duration, billsec, disposition, channel, dstchannel, linkedid, uniqueid, userfield, lastapp, lastdata, dcontext
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
                ORDER BY calldate DESC, id DESC LIMIT " . (int)$fetchLimit;

        $params = array_merge($extList, $extList, ["PJSIP/{$extPrimary}-%", "PJSIP/{$extPrimary}-%"]);
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Quality scoring function to select the primary leg of multi-leg calls
        $statusWords = ['CHANUNAVAIL', 'NOANSWER', 'BUSY', 'CONGESTION', 'CANCEL', 'ANSWER', 'DONTCALL', 'TORTURE', 'INVALIDARGS', 'S', 'H', 'T', 'I', 'E'];
        $legScore = function(array $r) use ($extList, $statusWords): int {
            $score = 0;
            if (($r['disposition'] ?? '') === 'ANSWERED') {
                $score += 1000;
            }
            $score += min((int)($r['billsec'] ?? 0), 500);

            $dstUpper = strtoupper(trim((string)($r['dst'] ?? '')));
            $isStatusDst = in_array($dstUpper, $statusWords, true);

            // Deprioritize sub-outbound-status context and Hangup trampoline legs
            if (($r['dcontext'] ?? '') === 'sub-outbound-status') {
                $score -= 200;
            }
            if (($r['lastapp'] ?? '') === 'Dial') {
                $score += 100;
            } elseif (($r['lastapp'] ?? '') === 'Queue') {
                $score += 80;
            } elseif (($r['lastapp'] ?? '') === 'Hangup') {
                $score -= 50;
            }

            // Reward real destination number (not status word or self-extension)
            if (!$isStatusDst && $dstUpper !== '' && !in_array($r['dst'], $extList, true)) {
                $score += 50;
            }
            if (!empty($r['userfield'])) {
                $score += 20;
            }
            if (!empty($r['dstchannel'])) {
                $score += 10;
            }
            return $score;
        };

        // Deduplicate multi-leg records of the same call (linkedid/uniqueid)
        $dedup = [];
        foreach ($rows as $row) {
            $linkId = !empty($row['linkedid']) ? $row['linkedid'] : $row['uniqueid'];
            if (!isset($dedup[$linkId])) {
                $dedup[$linkId] = $row;
            } else {
                $prev = $dedup[$linkId];
                if ($legScore($row) > $legScore($prev)) {
                    // Row is higher quality; preserve missing metadata from prev if row lacks them
                    if (empty($row['userfield']) && !empty($prev['userfield'])) {
                        $row['userfield'] = $prev['userfield'];
                    }
                    if (empty($row['dstchannel']) && !empty($prev['dstchannel'])) {
                        $row['dstchannel'] = $prev['dstchannel'];
                    }
                    if (empty($row['lastdata']) && !empty($prev['lastdata'])) {
                        $row['lastdata'] = $prev['lastdata'];
                    }
                    $dedup[$linkId] = $row;
                } else {
                    // Prev is better; absorb missing metadata from row
                    if (empty($prev['userfield']) && !empty($row['userfield'])) {
                        $dedup[$linkId]['userfield'] = $row['userfield'];
                    }
                    if (empty($prev['dstchannel']) && !empty($row['dstchannel'])) {
                        $dedup[$linkId]['dstchannel'] = $row['dstchannel'];
                    }
                    if (empty($prev['lastdata']) && !empty($row['lastdata'])) {
                        $dedup[$linkId]['lastdata'] = $row['lastdata'];
                    }
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
                $dstRaw = trim((string)($row['dst'] ?? ''));
                $dstUpper = strtoupper($dstRaw);
                $isInvalidDst = (
                    $dstRaw === '' ||
                    $dstRaw === 's' ||
                    in_array($dstUpper, $statusWords, true) ||
                    in_array($dstRaw, $extList, true)
                );

                // 1. Extract destination from recording userfield (/.../outbound_..._to_<num>.wav)
                $targetFromUserfield = '';
                if (!empty($row['userfield']) && preg_match('/[_\/]to_([0-9+*#]+)(?:\.[a-zA-Z0-9]+)?$/i', $row['userfield'], $m)) {
                    $targetFromUserfield = $m[1];
                }

                // 2. Extract destination from lastdata (e.g. PJSIP/<num>@trunk)
                $targetFromLastdata = '';
                if (!empty($row['lastdata']) && preg_match('~(?:PJSIP|SIP|Local)/([0-9+*#]+)@~i', $row['lastdata'], $m)) {
                    $targetFromLastdata = $m[1];
                }

                if ($targetFromUserfield !== '' && !in_array($targetFromUserfield, $extList, true)) {
                    $party = $targetFromUserfield;
                } elseif ($targetFromLastdata !== '' && !in_array($targetFromLastdata, $extList, true)) {
                    $party = $targetFromLastdata;
                } elseif (!$isInvalidDst) {
                    $party = $dstRaw;
                } else {
                    $party = $targetFromUserfield !== '' ? $targetFromUserfield : ($targetFromLastdata !== '' ? $targetFromLastdata : $dstRaw);
                }
            } else {
                $isMissed = ($row['disposition'] !== 'ANSWERED');
                $dir = $isMissed ? 'missed' : 'in';
                $party = trim((string)($row['src'] ?? ''));
                if (($party === '' || $party === 's') && !empty($row['clid']) && preg_match('/<(\+?[0-9]+)>/', $row['clid'], $m)) {
                    $party = $m[1];
                }
                if ($party === '' || $party === 's') {
                    if (!empty($row['userfield']) && preg_match('/inbound_[0-9]+_[0-9]+_([0-9+*#]+)_to_/i', $row['userfield'], $m)) {
                        $party = $m[1];
                    }
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

            // Extract party name:
            // For INCOMING calls, caller name is in clid ("Name" <num>).
            // For OUTGOING calls, clid is the caller's own identity, so recipient name must ONLY come from directory.
            $partyName = '';
            if (!$isOutgoing && !empty($row['clid']) && preg_match('/"([^"]+)"/', $row['clid'], $m)) {
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
