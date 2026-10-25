<?php
declare(strict_types=1);

require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/privacy/PrivacyDataSubjectHelper.php';

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
     * @return array<string, mixed>
     */
    public static function applyProfile(array $post): array
    {
        if (array_key_exists('csrf_token', $post) && !verifyCSRFToken((string)$post['csrf_token'])) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $key = (string)($post['jurisdiction_profile'] ?? '');
        $profiles = self::profiles();
        if (!isset($profiles[$key])) {
            return ['success' => false, 'error' => t('privacy.err_invalid_profile')];
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

        $uid = $_SESSION['user_id'] ?? null;
        if (function_exists('markPendingSync')) {
            $lbl = "Privacy preset: {$p['code']}";
            markPendingSync('general_dialplan', 'privacy', 'profile', $lbl, 'update', $uid);
            markPendingSync('inbound_dialplan', 'privacy', 'profile', $lbl, 'update', $uid);
            markPendingSync('queues', 'privacy', 'profile', $lbl, 'update', $uid);
            markPendingSync('ring_groups', 'privacy', 'profile', $lbl, 'update', $uid);
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog('privacy', 'profile', $key, "Applied jurisdiction preset: {$p['code']}", 'update', $uid);
        }

        return ['success' => true, 'message' => sprintf(t('privacy.msg_profile_applied'), $p['code'])];
    }

    /**
     * Save Pre-Call Notice, Opt-In/Opt-Out, Periodic Beep & PCI-DSS settings and queue dialplan sync.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function saveNotice(array $post): array
    {
        if (array_key_exists('csrf_token', $post) && !verifyCSRFToken((string)$post['csrf_token'])) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $mode = self::validNoticeMode((string)($post['notice_mode'] ?? 'off'));
        $soundId = max(0, (int)($post['notice_sound_id'] ?? 0));
        $digit = self::validOptoutDigit((string)($post['optout_digit'] ?? '9'));
        $timeout = max(1, min(10, (int)($post['optout_timeout'] ?? 3)));
        $beepSec = self::validBeepSec((int)($post['recording_beep_sec'] ?? 0));
        $pciPause = !empty($post['pci_pause_enabled']) ? '1' : '0';

        if ($mode !== 'off' && $soundId <= 0) {
            return ['success' => false, 'error' => t('privacy.err_sound_required')];
        }

        self::upsertSettings([
            'privacy_notice_mode'        => $mode,
            'privacy_notice_sound_id'    => (string)$soundId,
            'privacy_optout_digit'       => $digit,
            'privacy_optout_timeout'     => (string)$timeout,
            'privacy_recording_beep_sec' => (string)$beepSec,
            'privacy_pci_pause_enabled'  => $pciPause,
        ]);

        $uid = $_SESSION['user_id'] ?? null;
        if (function_exists('markPendingSync')) {
            $lbl = "Privacy Pre-Call Notice ({$mode})";
            markPendingSync('general_dialplan', 'privacy', 'notice', $lbl, 'update', $uid);
            markPendingSync('inbound_dialplan', 'privacy', 'notice', $lbl, 'update', $uid);
            markPendingSync('queues', 'privacy', 'notice', $lbl, 'update', $uid);
            markPendingSync('ring_groups', 'privacy', 'notice', $lbl, 'update', $uid);
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog(
                'privacy',
                'notice',
                'settings',
                "Pre-call notice: mode={$mode}, sound_id={$soundId}, digit={$digit}, beep={$beepSec}s, pci_pause={$pciPause}",
                'update',
                $uid
            );
        }

        return ['success' => true, 'message' => t('privacy.msg_notice_saved')];
    }

    /**
     * Save Data Retention & Auto-Purge policy settings.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function saveRetention(array $post): array
    {
        if (array_key_exists('csrf_token', $post) && !verifyCSRFToken((string)$post['csrf_token'])) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
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

        if (function_exists('writeAuditLog')) {
            writeAuditLog(
                'privacy',
                'retention',
                'settings',
                "Retention policy: enabled={$enabled}, rec={$recDays}d, vm={$vmDays}d, fax={$faxDays}d, chat={$chatDays}d, cdr={$cdrDays}d ({$cdrAction})",
                'update',
                $_SESSION['user_id'] ?? null
            );
        }

        return ['success' => true, 'message' => t('privacy.msg_retention_saved')];
    }

    /**
     * Save Cross-Border Cloud AI Data Transfer Lock setting.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function saveCompliance(array $post): array
    {
        if (array_key_exists('csrf_token', $post) && !verifyCSRFToken((string)$post['csrf_token'])) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $blockCloudAi = !empty($post['block_cloud_ai']) ? '1' : '0';
        self::upsertSettings([
            'privacy_block_cloud_ai' => $blockCloudAi,
        ]);

        if (function_exists('writeAuditLog')) {
            writeAuditLog('privacy', 'compliance', 'block_cloud_ai', "Cross-border Cloud AI lock: block_cloud_ai={$blockCloudAi}", 'update', $_SESSION['user_id'] ?? null);
        }

        return ['success' => true, 'message' => t('privacy.msg_compliance_saved')];
    }

    /**
     * Execute retention purge now (either from portal button or nightly cron).
     *
     * @return array{counts: array<string, int>, summary: string}
     */
    public static function runRetentionPurge(): array
    {
        return PrivacyDataSubjectHelper::runRetentionPurge(
            self::settings(),
            static function (array $pairs): void {
                self::upsertSettings($pairs);
            }
        );
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
        return PrivacyDataSubjectHelper::lookupSubject($rawNumber);
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
        return PrivacyDataSubjectHelper::eraseSubject($rawNumber);
    }

    /**
     * Redact sensitive PII / PCI-DSS patterns (credit card PANs, IBANs, SSN/TCKN, emails) from text.
     */
    public static function redactText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // 1. IBAN (e.g. TR33 0006 1005 1978 6457 8413 26 or DE89370400440532013000)
        $text = preg_replace('/\b[A-Z]{2}\d{2}(?:[\s-]?[A-Z0-9]{4}){3,7}(?:[\s-]?[A-Z0-9]{1,4})?\b/i', '[REDACTED-IBAN]', $text) ?? $text;
        // 2. Credit/Debit Card PANs (13 to 19 digits, optionally grouped by spaces or hyphens)
        $text = preg_replace_callback('/\b(?:\d[ -]*?){13,19}\b/', static function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';
            return (strlen($digits) >= 13 && strlen($digits) <= 19) ? '[REDACTED-CARD]' : $m[0];
        }, $text) ?? $text;
        // 3. US SSN (XXX-XX-XXXX) and 11-digit National ID / TCKN
        $text = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[REDACTED-ID]', $text) ?? $text;
        $text = preg_replace('/\b[1-9]\d{10}\b/', '[REDACTED-ID]', $text) ?? $text;
        // 4. Email addresses
        $text = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[REDACTED-EMAIL]', $text) ?? $text;

        return $text;
    }

    /**
     * Automatically redacts sensitive PII/PCI data in live AGI / AI / Voicemail transcripts
     * whenever PCI-DSS protection or an active privacy jurisdiction profile is enabled.
     * When all privacy rules are disabled ('off'), returns the transcript unchanged.
     */
    public static function redactTranscriptIfEnabled(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $cfg = self::settings();
        if (!empty($cfg['pci_pause_enabled']) || $cfg['jurisdiction_profile'] !== 'off' || !empty($cfg['block_cloud_ai'])) {
            return self::redactText($text);
        }
        return $text;
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
            $rows = $db->query("SELECT two_factor_enabled FROM sys_users WHERE is_active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
            $adminTotalCount = count($rows);
            foreach ($rows as $r) {
                if (!empty($r['two_factor_enabled'])) {
                    $admin2faCount++;
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $recentAudits = $db->query(
                "SELECT UPPER(CONCAT(domain, ':', entity_type)) AS action,
                        COALESCE(username, 'system') AS username,
                        ip_address,
                        entity_label AS details,
                        created_at
                 FROM sys_audit_log
                 WHERE domain = 'privacy' OR entity_type = 'license_acceptance'
                 ORDER BY id DESC LIMIT 15"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }

        $sounds = [];
        try {
            $sounds = $db->query("SELECT id, title, audio_file AS file_path FROM pbx_announcements ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
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
            $stmt = getDB()->prepare("SELECT audio_file FROM pbx_announcements WHERE id = ?");
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
