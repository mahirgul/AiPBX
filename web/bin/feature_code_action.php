#!/usr/bin/env php
<?php
/**
 * Star code (DND/Çağrı Yönlendirme/Kuyruk Giriş-Çıkış) sonrası dialplan tarafından
 * tetiklenir (bkz. src/sync/SyncFeatureCodes.php). DND/CF sys_users'ı günceller ve
 * dialplan'a gömülü olduğu için syncEverything() ile yeniden üretilip reload edilir;
 * kuyruk giriş/çıkış ise SADECE canlı Asterisk durumu (queue add/remove member) olduğu
 * için dialplan'a dokunmaz, syncEverything() GEREKMEZ (gereksiz tam reload'dan kaçınılır).
 *
 * Kullanım: feature_code_action.php <dnd_toggle|cf_set|cf_cancel|queue_login|queue_logout> <dahili> [hedef|kuyruk_id]
 */
require_once '/var/www/html/config.php';
require_once '/var/www/html/src/asterisk_sync.php';
require_once '/var/www/html/src/queue_helper.php';

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
            // Hedef belirtilmediğinde (*81 / *80): Dahilinin atanmış olduğu TÜM aktif kuyruklara giriş/çıkış yap
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
            // Belirli bir kuyruk ID'si, dahili numarası veya kuyruk adı tuşlandı (*81<no> / *80<no>)
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

        // Kuyruğa giriş veya çıkış yapıldığında aktif mola kaydı varsa kapat.
        // Çıkışta statik kuyruklarda hâlâ üye olduğundan (belki molada) kayıt açık kalır.
        if (!$join && !empty(QueueHelper::staticQueuesOf($ext))) break;
        $stmt_close_pause = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt_close_pause->execute([$ext]);
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
