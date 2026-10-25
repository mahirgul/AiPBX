<?php
declare(strict_types=1);

/**
 * Data Retention Auto-Purge & Subject Access Request (SAR) / Right-to-Erasure
 * helper for PrivacyService across dual CDR and media tables.
 */
final class PrivacyDataSubjectHelper
{
    /**
     * Execute retention purge now (either from portal button or nightly cron).
     *
     * @param array<string, mixed> $cfg
     * @param callable(array<string, string>): void $upsertSettings
     * @return array{counts: array<string, int>, summary: string}
     */
    public static function runRetentionPurge(array $cfg, callable $upsertSettings): array
    {
        $db = getDB();
        $counts = [
            'recordings' => 0,
            'voicemail'  => 0,
            'fax'        => 0,
            'chat'       => 0,
            'cdr'        => 0,
        ];

        // 1. Call recordings older than ret_recordings_days
        $recDays = (int)$cfg['ret_recordings_days'];
        if ($recDays > 0) {
            $cutoffTs = time() - ($recDays * 86400);
            $monitorDir = '/var/spool/asterisk/monitor';
            if (is_dir($monitorDir)) {
                $files = glob($monitorDir . '/*.{wav,mp3,ogg,gsm}', GLOB_BRACE) ?: [];
                foreach ($files as $file) {
                    if (is_file($file) && @filemtime($file) !== false && @filemtime($file) < $cutoffTs) {
                        if (@unlink($file)) {
                            $counts['recordings']++;
                        }
                    }
                }
            }
            try {
                $stmt = $db->prepare("UPDATE asteriskcdr SET userfield = '' WHERE calldate < DATE_SUB(NOW(), INTERVAL ? DAY) AND userfield != ''");
                $stmt->execute([$recDays]);
            } catch (\Throwable $e) {
            }
            try {
                $stmt = $db->prepare("UPDATE cdrs SET recording_path = '' WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY) AND recording_path IS NOT NULL AND recording_path != ''");
                $stmt->execute([$recDays]);
            } catch (\Throwable $e) {
            }
        }

        // 2. Voicemail archive & AI transcripts older than ret_voicemail_days
        $vmDays = (int)$cfg['ret_voicemail_days'];
        if ($vmDays > 0) {
            foreach (['voicemail_archive', 'pbx_voicemail_archive'] as $vmTable) {
                try {
                    $stmt = $db->prepare("DELETE FROM {$vmTable} WHERE origtime < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))");
                    $stmt->execute([$vmDays]);
                    $counts['voicemail'] += $stmt->rowCount();
                } catch (\Throwable $e) {
                }
            }
            foreach (['voicemail_ai', 'pbx_voicemail_ai', 'ai_requests'] as $aiTable) {
                try {
                    $stmt = $db->prepare("DELETE FROM {$aiTable} WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$vmDays]);
                } catch (\Throwable $e) {
                }
            }
        }

        // 3. Fax logs older than ret_fax_days (fax_received, fax_sent, and legacy pbx_fax_log)
        $faxDays = (int)$cfg['ret_fax_days'];
        if ($faxDays > 0) {
            $faxTables = [
                ['table' => 'fax_received', 'ts' => 'received_at', 'cols' => ['tif_path', 'pdf_path']],
                ['table' => 'fax_sent',     'ts' => 'created_at',  'cols' => ['tif_path', 'pdf_path']],
                ['table' => 'pbx_fax_log',  'ts' => 'created_at',  'cols' => ['file_path', 'pdf_path']],
            ];
            foreach ($faxTables as $ft) {
                try {
                    $colList = implode(', ', $ft['cols']);
                    $stmt = $db->prepare("SELECT id, {$colList} FROM {$ft['table']} WHERE {$ft['ts']} < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$faxDays]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        foreach ($ft['cols'] as $col) {
                            $p = (string)($row[$col] ?? '');
                            if ($p !== '' && str_starts_with($p, '/var/spool/asterisk/fax/') && is_file($p)) {
                                @unlink($p);
                            }
                        }
                    }
                    $del = $db->prepare("DELETE FROM {$ft['table']} WHERE {$ft['ts']} < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $del->execute([$faxDays]);
                    $counts['fax'] += $del->rowCount();
                } catch (\Throwable $e) {
                }
            }
        }

        // 4. Chat messages older than ret_chat_days
        $chatDays = (int)$cfg['ret_chat_days'];
        if ($chatDays > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$chatDays]);
                $counts['chat'] += $stmt->rowCount();
            } catch (\Throwable $e) {
            }
        }

        // 5. CDR records older than ret_cdr_days across both asteriskcdr and cdrs/callcenter_notes
        $cdrDays = (int)$cfg['ret_cdr_days'];
        if ($cdrDays > 0) {
            try {
                if ($cfg['ret_cdr_action'] === 'delete') {
                    $stmt = $db->prepare("DELETE FROM asteriskcdr WHERE calldate < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$cdrDays]);
                    $counts['cdr'] += $stmt->rowCount();
                } else {
                    $stmt = $db->prepare(
                        "UPDATE asteriskcdr
                         SET src = 'REDACTED', dst = 'REDACTED', clid = 'REDACTED', userfield = ''
                         WHERE calldate < DATE_SUB(NOW(), INTERVAL ? DAY)
                           AND (src != 'REDACTED' OR dst != 'REDACTED' OR clid != 'REDACTED')"
                    );
                    $stmt->execute([$cdrDays]);
                    $counts['cdr'] += $stmt->rowCount();
                }
            } catch (\Throwable $e) {
            }

            try {
                if ($cfg['ret_cdr_action'] === 'delete') {
                    try {
                        $stmtNotes = $db->prepare("DELETE FROM callcenter_notes WHERE call_id IN (SELECT call_id FROM cdrs WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY))");
                        $stmtNotes->execute([$cdrDays]);
                    } catch (\Throwable $e) {
                    }
                    $stmt = $db->prepare("DELETE FROM cdrs WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$cdrDays]);
                    $counts['cdr'] += $stmt->rowCount();
                } else {
                    try {
                        $stmtNotes = $db->prepare(
                            "UPDATE callcenter_notes
                             SET customer_name = 'REDACTED', phone = 'REDACTED', notes = '[REDACTED]'
                             WHERE call_id IN (SELECT call_id FROM cdrs WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY))"
                        );
                        $stmtNotes->execute([$cdrDays]);
                    } catch (\Throwable $e) {
                    }
                    $stmt = $db->prepare(
                        "UPDATE cdrs
                         SET caller_num = 'REDACTED', recording_path = ''
                         WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY)
                           AND caller_num != 'REDACTED'"
                    );
                    $stmt->execute([$cdrDays]);
                    $counts['cdr'] += $stmt->rowCount();
                }
            } catch (\Throwable $e) {
            }
        }

        $now = date('Y-m-d H:i:s');
        $summary = sprintf(
            'recordings=%d, voicemail=%d, fax=%d, chat=%d, cdr=%d',
            $counts['recordings'],
            $counts['voicemail'],
            $counts['fax'],
            $counts['chat'],
            $counts['cdr']
        );
        $upsertSettings([
            'privacy_last_purge_at'      => $now,
            'privacy_last_purge_summary' => $summary,
        ]);

        if (function_exists('writeAuditLog')) {
            writeAuditLog('privacy', 'retention_purge', 'purge', "Retention purge completed: {$summary}", 'delete', $_SESSION['user_id'] ?? null);
        }

        return ['counts' => $counts, 'summary' => $summary];
    }

    /**
     * Subject Access Request (SAR) lookup by phone number / extension across both
     * CDR layers (asteriskcdr + cdrs), voicemail_archive/voicemail_ai, fax_received/fax_sent,
     * ai_requests, and pbx_signaling_events.
     *
     * @return array<string, mixed>|null
     */
    public static function lookupSubject(string $rawNumber): ?array
    {
        $clean = preg_replace('/[^0-9+]/', '', trim($rawNumber)) ?? '';
        $digitsOnly = preg_replace('/[^0-9]/', '', $clean) ?? '';
        if (strlen($digitsOnly) < 3) {
            return null;
        }

        $db = getDB();
        $like = '%' . $digitsOnly . '%';

        $cdrs = [];
        $recordingFiles = [];
        try {
            $stmt = $db->prepare(
                "SELECT calldate, clid, src, dst, dcontext, disposition, duration, billsec, uniqueid, userfield
                 FROM asteriskcdr
                 WHERE src = ? OR dst = ? OR src LIKE ? OR dst LIKE ?
                 ORDER BY calldate DESC LIMIT 200"
            );
            $stmt->execute([$clean, $clean, $like, $like]);
            $cdrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cdrs as $c) {
                $uf = trim((string)($c['userfield'] ?? ''));
                if ($uf !== '' && str_starts_with($uf, '/var/spool/asterisk/monitor/') && is_file($uf)) {
                    $recordingFiles[$uf] = $uf;
                }
            }
        } catch (\Throwable $e) {
        }

        // Also query the call center `cdrs` table when present
        try {
            $stmt = $db->prepare(
                "SELECT start_time AS calldate, caller_num AS clid, caller_num AS src,
                        COALESCE(agent_extension, queue_name, '') AS dst,
                        COALESCE(queue_name, 'cdrs') AS dcontext, status AS disposition,
                        duration, billsec, call_id AS uniqueid, recording_path AS userfield
                 FROM cdrs
                 WHERE caller_num = ? OR agent_extension = ? OR caller_num LIKE ?
                 ORDER BY start_time DESC LIMIT 200"
            );
            $stmt->execute([$clean, $digitsOnly, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
                $uf = trim((string)($cRow['userfield'] ?? ''));
                if ($uf !== '' && str_starts_with($uf, '/var/spool/asterisk/monitor/') && is_file($uf)) {
                    $recordingFiles[$uf] = $uf;
                }
                if (count($cdrs) < 200) {
                    $cdrs[] = $cRow;
                }
            }
        } catch (\Throwable $e) {
        }

        // Also scan monitor filename matches
        $globbed = glob('/var/spool/asterisk/monitor/*' . $digitsOnly . '*.{wav,mp3,ogg}', GLOB_BRACE) ?: [];
        foreach ($globbed as $gf) {
            if (is_file($gf)) {
                $recordingFiles[$gf] = $gf;
            }
        }

        $voicemails = [];
        try {
            $stmt = $db->prepare(
                "SELECT va.id, va.mailbox AS mailboxuser, va.callerid, va.origtime, va.duration,
                        COALESCE(vai.transcript, '') AS transcript
                 FROM voicemail_archive va
                 LEFT JOIN voicemail_ai vai ON vai.mailbox = va.mailbox AND vai.origtime = va.origtime
                 WHERE va.mailbox = ? OR va.callerid LIKE ?
                 ORDER BY va.origtime DESC LIMIT 100"
            );
            $stmt->execute([$digitsOnly, $like]);
            $voicemails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }
        if (empty($voicemails)) {
            try {
                $stmt = $db->prepare(
                    "SELECT id, mailboxuser, callerid, origtime, duration, transcript
                     FROM pbx_voicemail_archive
                     WHERE mailboxuser = ? OR callerid LIKE ?
                     ORDER BY origtime DESC LIMIT 100"
                );
                $stmt->execute([$digitsOnly, $like]);
                $voicemails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
            }
        }

        $faxes = [];
        try {
            $stmt = $db->prepare(
                "SELECT id, 'inbound' AS direction, caller_id, did_extension AS destination,
                        status, pages, received_at AS created_at, tif_path AS file_path, pdf_path
                 FROM fax_received
                 WHERE caller_id LIKE ? OR did_extension LIKE ?
                 ORDER BY received_at DESC LIMIT 100"
            );
            $stmt->execute([$like, $like]);
            $faxes = array_merge($faxes, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $db->prepare(
                "SELECT id, 'outbound' AS direction, sender_extension AS caller_id, destination_number AS destination,
                        status, pages, created_at, tif_path AS file_path, pdf_path
                 FROM fax_sent
                 WHERE sender_extension LIKE ? OR destination_number LIKE ?
                 ORDER BY created_at DESC LIMIT 100"
            );
            $stmt->execute([$like, $like]);
            $faxes = array_merge($faxes, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
        }
        if (empty($faxes)) {
            try {
                $stmt = $db->prepare(
                    "SELECT id, direction, caller_id, destination, status, pages, created_at, file_path, pdf_path
                     FROM pbx_fax_log
                     WHERE caller_id LIKE ? OR destination LIKE ?
                     ORDER BY created_at DESC LIMIT 100"
                );
                $stmt->execute([$like, $like]);
                $faxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
            }
        }

        $signalingCount = 0;
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM pbx_signaling_events WHERE from_uri LIKE ? OR to_uri LIKE ?"
            );
            $stmt->execute([$like, $like]);
            $signalingCount = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
        }

        return [
            'query'           => $clean,
            'digits'          => $digitsOnly,
            'cdr_count'       => count($cdrs),
            'cdrs'            => array_slice($cdrs, 0, 25),
            'all_cdrs'        => $cdrs,
            'recording_count' => count($recordingFiles),
            'recording_files' => array_values($recordingFiles),
            'voicemail_count' => count($voicemails),
            'voicemails'      => $voicemails,
            'fax_count'       => count($faxes),
            'faxes'           => $faxes,
            'signaling_count' => $signalingCount,
        ];
    }

    /**
     * Right to Erasure (KVKK Art. 7 & 11 / GDPR Art. 17 / CCPA / LGPD):
     * Deletes call recordings, voicemail transcripts, fax files/logs, signaling rows,
     * and anonymizes CDR rows across both asteriskcdr and cdrs for the given phone number.
     *
     * @return array<string, mixed>
     */
    public static function eraseSubject(string $rawNumber): array
    {
        $info = self::lookupSubject($rawNumber);
        if ($info === null) {
            return ['success' => false, 'error' => t('privacy.err_subject_short')];
        }

        $db = getDB();
        $clean = $info['query'];
        $digits = $info['digits'];
        $like = '%' . $digits . '%';

        $deletedRecs = 0;
        foreach ($info['recording_files'] as $file) {
            if (is_string($file) && str_starts_with($file, '/var/spool/asterisk/monitor/') && is_file($file)) {
                if (@unlink($file)) {
                    $deletedRecs++;
                }
            }
        }

        $anonCdrs = 0;
        try {
            $stmt = $db->prepare(
                "UPDATE asteriskcdr
                 SET src = IF(src = ? OR src LIKE ?, 'REDACTED', src),
                     dst = IF(dst = ? OR dst LIKE ?, 'REDACTED', dst),
                     clid = 'REDACTED',
                     userfield = ''
                 WHERE src = ? OR dst = ? OR src LIKE ? OR dst LIKE ?"
            );
            $stmt->execute([$clean, $like, $clean, $like, $clean, $clean, $like, $like]);
            $anonCdrs += $stmt->rowCount();
        } catch (\Throwable $e) {
        }

        try {
            try {
                $stmtNotes = $db->prepare(
                    "UPDATE callcenter_notes
                     SET customer_name = 'REDACTED', phone = 'REDACTED', notes = '[REDACTED]'
                     WHERE phone LIKE ? OR call_id IN (SELECT call_id FROM cdrs WHERE caller_num = ? OR caller_num LIKE ?)"
                );
                $stmtNotes->execute([$like, $clean, $like]);
            } catch (\Throwable $e) {
            }
            $stmt = $db->prepare(
                "UPDATE cdrs
                 SET caller_num = 'REDACTED', recording_path = ''
                 WHERE caller_num = ? OR caller_num LIKE ?"
            );
            $stmt->execute([$clean, $like]);
            $anonCdrs += $stmt->rowCount();
        } catch (\Throwable $e) {
        }

        $delVm = 0;
        try {
            $stmt = $db->prepare("DELETE FROM voicemail_ai WHERE mailbox = ?");
            $stmt->execute([$digits]);
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $db->prepare("DELETE FROM voicemail_archive WHERE mailbox = ? OR callerid LIKE ?");
            $stmt->execute([$digits, $like]);
            $delVm += $stmt->rowCount();
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $db->prepare("DELETE FROM pbx_voicemail_archive WHERE mailboxuser = ? OR callerid LIKE ?");
            $stmt->execute([$digits, $like]);
            $delVm += $stmt->rowCount();
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $db->prepare("DELETE FROM ai_requests WHERE caller = ? OR caller LIKE ?");
            $stmt->execute([$clean, $like]);
        } catch (\Throwable $e) {
        }

        $delFax = 0;
        foreach ($info['faxes'] as $fax) {
            foreach (['file_path', 'pdf_path'] as $col) {
                $p = (string)($fax[$col] ?? '');
                if ($p !== '' && str_starts_with($p, '/var/spool/asterisk/fax/') && is_file($p)) {
                    @unlink($p);
                }
            }
        }
        try {
            $stmt = $db->prepare("DELETE FROM fax_received WHERE caller_id LIKE ? OR did_extension LIKE ?");
            $stmt->execute([$like, $like]);
            $delFax += $stmt->rowCount();
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $db->prepare("DELETE FROM fax_sent WHERE sender_extension LIKE ? OR destination_number LIKE ?");
            $stmt->execute([$like, $like]);
            $delFax += $stmt->rowCount();
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $db->prepare("DELETE FROM pbx_fax_log WHERE caller_id LIKE ? OR destination LIKE ?");
            $stmt->execute([$like, $like]);
            $delFax += $stmt->rowCount();
        } catch (\Throwable $e) {
        }

        $delSig = 0;
        try {
            $stmt = $db->prepare("DELETE FROM pbx_signaling_events WHERE from_uri LIKE ? OR to_uri LIKE ?");
            $stmt->execute([$like, $like]);
            $delSig = $stmt->rowCount();
        } catch (\Throwable $e) {
        }

        $maskedTarget = substr($digits, 0, 3) . str_repeat('*', max(2, strlen($digits) - 5)) . substr($digits, -2);
        if (function_exists('writeAuditLog')) {
            writeAuditLog(
                'privacy',
                'subject_erasure',
                $maskedTarget,
                "Erased subject {$maskedTarget}: cdr_anonymized={$anonCdrs}, recordings_deleted={$deletedRecs}, voicemail_deleted={$delVm}, fax_deleted={$delFax}, signaling_deleted={$delSig}",
                'delete',
                $_SESSION['user_id'] ?? null
            );
        }

        return [
            'success' => true,
            'message' => sprintf(
                t('privacy.msg_erased'),
                $anonCdrs,
                $deletedRecs,
                $delVm,
                $delFax
            ),
        ];
    }
}
