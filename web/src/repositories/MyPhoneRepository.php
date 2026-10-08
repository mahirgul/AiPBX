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
            "SELECT u.id, u.username, u.full_name, u.email, u.extension, u.sip_password, u.sip_auth_digest,
                    u.role, COALESCE(r.role_name, u.role) AS role_name, u.allowed_phone_mode, dnd_enabled, call_forward_number,
                    cf_busy_number, cf_noanswer_number, cf_noanswer_timeout,
                    cid_internal, cid_external, outbound_group, is_active,
                    voicemail_enabled, voicemail_pin, voicemail_email, voicemail_email_notify, voicemail_attach_audio,
                    vm_on_noanswer, vm_on_busy, vm_on_unavail, vm_always
             FROM sys_users u
             LEFT JOIN sys_roles r ON r.role_key = u.role
             WHERE u.id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? localizeRole($row) : null;
    }

    /**
     * Get call statistics for a specific extension
     */
    public static function getCallStats(string $ext, ?int $userId = null): array
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

        $allCalls = static::getRecentCalls($ext, null, null, 500, $userId);
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
    public static function getRecentCalls(string $ext, ?string $filter = null, ?string $search = null, int $limit = 50, ?int $userId = null): array
    {
        if ($ext === '') {
            return [];
        }

        // EXPLICIT PRIORITY: the same value can be one user's extension and
        // another user's cid_internal. Without ORDER BY, LIMIT 1 did not
        // guarantee which row came back, and the whole CDR query is derived
        // from this row — the wrong row means SOMEBODY ELSE's call history
        // (2026-09-05 audit, bulgu2.md B2-2). The real extension always wins.
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
                %CLEARED%
                ORDER BY calldate DESC, id DESC LIMIT " . (int)$fetchLimit;

        $params = array_merge($extList, $extList, ["PJSIP/{$extPrimary}-%", "PJSIP/{$extPrimary}-%"]);

        // Calls the user removed from their history (#10): the CDR stays, they are only left out here.
        $hidden = [];
        $clearedAt = $userId ? static::callHistoryClearedAt($userId) : null;
        if ($clearedAt !== null) {
            $params[] = $clearedAt;
        }
        $sql = str_replace('%CLEARED%', $clearedAt !== null ? 'AND calldate > ?' : '', $sql);
        if ($userId) {
            $st = static::db()->prepare('SELECT call_key FROM sys_call_history_hidden WHERE user_id = ?');
            $st->execute([$userId]);
            $hidden = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        }
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
        foreach ($dedup as $callKey => $row) {
            if (isset($hidden[(string)$callKey])) {
                continue;
            }
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
                'call_key' => (string)$callKey,
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

    /** Time of the user's last "clear call history", or null. */
    public static function callHistoryClearedAt(int $userId): ?string
    {
        $st = static::db()->prepare('SELECT call_history_cleared_at FROM sys_users WHERE id = ?');
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    }

    /**
     * Remove calls from the user's own history (#10). Only the list changes:
     * the CDR, reports and recordings are not touched. Keys are the
     * call_key values of getRecentCalls().
     */
    public static function hideCalls(int $userId, array $callKeys): int
    {
        $keys = array_values(array_unique(array_filter(array_map(
            fn($k) => preg_replace('/[^A-Za-z0-9._-]/', '', (string)$k), $callKeys
        ), fn($k) => $k !== '' && strlen($k) <= 64)));
        if (!$keys) {
            return 0;
        }
        $st = static::db()->prepare('INSERT IGNORE INTO sys_call_history_hidden (user_id, call_key) VALUES (?, ?)');
        $n = 0;
        foreach (array_slice($keys, 0, 500) as $k) {
            $st->execute([$userId, $k]);
            $n += $st->rowCount();
        }
        return $n;
    }

    /** "Clear call history": everything up to now is left out of the user's list. */
    public static function clearCallHistory(int $userId): void
    {
        static::db()->prepare('UPDATE sys_users SET call_history_cleared_at = NOW() WHERE id = ?')->execute([$userId]);
        // Single hidden calls are older than the clear time now and no longer needed.
        static::db()->prepare('DELETE FROM sys_call_history_hidden WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * Update the user's call settings (DND, forwarding, phone modes).
     * Voicemail has its own form and method: updateVoicemailSettings().
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
     * Update the user's voicemail settings. Every switch must be given: the
     * form sends a box only when it is ticked, the controller turns a missing
     * one into 0. An empty PIN keeps the current PIN.
     */
    public static function updateVoicemailSettings(int $userId, array $vm): bool
    {
        $flag = fn(string $k): int => !empty($vm[$k]) ? 1 : 0;
        $pin = preg_replace('/[^0-9]/', '', (string)($vm['voicemail_pin'] ?? ''));
        $email = trim((string)($vm['voicemail_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(t('my_phone.vm_email_invalid'));
        }

        $stmt = static::db()->prepare(
            "UPDATE sys_users
             SET voicemail_enabled = ?,
                 voicemail_pin = CASE WHEN ? != '' THEN ? ELSE voicemail_pin END,
                 voicemail_email = ?,
                 voicemail_email_notify = ?,
                 voicemail_attach_audio = ?,
                 vm_on_noanswer = ?,
                 vm_on_busy = ?,
                 vm_on_unavail = ?,
                 vm_always = ?
             WHERE id = ?"
        );
        return $stmt->execute([
            $flag('voicemail_enabled'),
            $pin,
            $pin,
            $email,
            $flag('voicemail_email_notify'),
            $flag('voicemail_attach_audio'),
            $flag('vm_on_noanswer'),
            $flag('vm_on_busy'),
            $flag('vm_on_unavail'),
            $flag('vm_always'),
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
