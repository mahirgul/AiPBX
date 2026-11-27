<?php
/**
 * Master Asterisk Sync Orchestrator (/etc/asterisk/pbx/)
 * Facade linking domain-driven sync modules in src/sync/
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/file_helper.php';
require_once __DIR__ . '/asterisk_helper.php';
require_once __DIR__ . '/priv_helper.php';
require_once __DIR__ . '/sync/ConfWriter.php';     // writePBXConf(), writeConfWithRollback(), withSyncLock(): below the modules

/**
 * Deferred reload system: domain -> real syncXxx() function name, in the order Apply runs them.
 * One list answers both "which domains exist" and "which comes first".
 */
const PENDING_SYNC_DOMAIN_MAP = [
    // The ORDER of this list is the order "Apply" runs the domains in (applyPendingSync()), the same order
    // syncEverything() uses: a domain comes after the ones whose files it includes or checks.
    'readdress'         => 'syncReaddress',          // first: it regenerates the extensions itself
    'transports'        => 'syncTransports',
    'permissions'       => 'syncPermissions',
    'extensions'        => 'syncAllExtensions',
    'trunks'            => 'syncAllTrunks',
    'advanced_pjsip'    => 'syncAdvancedPjsip',      // checks the options against the PJSIP objects written above
    'queues'            => 'syncAllQueues',
    'ivrs'              => 'syncAllIVRs',
    'time_conditions'   => 'syncAllTimeConditions',
    'ring_groups'       => 'syncRingGroups',
    'conferences'       => 'syncConferences',
    'voicemail'         => 'syncVoicemail',
    'inbound_dialplan'  => 'syncInboundDialplan',
    'outbound_dialplan' => 'syncOutboundDialplan',
    'widgets'           => 'syncWidgets',            // after the outbound routes: it jumps into them
    'ai_apps'           => 'syncAiApps',
    'featurecodes'      => 'syncFeatureCodes',
    'internal_numbers'  => 'syncInternalNumbers',    // internal_numbers and custom_dialplan before general_dialplan,
    'custom_dialplan'   => 'syncCustomDialplan',     // which includes their contexts
    'general_dialplan'  => 'syncGeneralDialplan',    // the enabled plugins' dialplans follow it (applyPendingSync())
    'moh'               => 'syncAsteriskMOH',
    'rtp'               => 'syncRtpSettings',
    'udptl'             => 'syncUdptlSettings',
    'parking'           => 'syncParking',
];

/**
 * Marks that a record must be PUSHED to Asterisk — it does NOT run the real
 * regen+reload right away; it waits until the admin presses "Apply" on the
 * /pending-sync page (2026-08-24, user request: "the admin may want to make
 * several changes and apply them all at once").
 * $domain must be in PENDING_SYNC_DOMAIN_MAP. If the same (domain,
 * entity_type, entity_id) is edited again before Apply, the row is UPDATED
 * (deduplicated) — the list does not grow for the same record, it shows the
 * latest state.
 *
 * OUT of scope (on purpose): phone feature codes (feature_code_action.php
 * KEEPS its own syncEverything() call — dialing *78 must take effect at
 * once, without waiting for admin approval) and agent queue login/pause
 * (live AMI commands with no config file at all, outside this system).
 */
function markPendingSync($domain, $entity_type, $entity_id, $entity_label, $action = 'update', $user_id = null) {
    if (!array_key_exists($domain, PENDING_SYNC_DOMAIN_MAP)) {
        throw new \InvalidArgumentException("Unknown pending-sync domain: {$domain}");
    }
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO sys_pending_sync (domain, entity_type, entity_id, entity_label, action, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE entity_label = VALUES(entity_label), action = VALUES(action), changed_by = VALUES(changed_by), changed_at = NOW(), revision = revision + 1"
    );
    $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id]);
    // "Apply changes at once": the next page this user opens applies them (pendingSyncAutoApplyNow()).
    if ($domain !== 'readdress' && isset($_SESSION)) {
        $_SESSION['pending_sync_marked'] = 1;
    }

    // sys_pending_sync ROWS are DELETED after Apply — a permanent "who did
    // what and when" record goes to the separate, INSERT-only sys_audit_log.
    writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id);

    if (is_file(__DIR__ . '/services/WebhookService.php')) {
        require_once __DIR__ . '/services/WebhookService.php';
        WebhookService::emit('config.pending_created', [
            'domain'       => $domain,
            'entity_type'  => $entity_type,
            'entity_id'    => (string)$entity_id,
            'entity_label' => $entity_label,
            'action'       => $action,
        ]);
    }
}

// writeAuditLog() lives in src/audit_log.php (loaded by config.php for every
// request: API endpoints and controllers that never load this file used to
// fail with "undefined function" after their change was already made).

/**
 * A lightweight counter for the sidebar badge.
 */
function getPendingSyncCount() {
    try {
        return (int) getDB()->query("SELECT COUNT(*) FROM sys_pending_sync")->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * For the /pending-sync page: all pending rows, grouped by domain.
 */
function getPendingSyncList() {
    $db = getDB();
    $rows = $db->query(
        "SELECT ps.*, u.full_name AS changed_by_name
         FROM sys_pending_sync ps
         LEFT JOIN sys_users u ON u.id = ps.changed_by
         ORDER BY ps.domain ASC, ps.changed_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['domain']][] = $r;
    }
    return $grouped;
}

/** The signed-in user's extension as it is now (the web phone follows a change of it). */
function currentUserExtension(): string
{
    $st = getDB()->prepare('SELECT extension FROM sys_users WHERE id = ?');
    $st->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    return (string) $st->fetchColumn();
}

/**
 * True once after this user saved a change, when "apply changes at once" is on and
 * they may apply. A pending server address change is never applied this way.
 */
function pendingSyncAutoApplyNow(): bool
{
    if (empty($_SESSION['pending_sync_marked'])) {
        return false;
    }
    unset($_SESSION['pending_sync_marked']);
    return getSystemSetting('pending_sync_auto_apply', '0') === '1'
        && hasModulePermission('pending_sync', 'edit')
        && !getDB()->query("SELECT 1 FROM sys_pending_sync WHERE domain = 'readdress' LIMIT 1")->fetchColumn()
        && getPendingSyncCount() > 0;
}

/**
 * Runs when "Apply" is pressed: calls the real syncXxx() function once for
 * EVERY pending domain (ONE regen per domain however many records changed
 * in it — syncAllQueues() already generates all queues in one go, and the
 * withSyncLock() lock applies here too). A successful domain's rows are
 * deleted (only those read before its sync, at the revision read); if a domain fails its rows STAY (retried on the next Apply) and
 * the other domains are NOT AFFECTED.
 * Returns: ['domain' => ['success' => bool, 'error' => string|null]].
 */
function applyPendingSync($user_id = null) {
    $db = getDB();
    $domains = $db->query("SELECT DISTINCT domain FROM sys_pending_sync")->fetchAll(PDO::FETCH_COLUMN);
    // In the order of PENDING_SYNC_DOMAIN_MAP (not alphabetical): a domain that is not in the list comes last and fails there.
    $position = array_flip(array_keys(PENDING_SYNC_DOMAIN_MAP));
    usort($domains, static fn ($a, $b) => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX) ?: strcmp((string) $a, (string) $b));

    if (is_file(__DIR__ . '/services/WebhookService.php')) {
        require_once __DIR__ . '/services/WebhookService.php';
    }
    if (is_file(__DIR__ . '/core/PluginManager.php')) {
        require_once __DIR__ . '/core/PluginManager.php';
    }

    $results = [];
    foreach ($domains as $domain) {
        $fn = PENDING_SYNC_DOMAIN_MAP[$domain] ?? null;
        if (!$fn || !function_exists($fn)) {
            $results[$domain] = ['success' => false, 'error' => "Unknown domain: {$domain}"];
            continue;
        }
        // What this run applies: a row saved (or saved again) while the sync runs has
        // another id or revision and stays pending for the next Apply.
        $snap = $db->prepare('SELECT id, revision FROM sys_pending_sync WHERE domain = ?');
        $snap->execute([$domain]);
        $applied = $snap->fetchAll(PDO::FETCH_KEY_PAIR);
        try {
            $fn();
            if ($domain === 'general_dialplan' && class_exists('PluginManager')) {
                PluginManager::syncAllEnabledPlugins();
            }
            $del = $db->prepare('DELETE FROM sys_pending_sync WHERE id = ? AND revision = ?');
            foreach ($applied as $rowId => $rev) {
                $del->execute([$rowId, $rev]);
            }
            $results[$domain] = ['success' => true, 'error' => null];
            writeAuditLog($domain, 'sync_apply', $domain, $domain, 'apply', $user_id);
            if (class_exists('WebhookService')) {
                WebhookService::emit('config.sync_applied', ['domain' => $domain]);
            }
        } catch (\Throwable $e) {
            $results[$domain] = ['success' => false, 'error' => $e->getMessage()];
            // 2026-08-25: only the domain name ("queues") used to be written
            // here — the REAL text of the rollback/reload error (including
            // writeConfWithRollback()'s "AUTOMATICALLY ROLLED BACK"/"MANUAL
            // INTERVENTION NEEDED" messages) only appeared in that HTTP
            // response and was lost from the permanent record.
            // entity_label is varchar(255) — raw Asterisk output can exceed
            // it, so it is trimmed safely.
            $label = "{$domain}: " . mb_substr($e->getMessage(), 0, 230);
            writeAuditLog($domain, 'sync_apply', $domain, $label, 'apply_failed', $user_id);
            if (class_exists('WebhookService')) {
                WebhookService::emit('config.sync_failed', ['domain' => $domain, 'error' => $e->getMessage()]);
            }
        }
    }
    return $results;
}

// Require Domain Sync Modules
require_once __DIR__ . '/sync/SyncTransports.php';
// SyncDialplan.php was split into four areas on 2026-08-31 (it was 516 lines in one file).
// DialplanBuilders must come FIRST — the others use the line functions it produces.
require_once __DIR__ . '/sync/DialplanBuilders.php';
require_once __DIR__ . '/sync/SyncInboundDialplan.php';
require_once __DIR__ . '/sync/SyncOutboundDialplan.php';
require_once __DIR__ . '/sync/SyncGeneralDialplan.php';
require_once __DIR__ . '/sync/SyncMOH.php';
require_once __DIR__ . '/sync/SyncExtensions.php';
require_once __DIR__ . '/sync/SyncTrunks.php';
require_once __DIR__ . '/sync/SyncQueues.php';
require_once __DIR__ . '/sync/SyncIVRs.php';
require_once __DIR__ . '/sync/SyncIvrDataSteps.php';
require_once __DIR__ . '/sync/SyncTimeConditions.php';
require_once __DIR__ . '/sync/SyncFeatureCodes.php';
require_once __DIR__ . '/sync/SyncInternalNumbers.php';
require_once __DIR__ . '/sync/SyncRtpSettings.php';
require_once __DIR__ . '/sync/SyncUdptlSettings.php';
require_once __DIR__ . '/sync/SyncSignaling.php';
require_once __DIR__ . '/sync/SyncRingGroups.php';
require_once __DIR__ . '/sync/SyncConferences.php';
require_once __DIR__ . '/sync/SyncVoicemail.php';
require_once __DIR__ . '/sync/SyncPermissions.php';
require_once __DIR__ . '/sync/SyncWidgets.php';
require_once __DIR__ . '/sync/SyncAiApps.php';
require_once __DIR__ . '/sync/SyncCustomDialplan.php';
require_once __DIR__ . '/sync/SyncAdvancedPjsip.php';
require_once __DIR__ . '/sync/SyncReaddress.php';
require_once __DIR__ . '/sync/SyncParking.php';

/**
 * Updates the system default language (/etc/asterisk/asterisk.conf [options]
 * defaultlanguage=). CAUTION: unlike every other sync* function this does NOT
 * take effect with "core reload", only with a FULL Asterisk restart
 * (asterisk.conf [options] is read only at startup). So it returns true only
 * when the value REALLY changed, false otherwise — the caller
 * (asterisk_settings.php) must trigger a restart only on true, not on every
 * save.
 */
function syncDefaultLanguage($lang) {
    $lang = preg_replace('/[^a-zA-Z_]/', '', $lang) ?: 'en';
    $conf_path = ASTERISK_CONF_DIR . '/asterisk.conf';
    $content = @file_get_contents($conf_path);
    if ($content === false) return false;
    if (preg_match('/^defaultlanguage\s*=\s*(.*)$/m', $content, $m) && trim($m[1]) === $lang) {
        return false; // same value already, nothing changed
    }
    if (ASTERISK_CONF_DIR !== '/etc/asterisk') {
        // Another config directory (tests): written directly.
        $content = preg_match('/^defaultlanguage\s*=.*$/m', $content)
            ? preg_replace('/^defaultlanguage\s*=.*$/m', "defaultlanguage = {$lang}", $content, 1)
            : preg_replace('/(\[options\][^\[]*)/', "$1defaultlanguage = {$lang}\n", $content, 1);
        return file_put_contents($conf_path, $content) !== false;
    }
    // /etc/asterisk/asterisk.conf is asterisk:asterisk 0640 — the portal cannot
    // write it, so the old file_put_contents() here failed silently and the
    // language never changed although the page asked for a restart. aipbx-priv
    // (root, the path is fixed there) writes it now.
    $res = PrivHelper::run(['asterisk-lang', $lang]);
    if (!$res['success']) {
        throw new RuntimeException(sprintf(t('srv_asterisk.err_lang'), $res['output']));
    }
    return true;
}

/**
 * Full Initial / Bulk System Sync Trigger
 */
function syncEverything() {
    // Every syncXxx() function already calls ITS OWN reload (dialplan/pjsip/
    // queue/moh) — triggering the same reloads again here was redundant (one
    // syncEverything() call reloaded the dialplan 5 times, pjsip 2 times and
    // moh 2 times; found in the 2026-08-21 audit).
    syncTransports();
    syncPermissions();
    syncAllExtensions();
    syncAllTrunks();
    syncAllQueues();
    syncAllIVRs();
    syncAllTimeConditions();
    syncRingGroups();
    syncConferences();
    syncVoicemail();
    syncInboundDialplan();
    syncOutboundDialplan();
    // After the outbound routes: a widget's external destination and its
    // call-back jump into [outbound-route-<id>].
    syncWidgets();
    syncAiApps();
    syncFeatureCodes();
    // The number and custom contexts must be generated BEFORE the general
    // dialplan that includes them; otherwise on a fresh install Asterisk includes
    // a context that does not exist.
    syncInternalNumbers();
    syncCustomDialplan();
    syncGeneralDialplan();
    if (is_file(__DIR__ . '/core/PluginManager.php')) {
        require_once __DIR__ . '/core/PluginManager.php';
        PluginManager::syncAllEnabledPlugins();
    }
    syncAsteriskMOH();
    syncRtpSettings();
    syncUdptlSettings();
    syncSignalingCapture();
    syncParking();
    if (is_file(__DIR__ . '/services/CustomConfigService.php')) {
        require_once __DIR__ . '/services/CustomConfigService.php';
        CustomConfigService::ensureFilesExist();
    }

    return true;
}

if (php_sapi_name() === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    syncEverything();
    // Install / update: send every BLF lamp once (Asterisk's own store may be
    // new or older than pbx_lamp_states).
    require_once __DIR__ . '/services/LampService.php';
    LampService::refresh(true);
}

