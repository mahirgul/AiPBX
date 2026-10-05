#!/usr/bin/env php
<?php
/**
 * Triggered by the dialplan after a star code (DND/call forwarding/queue
 * login-logout) (see src/sync/SyncFeatureCodes.php). DND/CF update sys_users
 * and, being embedded in the dialplan, are regenerated and reloaded with
 * syncEverything(); queue login/logout is ONLY live Asterisk state (queue
 * add/remove member), so it does not touch the dialplan and syncEverything()
 * is NOT NEEDED (avoids a needless full reload).
 *
 * Usage: feature_code_action.php <dnd_toggle|cf_set|cf_cancel|queue_login|queue_logout> <extension> [target|queue_id]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/asterisk_sync.php';
require_once dirname(__DIR__) . '/src/queue_helper.php';

$action = $argv[1] ?? '';
$ext = preg_replace('/[^0-9]/', '', $argv[2] ?? '');

if ($ext === '') {
    fwrite(STDERR, "Gecersiz dahili\n");
    exit(1);
}

$db = getDB();
$needs_dialplan_sync = true;

switch ($action) {
    case 'dnd_toggle':
        $stmt = $db->prepare("SELECT dnd_enabled FROM sys_users WHERE extension = ?");
        $stmt->execute([$ext]);
        $cur = (int)$stmt->fetchColumn();
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE sys_users SET dnd_enabled = ? WHERE extension = ?")->execute([$new, $ext]);
        break;

    case 'cf_set':
        $target = preg_replace('/[^0-9]/', '', $argv[3] ?? '');
        if ($target === '') {
            fwrite(STDERR, "Hedef numara bos\n");
            exit(1);
        }
        $db->prepare("UPDATE sys_users SET call_forward_number = ? WHERE extension = ?")->execute([$target, $ext]);
        break;

    case 'cf_cancel':
        $db->prepare("UPDATE sys_users SET call_forward_number = NULL WHERE extension = ?")->execute([$ext]);
        break;

    case 'queue_login':
    case 'queue_logout':
        $needs_dialplan_sync = false;
        $target = trim($argv[3] ?? '');
        $join = ($action === 'queue_login');

        if ($target === '' || $target === 'all' || $target === '0') {
            // No target given (*81 / *80): log in/out of ALL active queues the extension is assigned to
            $stmt = $db->query("SELECT queue_name, members_json FROM pbx_queues WHERE is_active = 1");
            $assigned_queues = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q_row) {
                $mems = json_decode($q_row['members_json'] ?? '[]', true) ?: [];
                if (in_array((string)$ext, array_map('strval', $mems))) {
                    $assigned_queues[] = $q_row['queue_name'];
                }
            }
            if (empty($assigned_queues)) {
                fwrite(STDERR, "Dahili {$ext} hicbir aktif kuyruga atanmamis\n");
                exit(1);
            }
            foreach ($assigned_queues as $q_name) {
                QueueHelper::setMembership($ext, $q_name, $join);
            }
        } else {
            // A specific queue ID, extension number or queue name was dialed (*81<no> / *80<no>)
            $target_clean = preg_replace('/[^0-9a-zA-Z_-]/', '', $target);
            $stmt = $db->prepare("SELECT queue_name, members_json FROM pbx_queues WHERE (id = ? OR internal_number = ? OR queue_name = ?) AND is_active = 1");
            $stmt->execute([$target_clean, $target_clean, $target_clean]);
            $q_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$q_row) {
                fwrite(STDERR, "Kuyruk bulunamadi: {$target}\n");
                exit(1);
            }
            $queue_name = $q_row['queue_name'];
            if ($join && !QueueHelper::isAssignedMember($ext, $queue_name)) {
                fwrite(STDERR, "Dahili {$ext} bu kuyruga ({$queue_name}) atanmamis\n");
                exit(1);
            }
            if (!$join && QueueHelper::isStaticMember($ext, $queue_name)) {
                fwrite(STDERR, "Dahili {$ext} bu kuyrukta ({$queue_name}) statik temsilci, cikis yapamaz\n");
                exit(1);
            }
            QueueHelper::setMembership($ext, $queue_name, $join);
        }

        // On queue login or logout, close the active pause record if there is one.
        // On logout the record stays open in static queues, since the agent is still a member there (maybe paused).
        if (!$join && !empty(QueueHelper::staticQueuesOf($ext))) break;
        $stmt_close_pause = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt_close_pause->execute([$ext]);
        break;

    case 'queue_pause':
        $needs_dialplan_sync = false;
        $reason_id = trim($argv[3] ?? '');

        // Get the pause reasons defined in the system
        $stmt_reasons = $db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'cc_break_reasons'");
        $reasons_raw = $stmt_reasons ? ($stmt_reasons->fetchColumn() ?: '') : '';
        $reasons_list = array_values(array_filter(array_map('trim', explode(',', $reasons_raw))));

        // Map the pause ID (1, 2, 3...) to its name
        $reason_name = 'Mola';
        if (is_numeric($reason_id) && intval($reason_id) >= 1 && intval($reason_id) <= count($reasons_list)) {
            $reason_name = $reasons_list[intval($reason_id) - 1];
        } elseif (!empty($reason_id) && !is_numeric($reason_id)) {
            $reason_name = $reason_id;
        } elseif (!empty($reasons_list)) {
            $reason_name = $reasons_list[0];
        }

        QueueHelper::pauseMember($ext, $reason_name);
        break;

    case 'queue_unpause':
        $needs_dialplan_sync = false;
        QueueHelper::unpauseMember($ext);
        break;

    default:
        fwrite(STDERR, "Bilinmeyen aksiyon: {$action}\n");
        exit(1);
}

if ($needs_dialplan_sync) {
    try {
        syncEverything();
    } catch (\Throwable $e) {
        fwrite(STDERR, "syncEverything() hatası: " . $e->getMessage() . "\n");
    }
}
exit(0);
