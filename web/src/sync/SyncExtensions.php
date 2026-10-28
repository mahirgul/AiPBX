<?php
/**
 * Single Master File Extension Sync Module (/etc/asterisk/pbx/pjsip_endpoints.conf)
 * Consolidates all active subscriber endpoints into a single master configuration file reading from `sip` key-value table.
 */

require_once __DIR__ . '/../sip_helper.php';

/**
 * HOST list the PJSIP identify block accepts in the From header.
 *
 * Why: the identify block looked only at the user part
 * (`<?sip:3001[@:]`), so it matched whatever the HOST part of the From header
 * was. MEASURED with synthetic SIP OPTIONS on 2026-09-05:
 * `From: <sip:3001@198.51.100.1>` (the trunk's address) also matches the
 * `3001-sip` endpoint, while `sip:999999@198.51.100.1` gets "No matching
 * endpoint". So a call from the outside line whose caller number equals a
 * portal extension could match that user's endpoint instead of the trunk
 * endpoint and fail with 401 because auth was requested (bulgu2.md B2-3).
 * NOT an authentication hole — a functional risk.
 *
 * Fix: tie the host part to the PBX's OWN addresses. Real desk phones send
 * `From: "3001" <sip:3001@pbx.example.com>` in the logs, i.e. they use the
 * PBX's address; a call from outside carries its own/remote host.
 *
 * The list can be OVERRIDDEN by the `pjsip_identify_hosts` setting (comma
 * separated). Automatic sources: pjsip_external_ip, the server's local IPv4
 * addresses, the Apache ServerName and localhost. If the list ends up empty
 * the OLD WIDE pattern is used — falling back to the old behaviour beats a
 * wrong narrowing that unregisters the phones.
 *
 * @return string[] raw host values that go into the regex
 */
function pjsipIdentifyHosts(): array
{
    $elle = trim((string) getSystemSetting('pjsip_identify_hosts', ''));
    if ($elle !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $elle))));
    }

    $hosts = ['localhost', '127.0.0.1'];

    $external = trim((string) getSystemSetting('pjsip_external_ip', ''));
    if ($external !== '') { $hosts[] = $external; }

    if (function_exists('net_get_interfaces')) {
        foreach ((array) net_get_interfaces() as $iface) {
            foreach ($iface['unicast'] ?? [] as $u) {
                if (!empty($u['address']) && filter_var($u['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $hosts[] = $u['address'];
                }
            }
        }
    }

    // Apache ServerName — masaustu telefonlarin From hostu bu (loglarda dogrulandi).
    foreach (glob('/etc/apache2/sites-enabled/*.conf') as $f) {
        $icerik = @file_get_contents($f);
        if ($icerik !== false && preg_match_all('/^\s*ServerName\s+(\S+)/mi', $icerik, $m)) {
            foreach ($m[1] as $sn) { $hosts[] = trim($sn); }
        }
    }

    return array_values(array_unique(array_filter($hosts)));
}

function syncExtension($ext) {
    return syncAllExtensions();
}

/** Audio codecs of a stored codec list (the WebRTC list also holds the chosen video codecs). */
function pbxAudioCodecs(string $list): array {
    return array_values(array_diff(array_filter(array_map('trim', explode(',', $list))), ['vp8', 'h264']));
}

/**
 * The video codec for the `allow=` lines: none while video calls are off,
 * otherwise exactly ONE for every endpoint. Asterisk does not convert video:
 * when the two legs of a call settle on different video codecs it hangs the
 * whole call up ("Don't know how to send format h264 packets"). With one
 * codec everywhere a device that lacks it only goes without video.
 * VP8: browsers and the apps. H.264: also desk video phones.
 */
function pbxVideoCodecs(): array {
    if (getSystemSetting('video_calls_enabled', '0') !== '1') {
        return [];
    }
    $codec = getSystemSetting('video_codec', '');
    if (!in_array($codec, ['vp8', 'h264'], true)) {
        // Before 1.22 the codecs were ticked with the audio codecs.
        $old = explode(',', getSystemSetting('pjsip_codecs', ''));
        $codec = in_array('vp8', $old, true) || !in_array('h264', $old, true) ? 'vp8' : 'h264';
    }
    return [$codec];
}

function syncAllExtensions() {
    return withSyncLock('extensions', '__syncAllExtensionsBody');
}

function __syncAllExtensionsBody() {
    $db = getDB();
    // Fax users (extension_type='fax') never register over SIP (an incoming
    // fax lands directly in [from-trunk-fax], an outgoing fax is sent with a
    // call file) — so NO PJSIP endpoint/AOR/auth is generated for them.
    $users = $db->query("SELECT id, extension, full_name, sip_password, sip_auth_digest, cid_internal, cid_external, pickup_group, role, outbound_group, permission_group_id, voicemail_enabled FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 AND extension_type = 'sip' ORDER BY extension ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Two `sys_users` rows can share one extension — an imported account plus one
    // re-created by hand, say. The loop below writes a full endpoint/auth/identify
    // set for every row, and PJSIP rejects the *whole* file when an object is
    // defined twice ("configuration contains a duplicate object"): the reload
    // fails and the box runs with no endpoints at all, so every phone unregisters,
    // not only the duplicated extension. Keep the first row per extension — the
    // id ASC in the ORDER BY above makes that choice stable between runs — and log
    // the rows left out, because dropping them silently is how the duplicate
    // stays invisible.
    $unique = [];
    $kept_id = [];
    foreach ($users as $row) {
        $key = preg_replace('/[^0-9]/', '', (string)$row['extension']);
        // A non-numeric extension generates no endpoint below, so it cannot
        // collide with another row; pass it through untouched.
        if ($key === '') {
            $unique[] = $row;
            continue;
        }
        if (isset($kept_id[$key])) {
            error_log("syncAllExtensions: extension {$key} appears in more than one user row"
                . " — keeping user id {$kept_id[$key]}, skipping id {$row['id']}");
            continue;
        }
        $kept_id[$key] = $row['id'];
        $unique[] = $row;
    }
    $users = $unique;

    // Video codecs only while video calls are on (Admin -> Asterisk -> Video).
    $video_codecs = pbxVideoCodecs();
    $webrtc_codecs = implode(',', array_merge(pbxAudioCodecs(getSystemSetting('pjsip_codecs', 'opus,ulaw,alaw,g722')), $video_codecs));
    $wired_codecs = implode(',', array_merge(pbxAudioCodecs(getSystemSetting('pjsip_wired_codecs', 'alaw,ulaw,g729')), $video_codecs));
    $direct_media = getSystemSetting('pjsip_direct_media', 'no');
    $rtp_symmetric = getSystemSetting('pjsip_rtp_symmetric', 'yes');
    $qualify_frequency = intval(getSystemSetting('pjsip_qualify_frequency', '60'));
    $qualify_frequency_mobile = intval(getSystemSetting('pjsip_qualify_frequency_mobile', '0'));

    $conf = "; ==========================================================\n";
    $conf .= "; Master PJSIP Endpoints Configuration (Auto-generated by AI PBX via sip Key-Value DB)\n";
    $conf .= "; ==========================================================\n\n";

    // 1. Base Templates
    // Standard SIP devices (desk phone, classic softphone): NO DTLS/ICE/AVPF
    $conf .= "[endpoint-sip](!)\n";
    $conf .= "type=endpoint\n";
    $conf .= "context=from-internal-pbx\n";
    $conf .= "disallow=all\n";
    $conf .= "allow={$wired_codecs}\n";
    $conf .= "transport=transport-udp\n";
    $conf .= "identify_by=auth_username,username\n";
    $conf .= "rtp_symmetric={$rtp_symmetric}\n";
    $conf .= "rewrite_contact=yes\n";
    $conf .= "force_rport=yes\n";
    $conf .= "direct_media=no\n";
    $conf .= "dtmf_mode=rfc4733\n";
    $conf .= "rtp_timeout=60\n";
    $conf .= "rtp_timeout_hold=300\n\n";

    // WebRTC (browser/WSS) clients: DTLS-SRTP, ICE, AVPF
    $conf .= "[endpoint-wss](!)\n";
    $conf .= "type=endpoint\n";
    $conf .= "context=from-internal-pbx\n";
    $conf .= "disallow=all\n";
    $conf .= "allow={$webrtc_codecs}\n";
    $conf .= "transport=transport-ws\n";
    $conf .= "identify_by=auth_username,username\n";
    $conf .= "dtmf_mode=rfc4733\n";
    // With video on, no RTP timeout here: WebRTC bundles audio and video on one
    // port and Asterisk never sees the video stream as active, so it would hang
    // up every video call after rtp_timeout ("lack of video RTP activity"), as
    // well as a video call answered with audio only. Dead calls still end
    // through SIP session timers (the web phone and the apps refresh them).
    $wss_timeout = $video_codecs ? 0 : 30;
    $conf .= "rtp_timeout={$wss_timeout}\n";
    $conf .= "rtp_timeout_hold=" . ($video_codecs ? 0 : 300) . "\n";
    $conf .= "webrtc=yes\n";
    $conf .= "rtcp_mux=yes\n";
    $conf .= "direct_media={$direct_media}\n";
    $conf .= "media_use_received_transport=yes\n";
    $conf .= "ice_support=yes\n";
    $conf .= "use_avpf=yes\n";
    $conf .= "media_encryption=dtls\n";
    $conf .= "dtls_verify=fingerprint\n";
    $conf .= "dtls_setup=actpass\n";
    $conf .= "dtls_cert_file=" . PJSIP_DTLS_CERT . "\n";
    $conf .= "dtls_ca_file=" . PJSIP_DTLS_CERT . "\n";
    $conf .= "rtp_symmetric={$rtp_symmetric}\n";
    $conf .= "rewrite_contact=yes\n";
    $conf .= "force_rport=yes\n\n";

    $conf .= "[auth-userpass](!)\n";
    $conf .= "type=auth\n";
    $conf .= "auth_type=userpass\n\n";

    // Dual-endpoint AOR isolation: every endpoint is bound ONLY to its own AOR.
    // NO cross binding: PJSIP_DIAL_CONTACTS(<ext>-sip) scans ALL AORs of the
    // endpoint; if the -sip endpoint were bound to the -webrtc AOR, the
    // webphone contact would be dialed with the DTLS-less endpoint config and
    // the browser would fail with "Called with SDP without DTLS fingerprint".
    // Parallel ringing comes from the dialplan joining C_SIP & C_WEB.
    // (remove_existing=no → a second device of the same kind does not delete the first)
    $conf .= "[aor-dual](!)\n";
    $conf .= "type=aor\n";
    $conf .= "max_contacts=10\n";
    $conf .= "remove_existing=no\n";
    $conf .= "default_expiration=1800\n";
    $conf .= "maximum_expiration=3600\n";
    $conf .= "minimum_expiration=60\n";
    $conf .= "qualify_frequency={$qualify_frequency}\n\n";

    // Mobile WebRTC AOR template (M6):
    // One mobile device; when a new connection arrives the old dead contact is removed at once
    $conf .= "[aor-mobile](!)\n";
    $conf .= "type=aor\n";
    $conf .= "max_contacts=1\n";
    $conf .= "remove_existing=yes\n";
    $conf .= "default_expiration=1800\n";
    $conf .= "maximum_expiration=3600\n";
    $conf .= "minimum_expiration=60\n";
    $conf .= "qualify_frequency={$qualify_frequency_mobile}\n\n";

    // 2. Active Extension Endpoints (Driven by `sip` Key-Value Data)
    // Identify bloklarinda kabul edilecek From host'lari (tek sefer hesaplanir).
    $identify_hosts = pjsipIdentifyHosts();
    $identify_host_re = implode('|', array_map(function ($h) {
        return str_replace(['.', '-'], ['\\.', '\\-'], $h);
    }, $identify_hosts));

    $active_perm_groups = array_flip(array_map('intval', $db->query('SELECT id FROM pbx_permission_groups WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN)));

    foreach ($users as $u) {
        $ext = preg_replace('/[^0-9]/', '', (string)$u['extension']);
        if ($ext === '') continue;
        $name = toCleanAscii($u['full_name']);

        // Fetch settings from `sip` table (or fallback to sync)
        $sip_map = SIPHelper::getSettingsMap($ext);
        if (empty($sip_map)) {
            SIPHelper::syncExtensionToSIP($ext, $u['full_name'], $u['sip_password'], $u['sip_auth_digest'] ?? 1);
            $sip_map = SIPHelper::getSettingsMap($ext);
        }

        $secret = !empty($sip_map['secret']) ? $sip_map['secret'] : (!empty($u['sip_password']) ? $u['sip_password'] : 'Pass' . $ext . '!');
        $auth_digest = isset($u['sip_auth_digest']) ? (intval($u['sip_auth_digest']) === 1) : true;
        if (isset($sip_map['auth_digest']) && !isset($u['sip_auth_digest'])) {
            $auth_digest = !in_array(strtolower($sip_map['auth_digest']), ['no', '0', 'false', 'off'], true);
        }
        // The user's outbound route group decides the context. Group 1 (the
        // default) keeps the old name, so no endpoint's context changes on
        // installs without groups. Other groups go to their own context and see
        // only that group's outbound routes.
        //
        // A context set by hand in the sip table wins (an escape hatch for
        // special cases).
        $group = max(1, intval($u['outbound_group'] ?? 1));
        $stored_ctx = trim($sip_map['context'] ?? '');

        // Ingress contexts per device origin (Desktop, Mobile, Web).
        // Custom context entered explicitly in `sip` table wins only if it is
        // not one of the default or generated PBX contexts (V3-01).
        $isDefaultOrGeneratedContext = empty($stored_ctx)
            || $stored_ctx === 'from-internal-pbx'
            || preg_match('/^from-internal-g\d+$/', $stored_ctx)
            || preg_match('/^from-internal-(desktop|mobile|web)(-\d+)?$/', $stored_ctx);

        if ($isDefaultOrGeneratedContext) {
            $sip_context = ($group > 1) ? "from-internal-desktop-{$group}" : 'from-internal-desktop';
            $mob_context = ($group > 1) ? "from-internal-mobile-{$group}" : 'from-internal-mobile';
            $web_context = ($group > 1) ? "from-internal-web-{$group}" : 'from-internal-web';
        } else {
            $sip_context = $stored_ctx;
            $mob_context = $stored_ctx;
            $web_context = $stored_ctx;
        }

        $callerid = !empty($sip_map['callerid']) ? $sip_map['callerid'] : "\"{$name}\" <{$ext}>";
        $transport = !empty($sip_map['transport']) ? $sip_map['transport'] : 'transport-wss';
        $max_contacts = !empty($sip_map['max_contacts']) ? $sip_map['max_contacts'] : '5';

        // The user's internal/external call CID preferences are embedded into
        // the endpoint as channel variables; [from-internal-outbound] applies
        // one of them as CALLERID(num) depending on the route type (see
        // SyncDialplan.php).
        $cid_internal = toCleanAscii($u['cid_internal'] ?? '');
        $cid_external = toCleanAscii($u['cid_external'] ?? '');
        $role = trim($u['role'] ?? '');
        // Only an active group has its perm-group-N context (SyncPermissions);
        // a disabled or deleted group means no restriction, not a dead end.
        $perm_group = intval($u['permission_group_id'] ?? 1);
        if (!isset($active_perm_groups[$perm_group])) {
            $perm_group = 1;
        }
        $set_vars = "set_var=CID_INTERNAL={$cid_internal}\nset_var=CID_EXTERNAL={$cid_external}\nset_var=__CID_INTERNAL={$cid_internal}\nset_var=__CID_EXTERNAL={$cid_external}\nset_var=USER_ROLE={$role}\nset_var=PERMISSION_GROUP_ID={$perm_group}\n";

        // For group pickup: extensions in the same pickup_group can pick up
        // each other's ringing call with *20 (see SyncFeatureCodes.php).
        $pickup_group = preg_replace('/[^A-Za-z0-9_-]/', '', trim($u['pickup_group'] ?? '')) ?? '';
        $pickup_lines = '';
        if ($pickup_group !== '') {
            $pickup_lines = "named_call_group={$pickup_group}\nnamed_pickup_group={$pickup_group}\n";
        }

        $conf .= "; Extension {$ext}: {$name} (Dual-Endpoint: standard SIP + WebRTC)\n";
        $conf .= "[{$ext}-sip](endpoint-sip)\n";
        if ($auth_digest) {
            $conf .= "auth=auth{$ext}-sip\n";
        }
        $conf .= "aors={$ext}-sip,{$ext}\n";
        $conf .= "context={$sip_context}\n";
        $conf .= "callerid={$callerid}\n";
        $conf .= $set_vars;
        $conf .= "set_var=AIPBX_CLIENT_TYPE=desktop\n";
        $conf .= $pickup_lines;
        // Voicemail lamp on desk phones (MWI): new messages are signalled to
        // the phone. A mailboxes value set by hand in the `sip` table wins.
        if (!empty($u['voicemail_enabled']) && empty($sip_map['mailboxes'])) {
            $conf .= "mailboxes={$ext}@default\n";
        }

        if ($transport !== 'transport-wss') {
            $conf .= "transport={$transport}\n";
        }

        // Pass-through any extra custom PJSIP parameters stored in `sip` table.
        // WebRTC-specific keys (dtls/ice/avpf/direct_media) are NEVER written to
        // the standard SIP endpoint — desk phones are incompatible with them.
        $ignore_keys = ['type', 'account', 'secret', 'context', 'callerid', 'transport', 'max_contacts', 'remove_existing', 'auth_digest'];
        $sip_blocked = [
            'disallow', 'allow', 'rtp_symmetric', 'rewrite_contact', 'force_rport',
            'direct_media', 'ice_support', 'use_avpf', 'media_encryption',
            'media_encryption_optimistic', 'dtls_verify', 'dtls_setup',
            'dtls_cert_file', 'dtls_ca_file', 'dtls_rekey', 'force_avp'
        ];
        foreach ($sip_map as $k => $v) {
            if (in_array($k, $ignore_keys) || $v === '') continue;
            if (!in_array($k, $sip_blocked)) {
                $conf .= toCleanAscii($k) . "=" . toCleanAscii($v) . "\n";
            }
        }
        $conf .= "\n";

        $conf .= "[{$ext}-webrtc](endpoint-wss)\n";
        $conf .= "auth=auth{$ext}-webrtc\n";
        $conf .= "aors={$ext}-webrtc\n";
        $conf .= "context={$web_context}\n";
        $conf .= "callerid={$callerid}\n";
        $conf .= $set_vars;
        $conf .= "set_var=AIPBX_CLIENT_TYPE=web\n";
        $conf .= $pickup_lines . "\n";

        $conf .= "[{$ext}-mob-webrtc](endpoint-wss)\n";
        $conf .= "auth=auth{$ext}-mob-webrtc\n";
        $conf .= "aors={$ext}-mob-webrtc\n";
        $conf .= "context={$mob_context}\n";
        $conf .= "callerid={$callerid}\n";
        $conf .= $set_vars;
        $conf .= "set_var=AIPBX_CLIENT_TYPE=mobile\n";
        $conf .= $pickup_lines . "\n";

        if ($auth_digest) {
            $conf .= "[auth{$ext}-sip](auth-userpass)\n";
            $conf .= "username={$ext}\n";
            $conf .= "password={$secret}\n\n";
        }

        // Standard SIP devices (desk/hardware phones, PhonerLite etc.) send the
        // plain <extension> as username instead of <extension>-sip. The identify
        // block is always added so Asterisk PJSIP can match the extension in
        // the incoming From header (e.g. "3001") to the right endpoint.
        //
        // The host part is limited to the PBX's own addresses (2026-09-05,
        // bulgu2.md B2-3) — see pjsipIdentifyHosts() for the reason and the
        // measurement. With an empty list it falls back to the old wide pattern.
        $conf .= "[{$ext}-sip-identify]\n";
        $conf .= "type=identify\n";
        $conf .= "endpoint={$ext}-sip\n";
        if (!empty($identify_host_re)) {
            $conf .= "match_header=From: /<?sip:{$ext}@({$identify_host_re})/\n\n";
        } else {
            $conf .= "match_header=From: /<?sip:{$ext}[@:]/\n\n";
        }

        $conf .= "[auth{$ext}-webrtc](auth-userpass)\n";
        $conf .= "username={$ext}-webrtc\n";
        $conf .= "password={$secret}\n\n";

        $conf .= "[auth{$ext}-mob-webrtc](auth-userpass)\n";
        $conf .= "username={$ext}-mob-webrtc\n";
        $conf .= "password={$secret}\n\n";

        // The old logic ("use the value if it is above 10, otherwise 10") made
        // 10 a FLOOR that could not be undercut — even when the admin wanted to
        // limit an extension to fewer simultaneous devices (e.g. 2) on purpose,
        // it was always raised to 10 (found in the 2026-08-21 audit). Now any
        // positive configured value is used as is; only a missing/invalid
        // (0 or negative) value falls back to 10.
        $max_c = (intval($max_contacts) > 0) ? intval($max_contacts) : 10;
        $conf .= "[{$ext}-webrtc](aor-dual)\nmax_contacts={$max_c}\n\n";
        $conf .= "[{$ext}-mob-webrtc](aor-mobile)\n\n";
        $conf .= "[{$ext}-sip](aor-dual)\nmax_contacts={$max_c}\n\n";
        $conf .= "[{$ext}](aor-dual)\nmax_contacts={$max_c}\n\n";
    }

    // Clean up old individual files if any exist
    $old_files = glob(ASTERISK_PBX_DIR . '/pjsip_ext_*.conf');
    if (is_array($old_files)) {
        foreach ($old_files as $f) {
            FileHelper::deleteFile($f);
        }
    }
    FileHelper::deleteFile(ASTERISK_PBX_DIR . '/pjsip_00_template.conf');

    return writeConfWithRollback("pjsip_endpoints.conf", $conf, function() {
        AsteriskHelper::assertReloadsOk([
            AsteriskHelper::reloadPJSIP(),
            AsteriskHelper::reloadDialplan(),
        ], t('sync.ctx_extensions'));
    }, t('sync.ctx_extensions'));
}

