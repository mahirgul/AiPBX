<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Asterisk (Santral) Gelişmiş Ayarları Service
 */
class AsteriskSettingsService {
    public static function defaults(): array
    {
        return [
            // Section 1: PJSIP & SIP
            'pjsip_wss_port' => '8089',
            'pjsip_udp_port' => '5060',
            'pjsip_external_ip' => '',
            'pjsip_local_net' => '192.168.1.0/24',
            'pjsip_codecs' => 'opus,alaw,ulaw',
            'pjsip_wired_codecs' => 'alaw,ulaw,g729',
            'pjsip_user_agent' => 'Asterisk PBX',
            'pjsip_direct_media' => 'no',
            'pjsip_rtp_symmetric' => 'yes',
            'pjsip_qualify_frequency' => '60',
            'pjsip_internal_dial_timeout' => '30',
            'pjsip_external_dial_timeout' => '60',

            // RTP (medya) ayarları — /etc/asterisk/rtp.conf'a SyncRtpSettings ile
            // yazılır. rtp_strict: Asterisk, öğrendiği uzak adres/porttan BAŞKA
            // bir kaynaktan gelen RTP'yi SESSİZCE düşürür; karşı santral medyayı
            // beklenmedik bir porttan gönderirse "ses yok" şikayeti üretir ve
            // logda hiç iz bırakmaz. Teşhis için kapatılabilsin diye panele alındı
            // (2026-09-01). Port aralığı coturn'ünkiyle (13479-14999) ÇAKIŞMAMALI.
            'rtp_start'  => '10000',
            'rtp_end'    => '12999',
            'rtp_strict' => 'yes',

            // T.38 UDPTL (faks medya) ayarları — /etc/asterisk/udptl.conf'a SyncUdptlSettings ile yazılır.
            'udptl_start'       => '4100',
            'udptl_end'         => '4999',
            'udptl_checksums'   => 'yes',
            'udptl_fec_entries' => '3',
            'udptl_fec_span'    => '3',

            // Marka & Logo Ayarları artık ayrı bir sayfada (src/brand_settings.php)

            // Softphone zil/çevirme tonu (boş = header_phone.js'teki varsayılan dosya)
            'webrtc_ring_incoming' => '',
            'webrtc_ring_outgoing' => '',

            // Görüntülü arama (WebRTC video). Varsayılan KAPALI: video ses'e göre
            // kat kat fazla bant genişliği tüketir, kurum ağı hazır olmadan
            // açılmamalı. Kapalıyken softphone'da "Görüntülü Ara" butonu hiç
            // görünmez ve gelen video teklifleri sesli olarak karşılanır.
            // Çözünürlük/fps koda gömülmez, buradan yönetilir (getUserMedia
            // constraint'i olarak header_phone.js'e aktarılır).
            'video_calls_enabled'  => '0',
            'video_max_resolution' => '1280x720',
            'video_max_framerate'  => '24',

            // Sistem varsayılan sesli anons dili (Gelen Rota/IVR/Kuyruk kendi dilini
            // ayarlamazsa buna düşer). /etc/asterisk/asterisk.conf'a syncDefaultLanguage()
            // ile yazılır — DİKKAT: diğer tüm ayarların aksine sadece TAM Asterisk
            // yeniden başlatmasıyla devreye girer, "reload" yetmez (bkz. asterisk_sync.php).
            'system_default_language' => 'tr',
        ];
    }

    /**
     * @return array{success:bool, message?:string, error?:string}
     */
    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $db = getDB();
        // Codec adları doğrudan pjsip endpoint'lerinin `allow=` satırına yazılıyor
        // (SyncExtensions.php). POST'tan gelen ham değeri config dosyasına
        // taşımamak için whitelist'ten geçiriliyor — listede olmayan her şey
        // sessizce düşer, sıra korunur. Video codec'leri (vp8/h264) yalnızca
        // WebRTC tarafında anlamlı: masaüstü SIP telefonlar video yapmıyor.
        $allowed_audio = ['opus', 'alaw', 'ulaw', 'g722', 'g729'];
        $allowed_webrtc = array_merge($allowed_audio, ['vp8', 'h264']);
        $filter_codecs = static function ($posted, array $allowed, string $fallback): string {
            $list = array_values(array_intersect(array_map('strval', (array)$posted), $allowed));
            return $list ? implode(',', $list) : $fallback;
        };
        $submitted_codecs = isset($post['codecs'])
            ? $filter_codecs($post['codecs'], $allowed_webrtc, 'opus,alaw,ulaw')
            : 'opus,alaw,ulaw';
        $submitted_wired_codecs = isset($post['wired_codecs'])
            ? $filter_codecs($post['wired_codecs'], $allowed_audio, 'alaw,ulaw')
            : 'alaw,ulaw,g729';

        $new_settings = [
            'pjsip_wss_port' => trim($post['pjsip_wss_port'] ?? '8089'),
            'pjsip_udp_port' => trim($post['pjsip_udp_port'] ?? '5060'),
            'pjsip_external_ip' => trim($post['pjsip_external_ip'] ?? ''),
            'pjsip_local_net' => trim($post['pjsip_local_net'] ?? '192.168.1.0/24'),
            'pjsip_codecs' => $submitted_codecs,
            'pjsip_wired_codecs' => $submitted_wired_codecs,
            // SIP User-Agent/Server başlığı (pjsip.conf [global] user_agent) — dışarıya
            // hangi sürüm/ürün bilgisini verdiğimizi admin belirleyebilsin diye
            // ayarlanabilir yapıldı (2026-08-31, kullanıcı isteği). CR/LF temizleniyor:
            // ham SIP başlığına gidiyor, satır sonu kabul edilirse başlık enjeksiyonu olur.
            'pjsip_user_agent' => preg_replace('/[\r\n]+/', ' ', trim($post['pjsip_user_agent'] ?? 'Asterisk PBX')),
            'pjsip_direct_media' => trim($post['pjsip_direct_media'] ?? 'no'),
            'pjsip_rtp_symmetric' => trim($post['pjsip_rtp_symmetric'] ?? 'yes'),
            'pjsip_qualify_frequency' => max(0, intval($post['pjsip_qualify_frequency'] ?? 60)),
            'pjsip_internal_dial_timeout' => max(5, min(120, intval($post['pjsip_internal_dial_timeout'] ?? 30))),
            'pjsip_external_dial_timeout' => max(5, min(180, intval($post['pjsip_external_dial_timeout'] ?? 60))),

            'webrtc_ring_incoming' => preg_replace('/[^a-zA-Z0-9_-]/', '', trim($post['webrtc_ring_incoming'] ?? '')),
            'webrtc_ring_outgoing' => preg_replace('/[^a-zA-Z0-9_-]/', '', trim($post['webrtc_ring_outgoing'] ?? '')),

            // Görüntülü arama. Çözünürlük serbest metin DEĞİL: doğrudan
            // getUserMedia constraint'ine gidiyor, whitelist dışına çıkılmıyor.
            'video_calls_enabled' => !empty($post['video_calls_enabled']) ? '1' : '0',
            'video_max_resolution' => in_array($post['video_max_resolution'] ?? '', ['640x360', '960x540', '1280x720', '1920x1080'], true)
                ? $post['video_max_resolution'] : '1280x720',
            'video_max_framerate' => in_array(intval($post['video_max_framerate'] ?? 24), [15, 24, 30], true)
                ? strval(intval($post['video_max_framerate'])) : '24',

            'system_default_language' => in_array($post['system_default_language'] ?? '', getAvailableLanguages(), true)
                ? $post['system_default_language'] : 'tr',
        ];

        // --- RTP (medya) ayarları -------------------------------------------
        $rtp_start = max(1024, min(65534, intval($post['rtp_start'] ?? 10000)));
        $rtp_end   = max(1025, min(65535, intval($post['rtp_end'] ?? 12999)));
        if ($rtp_end <= $rtp_start) {
            return ['success' => false, 'error' => 'RTP bitiş portu, başlangıç portundan büyük olmalı!'];
        }
        // Asterisk her çağrı için aralıktan port çiftleri ayırıyor; çok dar bir
        // aralık eşzamanlı çağrıları sessizce sınırlar.
        if (($rtp_end - $rtp_start) < 100) {
            return ['success' => false, 'error' => 'RTP port aralığı en az 100 port olmalı (eşzamanlı çağrı sayısını sınırlar).'];
        }
        // coturn ile çakışma: ikisi de aynı sunucuda ve aynı portu ikisi birden
        // bağlayamaz — çakışırsa TURN relay'i ya da RTP sessizce bozulur.
        // (Bu çakışma 2026-08-20'de gerçekten yaşandı, aralık o yüzden bölünmüştü.)
        $coturn_start = 13479;
        $coturn_end   = 14999;
        if ($rtp_start <= $coturn_end && $rtp_end >= $coturn_start) {
            return ['success' => false, 'error' =>
                "RTP aralığı ({$rtp_start}-{$rtp_end}) coturn'ün TURN relay aralığıyla ({$coturn_start}-{$coturn_end}) çakışıyor! "
                . 'Bu, WebRTC ses yolunu bozar. Farklı bir aralık seçin.'];
        }
        $new_settings['rtp_start']  = (string) $rtp_start;
        $new_settings['rtp_end']    = (string) $rtp_end;
        $new_settings['rtp_strict'] = (($post['rtp_strict'] ?? 'yes') === 'no') ? 'no' : 'yes';

        // --- T.38 UDPTL (faks medya) ayarları --------------------------------
        $udptl_start = max(1024, min(65534, intval($post['udptl_start'] ?? 4100)));
        $udptl_end   = max(1025, min(65535, intval($post['udptl_end'] ?? 4999)));
        if ($udptl_end <= $udptl_start) {
            return ['success' => false, 'error' => 'UDPTL bitiş portu, başlangıç portundan büyük olmalı!'];
        }
        $new_settings['udptl_start']       = (string) $udptl_start;
        $new_settings['udptl_end']         = (string) $udptl_end;
        $new_settings['udptl_checksums']   = (($post['udptl_checksums'] ?? 'yes') === 'no') ? 'no' : 'yes';
        $new_settings['udptl_fec_entries'] = (string) max(0, min(9, intval($post['udptl_fec_entries'] ?? 3)));
        $new_settings['udptl_fec_span']    = (string) max(0, min(9, intval($post['udptl_fec_span'] ?? 3)));

        // Port aralığı DEĞİŞTİYSE reload yetmez, tam restart gerekir — kullanıcıya
        // bunu söyleyebilmek için önceki değerle karşılaştırılıyor.
        $rtp_range_changed = (getSystemSetting('rtp_start', '10000') !== $new_settings['rtp_start'])
                          || (getSystemSetting('rtp_end', '12999') !== $new_settings['rtp_end']);

        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($new_settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Transport-level ayarları (port/dış IP/yerel ağlar) pjsipsettings tablosuna yansıt
        $pj_stmt = $db->prepare('INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES (?, ?, 1, 0) ON DUPLICATE KEY UPDATE data = VALUES(data)');
        $pj_stmt->execute(['bindport', $new_settings['pjsip_udp_port']]);
        $pj_stmt->execute(['wss_bindport', $new_settings['pjsip_wss_port']]);
        $pj_stmt->execute(['externip_val', $new_settings['pjsip_external_ip']]);

        $net_index = 0;
        foreach (array_filter(array_map('trim', explode(',', $new_settings['pjsip_local_net']))) as $net) {
            $parts = explode('/', $net, 2);
            $net_ip = $parts[0];
            $cidr = isset($parts[1]) ? intval($parts[1]) : 24;
            if ($cidr < 0 || $cidr > 32) $cidr = 24;
            $net_mask = long2ip(-1 << (32 - $cidr));
            $pj_stmt->execute(["localnet_{$net_index}", $net_ip]);
            $pj_stmt->execute(["netmask_{$net_index}", $net_mask]);
            $net_index++;
        }

        // Bu tek kayıt işlemi birden fazla domain'i BİRDEN etkiliyor (port/IP/ağ
        // değişikliği transport'ları, kodek/timeout değişikliği dahili+dialplan'ı,
        // vb.) — her biri kendi domain'inde işaretlenir, Asterisk'e ANINDA
        // dokunulmaz; admin /pending-sync sayfasından Gönder'e basana kadar bekler
        // (2026-08-24, ertelenmiş reload sistemi — syncDefaultLanguage() bunun
        // DIŞINDA tutuldu, çünkü o "reload" değil TAM RESTART gerektiriyor, ayrı
        // bir onay/aksiyon kategorisi, Dashboard'daki Restart butonuyla ilişkili).
        $uid = $_SESSION['user_id'] ?? null;
        $label = "Genel Asterisk Ayarları (PJSIP/kodek/zaman aşımı)";
        markPendingSync('transports', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('extensions', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('inbound_dialplan', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('outbound_dialplan', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('general_dialplan', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('ivrs', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('queues', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('rtp', 'system_setting', 'general', $label, 'update', $uid);
        markPendingSync('udptl', 'system_setting', 'general', $label, 'update', $uid);

        $lang_changed = syncDefaultLanguage($new_settings['system_default_language']);

        $message = "Tüm santral gelişmiş ayarları kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        if ($lang_changed) {
            $message .= " Sistem varsayılan dili değişti — devreye girmesi için Asterisk'in TAM yeniden başlatılması gerekiyor (sadece reload yetmez); bu otomatik yapılamadı, lütfen yönetici oturumundan (Claude) yeniden başlatılmasını isteyin.";
        }
        if ($rtp_range_changed) {
            $message .= " RTP port aralığı değişti — 'strictrtp' Uygula ile devreye girer, ancak PORT ARALIĞI için Asterisk'in TAM yeniden başlatılması gerekir (port havuzu modül yüklenirken bir kez ayrılıyor).";
        }

        return ['success' => true, 'message' => $message];
    }
}
