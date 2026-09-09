<?php
/**
 * Dialplan Üretim Yardımcıları (paylaşılan "builder" fonksiyonları)
 *
 * Gelen/giden/genel dialplan üreticileri ile SyncIVRs/SyncTimeConditions
 * tarafından ORTAK kullanılan satır üretim fonksiyonları. 2026-08-31'de
 * SyncDialplan.php (516 satır) dört ayrı alana bölünürken buraya ayrıldı —
 * bu dosya "nasıl satır üretilir"i bilir, "hangi config dosyası yazılır"ı bilmez.
 */

require_once __DIR__ . '/../file_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';
/**
 * Kuyruk timeout/başarısızlık sonrası (cc_fallback_action) davranışı
 */
function buildCallCenterFallbackLines($action = 'hangup', $target = '') {
    $target = preg_replace('/[^0-9]/', '', $target ?? '');

    if ($action === 'forward' && $target !== '') {
        $dest_lines = buildDestinationLines('extension', $target);
        return array_values(array_filter(explode("\n", $dest_lines), fn($l) => trim($l) !== ''));
    }
    if ($action === 'closed_msg') {
        return [
            " same => n,Playback(custom/closed)",
            " same => n,Hangup()"
        ];
    }
    return [" same => n,Hangup()"];
}

/**
 * Bir giden rota denemesi (trunk girişi) için CALLERID(num) satırı üretir.
 * Trunk'ın kendi Caller ID maskelemesi varsa o öncelikli (ör. trunk operatörünün
 * zorunlu kıldığı numara); yoksa arayan dahilinin kendi dahili/harici CID tercihi
 * kullanılır (PJSIP endpoint set_var: CID_INTERNAL/CID_EXTERNAL, bkz. SyncExtensions.php).
 * İkisi de boşsa mevcut CALLERID(num) değişmeden kalır.
 *
 * Görünen ad (CALLERID(name)): pbx_trunks.send_caller_name kapalıysa (Dış Hat
 * Ayarları, 2026-08-31 kullanıcı isteği) bu route/trunk üzerinden giden çağrıda
 * açıkça temizlenir — normalde arayan dahilinin kendi SIP istemcisinden/endpoint
 * kimliğinden miras alınan ad trunk'a kadar gidiyordu, bu satır bunu kesiyor.
 * Açıksa dokunulmuyor (mevcut/eski davranış aynen korunuyor). NOT: faks aramaları
 * (FaxSendService::submitCallFile()) bu ayara HİÇ bakmaz, her zaman isimsizdir —
 * bu fonksiyonun kapsamı dışında, ayrı bir origination yolu.
 */
function buildTrunkCallerIdLine($trunk_entry, $is_internal) {
    $lines = '';
    $override = preg_replace('/[^0-9]/', '', trim($trunk_entry['callerid_override'] ?? ''));
    $trunk_name = trim($trunk_entry['trunk_name'] ?? '');

    $trunk_cid = '';
    $send_name = 1;
    if ($trunk_name !== '') {
        $t_stmt = getDB()->prepare("SELECT outbound_caller_id, send_caller_name FROM pbx_trunks WHERE trunk_name = ?");
        $t_stmt->execute([$trunk_name]);
        $t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
        if ($t_row) {
            $trunk_cid = preg_replace('/[^0-9]/', '', trim($t_row['outbound_caller_id'] ?? ''));
            $send_name = intval($t_row['send_caller_name'] ?? 1);
        }
    }

    if (!empty($override)) {
        $lines .= " same => n,Set(CALLERID(num)={$override})\n";
    } elseif (!empty($trunk_cid)) {
        $lines .= " same => n,Set(CALLERID(num)={$trunk_cid})\n";
    } else {
        $cid_var = $is_internal ? 'CID_INTERNAL' : 'CID_EXTERNAL';
        $lines .= " same => n,Set(CALLERID(num)=\${IF(\$[\"\${{$cid_var}}\" != \"\"]?\${{$cid_var}}:\${CALLERID(num)})})\n";
    }

    if (!$send_name) {
        $lines .= " same => n,Set(CALLERID(name)=)\n";
    }

    return $lines;
}

/**
 * Bir dahiliyi çevirmeden (Dial) önce eklenir: DND açıksa Busy, çağrı yönlendirme
 * hedefi varsa oraya Goto — ikisi de "terminal" (Dial bloğu hiç yazılmaz).
 * Yönlendirme hedefi [from-internal-pbx]'e Goto edilir; bu context
 * [from-internal-outbound]'u include ettiği için hedef ister dahili ister
 * dış hat numarası olsun (harici numaralar include zincirinden çözülür) çalışır.
 */
function buildDndCfCheckLines($ext, $db) {
    $stmt = $db->prepare("SELECT dnd_enabled, call_forward_number FROM sys_users WHERE extension = ?");
    $stmt->execute([$ext]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($u['dnd_enabled'])) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(DND aktif - {$ext})",
                " same => n,Hangup(17)"
            ]
        ];
    }

    $cf_target = preg_replace('/[^0-9]/', '', trim($u['call_forward_number'] ?? ''));
    if ($cf_target !== '') {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(Cagri Yonlendirme aktif - {$ext} -> {$cf_target})",
                " same => n,Goto(from-internal-pbx,{$cf_target},1)"
            ]
        ];
    }

    return ['terminal' => false, 'lines' => []];
}

/**
 * Bir dahiliyi aramak için gerekli tüm dialplan satırlarını üretir:
 * 1. DND (Rahatsız Etmeyin) kontrolü
 * 2. Her Zaman Yönlendir (Koşulsuz - CFU) kontrolü
 * 3. Hop Counter (Yönlendirme döngüsü engelleme)
 * 4. PJSIP kontaklarını paralel arama (SIP, WebRTC, Mobil WebRTC)
 * 5. Dial sonu durum yönlendirmeleri:
 *    - BUSY / CONGESTION -> Meşgulken Yönlendir (CFB)
 *    - NOANSWER -> Cevapsızken Yönlendir (CFNA)
 *    - CHANUNAVAIL / boş kontak -> Ulaşılamadı -> CFNA / CFB
 * 6. Döngü engelleme çıkış etiketi
 *
 * @param string $ext Dahili numara (örn: 3001)
 * @param PDO $db Veritabanı bağlantısı
 * @param string $labelPrefix Etiket çakışmasını önleyen önek
 * @return array ['terminal' => bool, 'lines' => string[]]
 */
function buildExtensionDialLines(string $ext, PDO $db, string $labelPrefix = ''): array
{
    static $seq = 0;
    $seq++;
    $lbl = ($labelPrefix !== '' ? $labelPrefix . '_' : '') . $ext . '_' . $seq;

    $stmt = $db->prepare("SELECT dnd_enabled, call_forward_number, cf_busy_number, cf_noanswer_number, cf_noanswer_timeout, allowed_phone_mode FROM sys_users WHERE extension = ?");
    $stmt->execute([$ext]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $dnd = !empty($u['dnd_enabled']);
    $cfAlways = preg_replace('/[^0-9]/', '', trim($u['call_forward_number'] ?? ''));
    $cfBusy = preg_replace('/[^0-9]/', '', trim($u['cf_busy_number'] ?? ''));
    $cfNoAnswer = preg_replace('/[^0-9]/', '', trim($u['cf_noanswer_number'] ?? ''));
    $cfTimeout = intval($u['cf_noanswer_timeout'] ?? 20);
    if ($cfTimeout < 5 || $cfTimeout > 120) {
        $cfTimeout = 20;
    }

    $globalTimeout = intval(getSystemSetting('pjsip_internal_dial_timeout', '30')) ?: 30;
    $dialTimeout = ($cfNoAnswer !== '') ? $cfTimeout : $globalTimeout;
    $hasAnyCf = ($cfAlways !== '' || $cfBusy !== '' || $cfNoAnswer !== '');

    // 1. DND aktif ise doğrudan Meşgul
    if ($dnd) {
        return [
            'terminal' => true,
            'lines' => [
                " same => n,NoOp(DND aktif - {$ext})",
                " same => n,Hangup(17)"
            ]
        ];
    }

    $lines = [];

    // 2. Yönlendirme döngü sayacı (Hop counter)
    if ($hasAnyCf) {
        $lines[] = " same => n,Set(CF_HOPS=\$[0\${CF_HOPS} + 1])";
    }

    // 3. Her Zaman (Koşulsuz) Yönlendirme
    if ($cfAlways !== '') {
        $lines[] = " same => n,NoOp(Her Zaman Yonlendirme aktif - {$ext} -> {$cfAlways})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfAlways},1)";
        $lines[] = " same => n(cf_loop_{$lbl}),NoOp(Cagri Yonlendirme Dongusu Engellendi - {$ext})";
        $lines[] = " same => n,Hangup(17)";
        return [
            'terminal' => true,
            'lines' => $lines
        ];
    }

    // 4. Cihazları Çevirme (SIP, WebRTC, Mobil WebRTC) - Aktif Telefon Modlarına Göre Filtrelenir
    $modes = function_exists('parsePhoneModes')
        ? parsePhoneModes($u['allowed_phone_mode'] ?? null)
        : ['web', 'mobil', 'sip', 'video'];

    $allowSip = in_array('sip', $modes, true);
    $allowWeb = in_array('web', $modes, true);
    $allowMob = in_array('mobil', $modes, true);

    if ($allowSip) {
        $lines[] = " same => n,Set(C_SIP=\${PJSIP_DIAL_CONTACTS({$ext}-sip)})";
    } else {
        $lines[] = " same => n,Set(C_SIP=)";
    }

    if ($allowWeb) {
        $lines[] = " same => n,Set(C_WEB=\${PJSIP_DIAL_CONTACTS({$ext}-webrtc)})";
    } else {
        $lines[] = " same => n,Set(C_WEB=)";
    }

    if ($allowMob) {
        $lines[] = " same => n,Set(C_MOB=\${PJSIP_DIAL_CONTACTS({$ext}-mob-webrtc)})";
    } else {
        $lines[] = " same => n,Set(C_MOB=)";
    }

    // Mobil Push Bildirim Kancası (Katman 1 - Yalnızca push aktifse, mobil izinliyse ve abonenin mobil cihazı varsa)
    $pushEnabled = (string)getSystemSetting('push_enabled', '0') === '1';
    $pushProvider = (string)getSystemSetting('push_provider', 'none');
    $hasMobileDevice = false;

    if ($allowMob && $pushEnabled && $pushProvider !== 'none') {
        $mStmt = $db->prepare("SELECT 1 FROM sys_mobile_devices WHERE extension = ? AND is_active = 1 AND fcm_token IS NOT NULL AND fcm_token <> '' AND push_type <> 'none' LIMIT 1");
        $mStmt->execute([$ext]);
        $hasMobileDevice = (bool)$mStmt->fetchColumn();
    }

    if ($hasMobileDevice) {
        $pushWait = max(3, min(30, intval(getSystemSetting('push_wait_seconds', '8'))));
        $lines[] = " same => n,GotoIf(\$[\"\${C_MOB}\" != \"\"]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Set(PUSH_CNUM=\${FILTER(0-9+,\${CALLERID(num)})})";
        $lines[] = " same => n,Set(PUSH_CNAM=\${FILTER(a-zA-Z0-9 ._+-,\${CALLERID(name)})})";
        $lines[] = " same => n,System(/usr/local/bin/push_dispatcher.php {$ext} \"\${PUSH_CNUM}\" \"\${PUSH_CNAM}\" &)";
        $lines[] = " same => n,GotoIf(\$[\"\${C_SIP}\" != \"\" | \"\${C_WEB}\" != \"\"]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Ringing()";
        $lines[] = " same => n,Set(PUSH_WAIT={$pushWait})";
        $lines[] = " same => n(push_loop_{$lbl}),GotoIf(\$[0\${PUSH_WAIT} <= 0]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Wait(1)";
        $lines[] = " same => n,Set(C_MOB=\${PJSIP_DIAL_CONTACTS({$ext}-mob-webrtc)})";
        $lines[] = " same => n,GotoIf(\$[\"\${C_MOB}\" != \"\"]?lbl_has_mob_{$lbl})";
        $lines[] = " same => n,Set(PUSH_WAIT=\$[0\${PUSH_WAIT} - 1])";
        $lines[] = " same => n,Goto(push_loop_{$lbl})";
        $lines[] = " same => n(lbl_has_mob_{$lbl}),NoOp(Mobil cihaz kontrolu tamamlandi)";
    }

    $lines[] = " same => n,Set(DIAL_CONTACTS=\${C_SIP}\${IF(\$[\"\${C_SIP}\" != \"\" & \"\${C_WEB}\" != \"\"]?&)}\${C_WEB})";
    $lines[] = " same => n,Set(DIAL_CONTACTS=\${DIAL_CONTACTS}\${IF(\$[\"\${DIAL_CONTACTS}\" != \"\" & \"\${C_MOB}\" != \"\"]?&)}\${C_MOB})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIAL_CONTACTS}\" = \"\"]?lbl_unavail_{$lbl})";
    $lines[] = " same => n,Set(JITTERBUFFER(adaptive)=default)";
    $lines[] = " same => n,Dial(\${DIAL_CONTACTS},{$dialTimeout},tTb(sub-callee-jb^s^1))";

    // 5. Dial Sonu Durum Yönlendirmeleri
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"BUSY\"]?lbl_busy_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"CONGESTION\"]?lbl_busy_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"NOANSWER\"]?lbl_noans_{$lbl})";
    $lines[] = " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"CHANUNAVAIL\"]?lbl_unavail_{$lbl})";
    $lines[] = " same => n,Hangup()";

    // 6. Meşgul Durumu
    $lines[] = " same => n(lbl_busy_{$lbl}),NoOp(Dahili {$ext} Mesgul - DIALSTATUS=\${DIALSTATUS})";
    if ($cfBusy !== '') {
        $lines[] = " same => n,NoOp(Mesgulken Yonlendirme aktif - {$ext} -> {$cfBusy})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfBusy},1)";
    } else {
        $lines[] = " same => n,Hangup(17)";
    }

    // 7. Cevapsız Durumu
    $lines[] = " same => n(lbl_noans_{$lbl}),NoOp(Dahili {$ext} Cevapsiz - DIALSTATUS=\${DIALSTATUS})";
    if ($cfNoAnswer !== '') {
        $lines[] = " same => n,NoOp(Cevapsizken Yonlendirme aktif - {$ext} -> {$cfNoAnswer})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfNoAnswer},1)";
    } else {
        $lines[] = " same => n,Hangup()";
    }

    // 8. Ulaşılamadı / Çevrimdışı Durumu
    $lines[] = " same => n(lbl_unavail_{$lbl}),NoOp(Dahili {$ext} Kayitli Cihaz Yok veya Ulasilamiyor)";
    if ($cfNoAnswer !== '') {
        $lines[] = " same => n,NoOp(Ulasilamadi -> Cevapsizken Yonlendirme: {$ext} -> {$cfNoAnswer})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfNoAnswer},1)";
    } elseif ($cfBusy !== '') {
        $lines[] = " same => n,NoOp(Ulasilamadi -> Mesgulken Yonlendirme: {$ext} -> {$cfBusy})";
        $lines[] = " same => n,GotoIf(\$[0\${CF_HOPS} > 3]?cf_loop_{$lbl})";
        $lines[] = " same => n,Goto(from-internal-pbx,{$cfBusy},1)";
    } else {
        $lines[] = " same => n,Hangup()";
    }

    // 9. Döngü Engelleme Çıkışı
    if ($hasAnyCf) {
        $lines[] = " same => n(cf_loop_{$lbl}),NoOp(Cagri Yonlendirme Dongusu Engellendi - {$ext})";
        $lines[] = " same => n,Hangup(17)";
    }

    return [
        'terminal' => false,
        'lines' => $lines
    ];
}

/**
 * Format Native Dialplan Destination Lines
 */
function buildDestinationLines($dest_type, $dest_id, $orig_did = '', $derinlik = 0) {
    $db = getDB();
    $lines = [];
    $internal_dial_timeout = intval(getSystemSetting('pjsip_internal_dial_timeout', '30'));

    switch ($dest_type) {
        case 'queue':
            // 2026-08-19: Kuyruk timeout/fallback/kayıt ayarları artık global sys_settings değil,
            // kuyruk bazlı (pbx_queues) — birden fazla kuyruk farklı davranışlara sahip olabilir.
            $q_row = is_numeric($dest_id)
                ? $db->prepare("SELECT * FROM pbx_queues WHERE id = ?")
                : $db->prepare("SELECT * FROM pbx_queues WHERE queue_name = ?");
            $q_row->execute([$dest_id]);
            $q = $q_row->fetch();
            $q_name = $q['queue_name'] ?? (is_numeric($dest_id) ? 'queue_cc' : preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$dest_id));

            if (!empty($q['record_enabled'] ?? 1)) {
                $rec_format = $q['record_format'] ?? 'wav';
                // Kayıt ZATEN başlamışsa ikincisini başlatma.
                //
                // Gelen rotada "zorunlu kayıt" işaretliyse MixMonitor orada başlıyor;
                // burada ikinci bir tane başlatmak aynı kanala iki kaydedici bağlar,
                // iki ayrı dosya üretir ve CDR(userfield) ikincisiyle ezilir — panelde
                // kayda tıklayınca yalnızca kuyruk parçası dinlenirdi.
                // MIXMONITOR_FILENAME'i MixMonitor'un kendisi doldurur.
                $lines[] = " same => n,GotoIf(\$[\"\${MIXMONITOR_FILENAME}\" != \"\"]?kayit_var_{$q_name})";
                $lines[] = " same => n,Set(REC_FILE=/var/spool/asterisk/monitor/inbound_\${STRFTIME(\${EPOCH},,%Y%m%d_%H%M%S)}_\${CALLERID(num)}.{$rec_format})";
                $lines[] = " same => n,MixMonitor(\${REC_FILE})";
                $lines[] = " same => n,Set(CDR(userfield)=\${REC_FILE})";
                $lines[] = " same => n(kayit_var_{$q_name}),NoOp(Kuyruk kaydi: \${MIXMONITOR_FILENAME})";
            }
            // Kuyruğun kendi dili ayarlanmışsa, buraya kadarki (Gelen Rota/IVR) dil
            // ayarını ezer — kuyruk bekleme anonsları (announce-holdtime/position)
            // arayanın kanal diline göre çalar, en spesifik (en sona en yakın) ayar kazanır.
            if (!empty($q['language'])) {
                $q_lang = preg_replace('/[^a-zA-Z_]/', '', $q['language']);
                $lines[] = " same => n,Set(CHANNEL(language)={$q_lang})";
            }
            $queue_timeout = intval($q['max_wait_seconds'] ?? 300) ?: 300;
            $lines[] = " same => n,Queue({$q_name},tT,,,{$queue_timeout})";
            $lines = array_merge($lines, buildCallCenterFallbackLines($q['fallback_action'] ?? 'hangup', $q['fallback_target'] ?? ''));
            break;

        case 'ivr':
            $lines[] = " same => n,Goto(app-ivr-" . intval($dest_id) . ",s,1)";
            break;

        case 'time_condition':
            $lines[] = " same => n,Goto(app-timecondition-" . intval($dest_id) . ",s,1)";
            break;

        case 'extension':
            // Aynı context/exten bloğu içinde buildDestinationLines('extension',...)
            // birden fazla kez (örn. çoklu kural Zaman Koşulu'nda aynı dahili hem
            // match hem nomatch hedefi olarak) çağrılabilir. Sabit "ext_unavail_{$ext}"
            // label'ı bu durumda mükerrer üretilip Asterisk dialplan reload'ını
            // bozar — her çağrı site'ı için benzersiz bir sayaç eklenir.
            static $ext_label_seq = 0;
            $ext_label_seq++;
            $ext = preg_replace('/[^0-9]/', '', $dest_id);
            $lines[] = " same => n,Set(CHANNEL(accountcode)={$ext})";

            $extDial = buildExtensionDialLines($ext, $db, "dest_{$ext_label_seq}");
            $lines = array_merge($lines, $extDial['lines']);
            break;

        case 'fax':
            // Goto hedefi DID numarasının kendisi olmalı; [from-trunk-fax] context'i
            // _X. deseniyle eşleşir (tek haneli sıra numaraları eşleşmezdi).
            $target = !empty($orig_did) ? $orig_did : (!empty($dest_id) ? $dest_id : 'default');
            $lines[] = " same => n,Goto(from-trunk-fax,{$target},1)";
            break;

        case 'announcement':
            $sound_file = '';
            if (is_numeric($dest_id)) {
                $st = $db->prepare("SELECT audio_file FROM pbx_announcements WHERE id = ? AND is_active = 1");
                $st->execute([$dest_id]);
                $sound_file = $st->fetchColumn() ?: '';
            } else {
                $sound_file = toCleanAscii($dest_id);
            }
            if (!empty($sound_file)) {
                $sound_clean = FileHelper::sanitizeAudioPath($sound_file);
                $lines[] = " same => n,Playback({$sound_clean})";
            }

            // Anons bittikten sonraki hedef. `post_dest_type`/`post_dest_id`
            // panelde kaydediliyordu ama BURADA HIC OKUNMUYORDU: ne secilirse
            // secilsin cagri kapatiliyordu (2026-09-01 alan denetimi).
            //
            // $derinlik, anons -> anons zincirinin sonsuza gitmesini engeller.
            $sonraki_tip = '';
            $sonraki_id  = '';
            if (is_numeric($dest_id)) {
                $ps = $db->prepare("SELECT post_dest_type, post_dest_id FROM pbx_announcements WHERE id = ?");
                $ps->execute([$dest_id]);
                if ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
                    $sonraki_tip = trim((string)($row['post_dest_type'] ?? ''));
                    $sonraki_id  = trim((string)($row['post_dest_id'] ?? ''));
                }
            }

            if ($sonraki_tip !== '' && $sonraki_tip !== 'hangup' && $derinlik < 3) {
                $lines[] = buildDestinationLines($sonraki_tip, $sonraki_id, $orig_did, $derinlik + 1);
            } else {
                if ($derinlik >= 3) {
                    $lines[] = " same => n,NoOp(Anons zinciri cok derin, kapatiliyor)";
                }
                $lines[] = " same => n,Hangup()";
            }
            break;

        case 'hangup':
        default:
            $ha_stmt = $db->prepare("SELECT ha.action_type, anc.audio_file FROM pbx_hangup_actions ha LEFT JOIN pbx_announcements anc ON ha.announcement_id = anc.id WHERE ha.action_key = ? AND ha.is_active = 1 AND (anc.id IS NULL OR anc.is_active = 1)");
            $ha_stmt->execute([$dest_id]);
            $ha_row = $ha_stmt->fetch(PDO::FETCH_ASSOC);
            $action_type = $ha_row['action_type'] ?? $dest_id;

            if (!empty($ha_row['audio_file'])) {
                $sound_clean = FileHelper::sanitizeAudioPath($ha_row['audio_file']);
                $lines[] = " same => n,Playback({$sound_clean})";
            }

            if ($action_type === 'busy' || $dest_id === 'busy') {
                $lines[] = " same => n,Busy(10)";
            } elseif ($action_type === 'congestion' || $dest_id === 'congestion') {
                $lines[] = " same => n,Congestion(10)";
            } else {
                $lines[] = " same => n,Hangup()";
            }
            break;
    }

    return implode("\n", $lines);
}
