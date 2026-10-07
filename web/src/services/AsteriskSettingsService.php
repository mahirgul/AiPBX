<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Asterisk (PBX) advanced settings service
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

            // RTP (media) settings — written to /etc/asterisk/rtp.conf by
            // SyncRtpSettings. rtp_strict: Asterisk SILENTLY drops RTP coming
            // from a source OTHER than the remote address/port it learned; if
            // the far PBX sends media from an unexpected port this produces a
            // "no audio" complaint and leaves no trace in the log. Put in the
            // panel so it can be switched off for diagnosis (2026-09-01). The
            // port range MUST NOT overlap coturn's (13479-14999).
            'rtp_start'  => '10000',
            'rtp_end'    => '12999',
            'rtp_strict' => 'yes',

            // T.38 UDPTL (fax media) settings — written to /etc/asterisk/udptl.conf by SyncUdptlSettings.
            'udptl_start'       => '4100',
            'udptl_end'         => '4999',
            'udptl_checksums'   => 'yes',
            'udptl_fec_entries' => '3',
            'udptl_fec_span'    => '3',

            // Brand & logo settings live on a separate page now (src/brand_settings.php)

            // Softphone ring/ringback tone (empty = the default file in header_phone.js)
            'webrtc_ring_incoming' => '',
            'webrtc_ring_outgoing' => '',

            // Video calls (WebRTC video). OFF by default: video uses many times
            // the bandwidth of audio and should not be enabled before the
            // organisation's network is ready. When off, the softphone never
            // shows the "Video call" button and incoming video offers are
            // answered as audio. Resolution/fps are not hard-coded but managed
            // here (passed to header_phone.js as a getUserMedia constraint).
            'video_calls_enabled'  => '0',
            'video_max_resolution' => '1280x720',
            'video_max_framerate'  => '24',

            // System default spoken prompt language (used when the inbound
            // route/IVR/queue sets no language of its own). Written to
            // /etc/asterisk/asterisk.conf by syncDefaultLanguage() — CAUTION:
            // unlike every other setting it takes effect only after a FULL
            // Asterisk restart, a "reload" is not enough (see asterisk_sync.php).
            'system_default_language' => 'en',
        ];
    }

    /**
     * @return array{success:bool, message?:string, error?:string}
     */
    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }

        $db = getDB();
        // Codec names are written straight into the `allow=` line of the pjsip
        // endpoints (SyncExtensions.php). The raw POST value goes through a
        // whitelist so it never reaches the config file — anything not on the
        // list is silently dropped, the order is kept. Video codecs (vp8/h264)
        // only make sense on the WebRTC side: desk SIP phones do no video.
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
            // SIP User-Agent/Server header (pjsip.conf [global] user_agent) —
            // made configurable so the admin decides which version/product
            // information we reveal (2026-08-31, user request). CR/LF is
            // stripped: it goes into a raw SIP header, accepting line breaks
            // would allow header injection.
            'pjsip_user_agent' => preg_replace('/[\r\n]+/', ' ', trim($post['pjsip_user_agent'] ?? 'Asterisk PBX')),
            'pjsip_direct_media' => trim($post['pjsip_direct_media'] ?? 'no'),
            'pjsip_rtp_symmetric' => trim($post['pjsip_rtp_symmetric'] ?? 'yes'),
            'pjsip_qualify_frequency' => max(0, intval($post['pjsip_qualify_frequency'] ?? 60)),
            'pjsip_internal_dial_timeout' => max(5, min(120, intval($post['pjsip_internal_dial_timeout'] ?? 30))),
            'pjsip_external_dial_timeout' => max(5, min(180, intval($post['pjsip_external_dial_timeout'] ?? 60))),

            'webrtc_ring_incoming' => preg_replace('/[^a-zA-Z0-9_-]/', '', trim($post['webrtc_ring_incoming'] ?? '')),
            'webrtc_ring_outgoing' => preg_replace('/[^a-zA-Z0-9_-]/', '', trim($post['webrtc_ring_outgoing'] ?? '')),

            // Video calls. The resolution is NOT free text: it goes straight
            // into the getUserMedia constraint, so it stays within the whitelist.
            'video_calls_enabled' => !empty($post['video_calls_enabled']) ? '1' : '0',
            'video_max_resolution' => in_array($post['video_max_resolution'] ?? '', ['640x360', '960x540', '1280x720', '1920x1080'], true)
                ? $post['video_max_resolution'] : '1280x720',
            'video_max_framerate' => in_array(intval($post['video_max_framerate'] ?? 24), [15, 24, 30], true)
                ? strval(intval($post['video_max_framerate'] ?? 24)) : '24',

            'system_default_language' => in_array($post['system_default_language'] ?? '', getAvailableLanguages(), true)
                ? $post['system_default_language'] : 'en',
        ];

        // --- RTP (media) settings --------------------------------------------
        $rtp_start = max(1024, min(65534, intval($post['rtp_start'] ?? 10000)));
        $rtp_end   = max(1025, min(65535, intval($post['rtp_end'] ?? 12999)));
        if ($rtp_end <= $rtp_start) {
            return ['success' => false, 'error' => t('srv_asterisk.err_rtp_order')];
        }
        // Asterisk allocates port pairs from the range for every call; a range
        // that is too narrow silently limits concurrent calls.
        if (($rtp_end - $rtp_start) < 100) {
            return ['success' => false, 'error' => t('srv_asterisk.err_rtp_min')];
        }
        // Overlap with coturn: both run on the same server and two of them
        // cannot bind the same port — on overlap the TURN relay or RTP breaks
        // silently. (This overlap really happened on 2026-08-20; that is why
        // the range was split.)
        $coturn_start = 13479;
        $coturn_end   = 14999;
        if ($rtp_start <= $coturn_end && $rtp_end >= $coturn_start) {
            return ['success' => false, 'error' =>
                sprintf(t('srv_asterisk.err_rtp_overlap'), $rtp_start, $rtp_end, $coturn_start, $coturn_end)];
        }
        $new_settings['rtp_start']  = (string) $rtp_start;
        $new_settings['rtp_end']    = (string) $rtp_end;
        $new_settings['rtp_strict'] = (($post['rtp_strict'] ?? 'yes') === 'no') ? 'no' : 'yes';

        // --- T.38 UDPTL (fax media) settings ----------------------------------
        $udptl_start = max(1024, min(65534, intval($post['udptl_start'] ?? 4100)));
        $udptl_end   = max(1025, min(65535, intval($post['udptl_end'] ?? 4999)));
        if ($udptl_end <= $udptl_start) {
            return ['success' => false, 'error' => t('srv_asterisk.err_udptl_order')];
        }
        $new_settings['udptl_start']       = (string) $udptl_start;
        $new_settings['udptl_end']         = (string) $udptl_end;
        $new_settings['udptl_checksums']   = (($post['udptl_checksums'] ?? 'yes') === 'no') ? 'no' : 'yes';
        $new_settings['udptl_fec_entries'] = (string) max(0, min(9, intval($post['udptl_fec_entries'] ?? 3)));
        $new_settings['udptl_fec_span']    = (string) max(0, min(9, intval($post['udptl_fec_span'] ?? 3)));

        // If the port range CHANGED, a reload is not enough and a full restart
        // is needed — compared with the previous value so the user can be told.
        $rtp_range_changed = (getSystemSetting('rtp_start', '10000') !== $new_settings['rtp_start'])
                          || (getSystemSetting('rtp_end', '12999') !== $new_settings['rtp_end']);

        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($new_settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Mirror the transport-level settings (port/external IP/local networks) into the pjsipsettings table
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
        // Drop entries beyond the new list, otherwise a removed network stays active.
        $stale = $db->prepare("DELETE FROM pjsipsettings WHERE keyword REGEXP '^(localnet|netmask)_[0-9]+$' AND CAST(SUBSTRING_INDEX(keyword, '_', -1) AS UNSIGNED) >= ?");
        $stale->execute([$net_index]);

        // This single save affects SEVERAL domains AT ONCE (port/IP/network
        // changes the transports, codec/timeout changes the extensions +
        // dialplan, etc.) — each is marked in its own domain, Asterisk is NOT
        // touched RIGHT AWAY; it waits until the admin presses Apply on the
        // /pending-sync page (2026-08-24, deferred reload system —
        // syncDefaultLanguage() is kept OUTSIDE it because it needs a FULL
        // RESTART rather than a "reload": a separate confirmation/action
        // category, tied to the Restart button on the Dashboard).
        $uid = $_SESSION['user_id'] ?? null;
        $label = "General Asterisk settings (PJSIP/codecs/timeouts)";
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

        $message = t('srv_asterisk.saved');
        if ($lang_changed) {
            $message .= ' ' . t('srv_asterisk.lang_restart');
        }
        if ($rtp_range_changed) {
            $message .= ' ' . t('srv_asterisk.rtp_restart');
        }

        return ['success' => true, 'message' => $message];
    }
}
