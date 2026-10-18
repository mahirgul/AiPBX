<?php
declare(strict_types=1);

/**
 * Global Data Privacy & Telecom Compliance Engine:
 * - KVKK (Turkey — Law No. 6698 & BTK)
 * - GDPR / UK GDPR (EU 2016/679 & ePrivacy)
 * - US CCPA / CPRA, CIPA Two-Party Consent & FCC 47 CFR § 64.1200 (Periodic Recording Beep)
 * - HIPAA (Healthcare PHI Safeguards & Cloud AI Lock)
 * - PCI-DSS v4.0 (Call Center Recording Pause / Card Data Protection)
 * - LGPD (Brazil), PIPEDA (Canada), POPIA (South Africa), PDPA (Singapore), Privacy Act (Australia)
 */
final class PrivacyService
{
    public const NOTICE_MODES = ['off', 'notice', 'optout', 'optin'];
    public const OPTOUT_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '#'];
    public const CDR_ACTIONS = ['anonymize', 'delete'];

    /**
     * Global regulatory profile presets.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function profiles(): array
    {
        return [
            'off' => [
                'code'                => 'Off / Custom (All Rules Disabled)',
                'notice_mode'         => 'off',
                'optout_digit'        => '9',
                'optout_timeout'      => 3,
                'recording_beep_sec'  => 0,
                'pci_pause_enabled'   => false,
                'retention_enabled'   => false,
                'ret_recordings_days' => 0,
                'ret_voicemail_days'  => 0,
                'ret_fax_days'        => 0,
                'ret_chat_days'       => 0,
                'ret_cdr_days'        => 0,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => false,
            ],
            'kvkk' => [
                'code'                => 'KVKK (TR — 6698)',
                'notice_mode'         => 'optout',
                'optout_digit'        => '9',
                'optout_timeout'      => 3,
                'recording_beep_sec'  => 0,
                'pci_pause_enabled'   => true,
                'retention_enabled'   => true,
                'ret_recordings_days' => 180,
                'ret_voicemail_days'  => 90,
                'ret_fax_days'        => 180,
                'ret_chat_days'       => 365,
                'ret_cdr_days'        => 730,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => false,
            ],
            'gdpr' => [
                'code'                => 'EU / UK GDPR (2016/679)',
                'notice_mode'         => 'optin',
                'optout_digit'        => '1',
                'optout_timeout'      => 4,
                'recording_beep_sec'  => 0,
                'pci_pause_enabled'   => true,
                'retention_enabled'   => true,
                'ret_recordings_days' => 90,
                'ret_voicemail_days'  => 60,
                'ret_fax_days'        => 90,
                'ret_chat_days'       => 180,
                'ret_cdr_days'        => 365,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => false,
            ],
            'us_two_party' => [
                'code'                => 'US CCPA / Two-Party Consent & FCC',
                'notice_mode'         => 'optin',
                'optout_digit'        => '1',
                'optout_timeout'      => 4,
                'recording_beep_sec'  => 15,
                'pci_pause_enabled'   => true,
                'retention_enabled'   => true,
                'ret_recordings_days' => 180,
                'ret_voicemail_days'  => 90,
                'ret_fax_days'        => 180,
                'ret_chat_days'       => 365,
                'ret_cdr_days'        => 365,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => false,
            ],
            'hipaa' => [
                'code'                => 'HIPAA (Healthcare PHI)',
                'notice_mode'         => 'notice',
                'optout_digit'        => '9',
                'optout_timeout'      => 3,
                'recording_beep_sec'  => 15,
                'pci_pause_enabled'   => true,
                'retention_enabled'   => true,
                'ret_recordings_days' => 90,
                'ret_voicemail_days'  => 30,
                'ret_fax_days'        => 90,
                'ret_chat_days'       => 90,
                'ret_cdr_days'        => 365,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => true,
            ],
            'pci_dss' => [
                'code'                => 'PCI-DSS v4.0 (Call Center)',
                'notice_mode'         => 'optout',
                'optout_digit'        => '9',
                'optout_timeout'      => 3,
                'recording_beep_sec'  => 0,
                'pci_pause_enabled'   => true,
                'retention_enabled'   => true,
                'ret_recordings_days' => 90,
                'ret_voicemail_days'  => 30,
                'ret_fax_days'        => 90,
                'ret_chat_days'       => 180,
                'ret_cdr_days'        => 365,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => true,
            ],
            'global_lgpd_pipeda' => [
                'code'                => 'LGPD (BR) / PIPEDA (CA) / POPIA / PDPA',
                'notice_mode'         => 'optin',
                'optout_digit'        => '1',
                'optout_timeout'      => 4,
                'recording_beep_sec'  => 15,
                'pci_pause_enabled'   => true,
                'retention_enabled'   => true,
                'ret_recordings_days' => 180,
                'ret_voicemail_days'  => 90,
                'ret_fax_days'        => 180,
                'ret_chat_days'       => 365,
                'ret_cdr_days'        => 365,
                'ret_cdr_action'      => 'anonymize',
                'block_cloud_ai'      => false,
            ],
        ];
    }

    /**
     * Current privacy settings with safe opt-in defaults (all disabled until explicitly enabled).
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $profile = (string)getSystemSetting('privacy_jurisdiction_profile', 'off');
        if (!isset(self::profiles()[$profile])) {
            $profile = 'off';
        }

        return [
            'jurisdiction_profile'  => $profile,
            'notice_mode'           => self::validNoticeMode((string)getSystemSetting('privacy_notice_mode', 'off')),
            'notice_sound_id'       => max(0, (int)getSystemSetting('privacy_notice_sound_id', '0')),
            'optout_digit'          => self::validOptoutDigit((string)getSystemSetting('privacy_optout_digit', '9')),
            'optout_timeout'        => max(1, min(10, (int)getSystemSetting('privacy_optout_timeout', '3'))),
            'recording_beep_sec'    => self::validBeepSec((int)getSystemSetting('privacy_recording_beep_sec', '0')),
            'pci_pause_enabled'     => getSystemSetting('privacy_pci_pause_enabled', '0') === '1',
            'retention_enabled'     => getSystemSetting('privacy_retention_enabled', '0') === '1',
            'ret_recordings_days'   => max(0, min(3650, (int)getSystemSetting('privacy_ret_recordings_days', '180'))),
            'ret_voicemail_days'    => max(0, min(3650, (int)getSystemSetting('privacy_ret_voicemail_days', '90'))),
            'ret_fax_days'          => max(0, min(3650, (int)getSystemSetting('privacy_ret_fax_days', '180'))),
            'ret_chat_days'         => max(0, min(3650, (int)getSystemSetting('privacy_ret_chat_days', '365'))),
            'ret_cdr_days'          => max(0, min(3650, (int)getSystemSetting('privacy_ret_cdr_days', '730'))),
            'ret_cdr_action'        => in_array((string)getSystemSetting('privacy_ret_cdr_action', 'anonymize'), self::CDR_ACTIONS, true)
                ? (string)getSystemSetting('privacy_ret_cdr_action', 'anonymize')
                : 'anonymize',
            'block_cloud_ai'        => getSystemSetting('privacy_block_cloud_ai', '0') === '1',
            'last_purge_at'         => (string)getSystemSetting('privacy_last_purge_at', ''),
            'last_purge_summary'    => (string)getSystemSetting('privacy_last_purge_summary', ''),
        ];
    }

    /**
     * Apply a global regulatory jurisdiction preset in one click.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function applyProfile(array $post): array
    {
        $key = (string)($post['jurisdiction_profile'] ?? '');
        $profiles = self::profiles();
        if (!isset($profiles[$key])) {
            return ['error' => t('privacy.err_invalid_profile')];
        }
        $p = $profiles[$key];

        // Keep notice_mode 'off' if no announcement sound is selected yet, so dialplan stays valid
        $soundId = max(0, (int)getSystemSetting('privacy_notice_sound_id', '0'));
        $noticeMode = $soundId > 0 ? (string)$p['notice_mode'] : 'off';

        self::upsertSettings([
            'privacy_jurisdiction_profile' => $key,
            'privacy_notice_mode'          => $noticeMode,
            'privacy_optout_digit'         => (string)$p['optout_digit'],
            'privacy_optout_timeout'       => (string)$p['optout_timeout'],
            'privacy_recording_beep_sec'   => (string)$p['recording_beep_sec'],
            'privacy_pci_pause_enabled'    => $p['pci_pause_enabled'] ? '1' : '0',
            'privacy_retention_enabled'    => $p['retention_enabled'] ? '1' : '0',
            'privacy_ret_recordings_days'  => (string)$p['ret_recordings_days'],
            'privacy_ret_voicemail_days'   => (string)$p['ret_voicemail_days'],
            'privacy_ret_fax_days'         => (string)$p['ret_fax_days'],
            'privacy_ret_chat_days'        => (string)$p['ret_chat_days'],
            'privacy_ret_cdr_days'         => (string)$p['ret_cdr_days'],
            'privacy_ret_cdr_action'       => (string)$p['ret_cdr_action'],
            'privacy_block_cloud_ai'       => $p['block_cloud_ai'] ? '1' : '0',
        ]);

        if (function_exists('markSyncPending')) {
            markSyncPending('inbound_dialplan');
            markSyncPending('queues');
            markSyncPending('ring_groups');
        }
        if (function_exists('logAudit')) {
            logAudit('PRIVACY_PROFILE_APPLIED', 'sys_settings', null, "Applied jurisdiction preset: {$p['code']}");
        }

        return ['message' => sprintf(t('privacy.msg_profile_applied'), $p['code'])];
    }

    /**
     * Save Pre-Call Notice, Opt-In/Opt-Out, Periodic Beep & PCI-DSS settings and queue dialplan sync.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function saveNotice(array $post): array
    {
        $mode = self::validNoticeMode((string)($post['notice_mode'] ?? 'off'));
        $soundId = max(0, (int)($post['notice_sound_id'] ?? 0));
        $digit = self::validOptoutDigit((string)($post['optout_digit'] ?? '9'));
        $timeout = max(1, min(10, (int)($post['optout_timeout'] ?? 3)));
        $beepSec = self::validBeepSec((int)($post['recording_beep_sec'] ?? 0));
        $pciPause = !empty($post['pci_pause_enabled']) ? '1' : '0';

        if ($mode !== 'off' && $soundId <= 0) {
            return ['error' => t('privacy.err_sound_required')];
        }

        self::upsertSettings([
            'privacy_notice_mode'        => $mode,
            'privacy_notice_sound_id'    => (string)$soundId,
            'privacy_optout_digit'       => $digit,
            'privacy_optout_timeout'     => (string)$timeout,
            'privacy_recording_beep_sec' => (string)$beepSec,
            'privacy_pci_pause_enabled'  => $pciPause,
        ]);

        if (function_exists('markSyncPending')) {
            markSyncPending('inbound_dialplan');
            markSyncPending('queues');
            markSyncPending('ring_groups');
        }
        if (function_exists('logAudit')) {
            logAudit(
                'PRIVACY_NOTICE_UPDATED',
                'sys_settings',
                null,
                "mode={$mode}, sound_id={$soundId}, digit={$digit}, beep={$beepSec}s, pci_pause={$pciPause}"
            );
        }

        return ['message' => t('privacy.msg_notice_saved')];
    }

    /**
     * Save Data Retention & Auto-Purge policy settings.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function saveRetention(array $post): array
    {
        $enabled = !empty($post['retention_enabled']) ? '1' : '0';
        $recDays = max(0, min(3650, (int)($post['ret_recordings_days'] ?? 180)));
        $vmDays  = max(0, min(3650, (int)($post['ret_voicemail_days'] ?? 90)));
        $faxDays = max(0, min(3650, (int)($post['ret_fax_days'] ?? 180)));
        $chatDays = max(0, min(3650, (int)($post['ret_chat_days'] ?? 365)));
        $cdrDays = max(0, min(3650, (int)($post['ret_cdr_days'] ?? 730)));
        $cdrAction = in_array((string)($post['ret_cdr_action'] ?? 'anonymize'), self::CDR_ACTIONS, true)
            ? (string)$post['ret_cdr_action']
            : 'anonymize';

        self::upsertSettings([
            'privacy_retention_enabled'   => $enabled,
            'privacy_ret_recordings_days' => (string)$recDays,
            'privacy_ret_voicemail_days'  => (string)$vmDays,
            'privacy_ret_fax_days'        => (string)$faxDays,
            'privacy_ret_chat_days'       => (string)$chatDays,
            'privacy_ret_cdr_days'        => (string)$cdrDays,
            'privacy_ret_cdr_action'      => $cdrAction,
        ]);

        if (function_exists('logAudit')) {
            logAudit(
                'PRIVACY_RETENTION_UPDATED',
                'sys_settings',
                null,
                "enabled={$enabled}, rec={$recDays}d, vm={$vmDays}d, fax={$faxDays}d, chat={$chatDays}d, cdr={$cdrDays}d ({$cdrAction})"
            );
        }

        return ['message' => t('privacy.msg_retention_saved')];
    }

    /**
     * Save Cross-Border Cloud AI Data Transfer Lock setting.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function saveCompliance(array $post): array
    {
        $blockCloudAi = !empty($post['block_cloud_ai']) ? '1' : '0';
        self::upsertSettings([
            'privacy_block_cloud_ai' => $blockCloudAi,
        ]);

        if (function_exists('logAudit')) {
            logAudit('PRIVACY_COMPLIANCE_UPDATED', 'sys_settings', null, "block_cloud_ai={$blockCloudAi}");
        }

        return ['message' => t('privacy.msg_compliance_saved')];
    }

    /**
     * Execute retention purge now (either from portal button or nightly cron).
     *
     * @return array{counts: array<string, int>, summary: string}
     */
    public static function runRetentionPurge(): array
    {
        $db = getDB();
        $cfg = self::settings();
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
        }

        // 2. Voicemail archive & AI transcripts older than ret_voicemail_days
        $vmDays = (int)$cfg['ret_voicemail_days'];
        if ($vmDays > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM pbx_voicemail_archive WHERE origtime < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))");
                $stmt->execute([$vmDays]);
                $counts['voicemail'] += $stmt->rowCount();
            } catch (\Throwable $e) {
            }
            try {
                $stmt = $db->prepare("DELETE FROM pbx_voicemail_ai WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$vmDays]);
            } catch (\Throwable $e) {
            }
        }

        // 3. Fax logs older than ret_fax_days
        $faxDays = (int)$cfg['ret_fax_days'];
        if ($faxDays > 0) {
            try {
                $stmt = $db->prepare("SELECT id, file_path, pdf_path FROM pbx_fax_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$faxDays]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    foreach (['file_path', 'pdf_path'] as $col) {
                        $p = (string)($row[$col] ?? '');
                        if ($p !== '' && str_starts_with($p, '/var/spool/asterisk/fax/') && is_file($p)) {
                            @unlink($p);
                        }
                    }
                }
                $del = $db->prepare("DELETE FROM pbx_fax_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $del->execute([$faxDays]);
                $counts['fax'] += $del->rowCount();
            } catch (\Throwable $e) {
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

        // 5. CDR records older than ret_cdr_days (anonymize PII or delete)
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
        self::upsertSettings([
            'privacy_last_purge_at'      => $now,
            'privacy_last_purge_summary' => $summary,
        ]);

        if (function_exists('logAudit')) {
            logAudit('PRIVACY_PURGE', 'sys_settings', null, "Retention purge completed: {$summary}");
        }

        return ['counts' => $counts, 'summary' => $summary];
    }

    /**
     * Subject Access Request (SAR) lookup by phone number / extension.
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
                "SELECT id, mailboxuser, callerid, origtime, duration, transcript
                 FROM pbx_voicemail_archive
                 WHERE mailboxuser = ? OR callerid LIKE ?
                 ORDER BY origtime DESC LIMIT 100"
            );
            $stmt->execute([$digitsOnly, $like]);
            $voicemails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }

        $faxes = [];
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
     * and anonymizes CDR rows for the given phone number.
     *
     * @return array<string, string>
     */
    public static function eraseSubject(string $rawNumber): array
    {
        $info = self::lookupSubject($rawNumber);
        if ($info === null) {
            return ['error' => t('privacy.err_subject_short')];
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
            $anonCdrs = $stmt->rowCount();
        } catch (\Throwable $e) {
        }

        $delVm = 0;
        try {
            $stmt = $db->prepare("DELETE FROM pbx_voicemail_archive WHERE mailboxuser = ? OR callerid LIKE ?");
            $stmt->execute([$digits, $like]);
            $delVm = $stmt->rowCount();
        } catch (\Throwable $e) {
        }

        $delFax = 0;
        try {
            foreach ($info['faxes'] as $fax) {
                foreach (['file_path', 'pdf_path'] as $col) {
                    $p = (string)($fax[$col] ?? '');
                    if ($p !== '' && str_starts_with($p, '/var/spool/asterisk/fax/') && is_file($p)) {
                        @unlink($p);
                    }
                }
            }
            $stmt = $db->prepare("DELETE FROM pbx_fax_log WHERE caller_id LIKE ? OR destination LIKE ?");
            $stmt->execute([$like, $like]);
            $delFax = $stmt->rowCount();
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
        if (function_exists('logAudit')) {
            logAudit(
                'PRIVACY_ERASURE',
                'privacy',
                null,
                "Erased subject {$maskedTarget}: cdr_anonymized={$anonCdrs}, recordings_deleted={$deletedRecs}, voicemail_deleted={$delVm}, fax_deleted={$delFax}, signaling_deleted={$delSig}"
            );
        }

        return [
            'message' => sprintf(
                t('privacy.msg_erased'),
                $anonCdrs,
                $deletedRecs,
                $delVm,
                $delFax
            ),
        ];
    }

    /**
     * Compliance overview for Tab 4 (technical & organizational safeguards).
     *
     * @return array<string, mixed>
     */
    public static function complianceOverview(): array
    {
        $db = getDB();
        $recordingListeners = [];
        $admin2faCount = 0;
        $adminTotalCount = 0;
        $recentAudits = [];

        try {
            $recordingListeners = $db->query(
                "SELECT username, full_name, role, extension
                 FROM sys_users
                 WHERE is_active = 1 AND (role = 'admin' OR can_listen_recordings = 1)
                 ORDER BY role ASC, username ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }

        try {
            $rows = $db->query("SELECT totp_enabled FROM sys_users WHERE is_active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
            $adminTotalCount = count($rows);
            foreach ($rows as $r) {
                if (!empty($r['totp_enabled'])) {
                    $admin2faCount++;
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $recentAudits = $db->query(
                "SELECT action, username, ip_address, details, created_at
                 FROM sys_audit_log
                 WHERE action LIKE 'PRIVACY_%' OR action = 'LICENSE_ACCEPTED'
                 ORDER BY id DESC LIMIT 15"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }

        $sounds = [];
        try {
            $sounds = $db->query("SELECT id, title, file_path, language FROM pbx_sounds ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }

        return [
            'recording_listeners' => $recordingListeners,
            'admin_2fa_count'     => $admin2faCount,
            'admin_total_count'   => $adminTotalCount,
            'recent_audits'       => $recentAudits,
            'sounds'              => $sounds,
        ];
    }

    /**
     * Resolve the Asterisk Playback/Read sound path for `[sub-privacy-consent]`
     * when `privacy_notice_mode` is enabled, or null if disabled/missing.
     *
     * @return array{mode: string, sound_path: string, optout_digit: string, optout_timeout: int}|null
     */
    public static function dialplanNoticeConfig(): ?array
    {
        $mode = self::validNoticeMode((string)getSystemSetting('privacy_notice_mode', 'off'));
        if ($mode === 'off') {
            return null;
        }
        $soundId = (int)getSystemSetting('privacy_notice_sound_id', '0');
        if ($soundId <= 0) {
            return null;
        }
        try {
            $stmt = getDB()->prepare("SELECT file_path FROM pbx_sounds WHERE id = ?");
            $stmt->execute([$soundId]);
            $filePath = (string)($stmt->fetchColumn() ?: '');
            if ($filePath === '') {
                return null;
            }
            $pbPath = function_exists('asteriskSoundPlaybackPath')
                ? asteriskSoundPlaybackPath($filePath)
                : preg_replace('/\.[a-zA-Z0-9]+$/', '', $filePath);
            if (empty($pbPath)) {
                return null;
            }
            return [
                'mode'           => $mode,
                'sound_path'     => (string)$pbPath,
                'optout_digit'   => self::validOptoutDigit((string)getSystemSetting('privacy_optout_digit', '9')),
                'optout_timeout' => max(1, min(10, (int)getSystemSetting('privacy_optout_timeout', '3'))),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns Asterisk `MixMonitor()` second-argument option string (e.g. `,B(15)`)
     * when FCC / Two-Party Consent periodic recording beep is enabled, or empty string.
     */
    public static function mixMonitorOptionSuffix(): string
    {
        $beepSec = self::validBeepSec((int)getSystemSetting('privacy_recording_beep_sec', '0'));
        return $beepSec > 0 ? ",B({$beepSec})" : '';
    }

    private static function validNoticeMode(string $mode): string
    {
        return in_array($mode, self::NOTICE_MODES, true) ? $mode : 'off';
    }

    private static function validOptoutDigit(string $digit): string
    {
        return in_array($digit, self::OPTOUT_DIGITS, true) ? $digit : '9';
    }

    private static function validBeepSec(int $sec): int
    {
        if ($sec <= 0) {
            return 0;
        }
        return max(5, min(60, $sec));
    }

    /**
     * @param array<string, string> $pairs
     */
    private static function upsertSettings(array $pairs): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }
}
