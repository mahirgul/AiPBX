<?php
require_once __DIR__ . '/DialplanRuleService.php';
require_once __DIR__ . '/AdvancedPjsipService.php';
/**
 * SIP Trunk Service
 */

class TrunkService {
    /**
     * A PJSIP "timers" value Asterisk accepts (yes, no, required, always). An
     * unknown one makes Asterisk refuse the whole endpoint, so the trunk is gone;
     * "never" was offered once and meant "no".
     */
    public static function timersValue(string $v): string {
        $v = strtolower(trim($v));
        if ($v === 'never') {
            return 'no';
        }
        return in_array($v, ['yes', 'no', 'required', 'always'], true) ? $v : 'yes';
    }

    /** Headers carriers rely on for routing: a trunk's own SIP stack writes these. */
    private const RESERVED_HEADERS = ['via', 'from', 'to', 'call-id', 'cseq', 'contact', 'max-forwards', 'route', 'record-route', 'content-length', 'content-type', 'authorization', 'proxy-authorization'];

    /**
     * Header lines for outgoing calls of a trunk. The values end up in the dialplan, so only plain
     * characters are allowed: nothing that Asterisk would expand or read as a separator.
     *
     * @return array{lines: list<array{0: string, 1: string}>, errors: list<string>}
     */
    public static function parseSipHeaders(string $text): array
    {
        $lines = [];
        $errors = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $i => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z][A-Za-z0-9-]{0,60}):\s*([A-Za-z0-9 ._:;=+@\/<>"-]{1,200})$/D', $line, $m)
                || in_array(strtolower($m[1]), self::RESERVED_HEADERS, true)) {
                $errors[] = sprintf(t('trunks.err_sip_header'), $i + 1);
                continue;
            }
            $lines[] = [$m[1], trim($m[2])];
        }
        return ['lines' => $lines, 'errors' => $errors];
    }

    public static function saveTrunk($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $trunk_id = intval($data['trunk_id'] ?? 0);
            $trunk_name = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['trunk_name'] ?? ''));
            $title = trim($data['title'] ?? $trunk_name);
            $ip_address = trim($data['ip_address'] ?? '');
            $port = intval(($data['port'] ?? '') ?: 5060);
            $transport = trim($data['transport'] ?? 'udp');
            $codecs = trim($data['codecs'] ?? 'alaw,ulaw');
            $t38 = intval($data['t38_support'] ?? 0);
            $qualify = intval(($data['qualify_frequency'] ?? '') ?: 60);
            $send_caller_name = isset($data['send_caller_name']) ? intval($data['send_caller_name']) : 0;
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($trunk_name) || empty($ip_address)) {
                throw new \Exception(t('srv_trunk.err_required'));
            }
            // Format validation: an invalid IP/hostname or an out-of-range port
            // could be written to the PJSIP config and silently produce a
            // broken/useless trunk on reload (found in the 2026-08-21 audit).
            $is_valid_ip = filter_var($ip_address, FILTER_VALIDATE_IP) !== false;
            $is_valid_hostname = (bool)preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $ip_address);
            if (!$is_valid_ip && !$is_valid_hostname) {
                throw new \Exception(sprintf(t('srv_trunk.err_host'), $ip_address));
            }
            $old_name = null;
            if ($trunk_id > 0) {
                $old_name = DBHelper::fetchOne("SELECT trunk_name FROM pbx_trunks WHERE id = ?", [$trunk_id])['trunk_name'] ?? null;
            }
            $renamed = ($old_name !== null && $old_name !== $trunk_name);
            if (($trunk_id <= 0 || $renamed) && DBHelper::fetchOne("SELECT id FROM pbx_trunks WHERE trunk_name = ?", [$trunk_name])) {
                throw new \Exception(sprintf(t('srv_trunk.err_exists'), $trunk_name));
            }
            // An all-digit name shared the `sip` rows (and the PJSIP aor) of the
            // extension with that number: after a restart both were gone.
            if (($trunk_id <= 0 || $renamed) && !preg_match('/[a-zA-Z]/', $trunk_name)) {
                throw new \Exception(t('srv_trunk.err_name_letter'));
            }
            if ($port < 1 || $port > 65535) {
                throw new \Exception(t('srv_trunk.err_port'));
            }

            // Connection mode: 'ip' (IP-based identify, default) or 'register'
            // (the far PBX registers to us). An unknown value falls back to 'ip'.
            $connection_mode = (($data['connection_mode'] ?? 'ip') === 'register') ? 'register' : 'ip';

            $auth_username = trim($data['auth_username'] ?? '');
            $auth_password = trim($data['auth_password'] ?? '');

            // Credentials are REQUIRED in register mode: if no auth= line can be
            // generated, Asterisk accepts the incoming REGISTER WITHOUT A
            // PASSWORD, so anyone who can reach the same IP could take over the trunk.
            if ($connection_mode === 'register' && ($auth_username === '' || $auth_password === '')) {
                throw new \Exception(
                    t('srv_trunk.err_register_auth')
                );
            }

            $registration_enabled = isset($data['registration_enabled']) ? intval($data['registration_enabled']) : 0;
            $registration_expiration = intval($data['registration_expiration'] ?? 3600) ?: 3600;
            if ($registration_expiration < 30 || $registration_expiration > 86400) {
                $registration_expiration = 3600;
            }
            $registration_retry_interval = intval($data['registration_retry_interval'] ?? 60) ?: 60;
            if ($registration_retry_interval < 5 || $registration_retry_interval > 3600) {
                $registration_retry_interval = 60;
            }
            $max_contacts = max(1, min(100, intval($data['max_contacts'] ?? 1)));

            $outbound_proxy = trim($data['outbound_proxy'] ?? '');
            $match_hosts = trim($data['match_hosts'] ?? '');

            $t38_udptl_ec = in_array(strtolower(trim($data['t38_udptl_ec'] ?? '')), ['none', 'redundancy', 'fec'], true)
                ? strtolower(trim($data['t38_udptl_ec']))
                : 'redundancy';

            $t38_udptl_nat = in_array(strtolower(trim($data['t38_udptl_nat'] ?? '')), ['yes', 'no'], true)
                ? strtolower(trim($data['t38_udptl_nat']))
                : 'yes';

            $t38_udptl_maxdatagram = max(100, min(2000, intval($data['t38_udptl_maxdatagram'] ?? 400 ?: 400)));
            $fax_detect = isset($data['fax_detect']) ? intval($data['fax_detect']) : 1;
            $fax_detect_timeout = max(5, min(120, intval($data['fax_detect_timeout'] ?? 10 ?: 10)));

            $rtp_symmetric = in_array(strtolower(trim($data['rtp_symmetric'] ?? '')), ['yes', 'no'], true)
                ? strtolower(trim($data['rtp_symmetric']))
                : 'yes';

            $rewrite_contact = in_array(strtolower(trim($data['rewrite_contact'] ?? '')), ['yes', 'no'], true)
                ? strtolower(trim($data['rewrite_contact']))
                : 'yes';

            $force_rport = in_array(strtolower(trim($data['force_rport'] ?? '')), ['yes', 'no'], true)
                ? strtolower(trim($data['force_rport']))
                : 'yes';

            $from_user = trim($data['from_user'] ?? '');
            $from_domain = trim($data['from_domain'] ?? '');

            // Free-text SIP fields: a reload kept the old object, so a bad value
            // showed no error until Asterisk restarted, and then the whole trunk
            // was gone (a space in From user, seen in a test, 2026-11-13).
            $hostRe = '/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(:\d{1,5})?$/';
            // All digits and dots is meant as an IP address: "10.0.0" is a typo, not a host name.
            $validHost = fn(string $h): bool => filter_var(preg_replace('/:\d{1,5}$/', '', $h), FILTER_VALIDATE_IP) !== false
                || (!preg_match('/^[\d.]+(:\d+)?$/', $h) && (bool) preg_match($hostRe, $h));
            if ($from_user !== '' && !preg_match('/^[A-Za-z0-9._+*#!~-]{1,64}$/', $from_user)) {
                throw new \Exception(sprintf(t('srv_trunk.err_field'), 'From user', $from_user));
            }
            if ($from_domain !== '' && !$validHost($from_domain)) {
                throw new \Exception(sprintf(t('srv_trunk.err_field'), 'From domain', $from_domain));
            }
            if ($outbound_proxy !== '') {
                // Asterisk wants a SIP URI; a bare host name was accepted and did nothing.
                if (!preg_match('/^sips?:/i', $outbound_proxy)) {
                    $outbound_proxy = 'sip:' . $outbound_proxy;
                }
                // Typed as in Asterisk's own files ("\;lr") or plain (";lr"): stored plain,
                // the config writer escapes it.
                $outbound_proxy = str_replace('\\;', ';', $outbound_proxy);
                if (!preg_match('/^sips?:[^\s;<>"\\\\]+(;[A-Za-z0-9._=-]+)*$/i', $outbound_proxy) || !$validHost((string) preg_replace('/^sips?:([^@]*@)?|;.*$/i', '', $outbound_proxy))) {
                    throw new \Exception(sprintf(t('srv_trunk.err_field'), 'Outbound proxy', $outbound_proxy));
                }
            }
            if ($match_hosts !== '') {
                foreach (array_map('trim', explode(',', $match_hosts)) as $m) {
                    $ipPart = (string) preg_replace('#/\d{1,3}$#', '', $m);
                    if ($m === '' || (filter_var($ipPart, FILTER_VALIDATE_IP) === false && !$validHost($m))) {
                        throw new \Exception(sprintf(t('srv_trunk.err_field'), 'Match', $m));
                    }
                }
            }
            $outbound_caller_id = preg_replace('/[^0-9+]/', '', trim($data['outbound_caller_id'] ?? ''));

            $dtmf_mode = in_array(strtolower(trim($data['dtmf_mode'] ?? '')), ['rfc4733', 'inband', 'info', 'auto'], true)
                ? strtolower(trim($data['dtmf_mode']))
                : 'rfc4733';

            $context = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['context'] ?? '')) ?: 'from-trunk-inbound';
            $did_trim_digits = max(0, min(30, intval($data['did_trim_digits'] ?? 0)));
            $no_did_number = substr((string) preg_replace('/[^0-9+]/', '', trim($data['no_did_number'] ?? '')), 0, 32);
            $allow_outbound_routing = isset($data['allow_outbound_routing']) ? intval($data['allow_outbound_routing']) : 0;
            $outbound_route_group = max(1, intval($data['outbound_route_group'] ?? 1));
            $max_channels = max(0, intval($data['max_channels'] ?? 0));
            $max_calls_per_minute = max(0, min(10000, intval($data['max_calls_per_minute'] ?? 0)));
            $cid_keep_last = max(0, min(20, intval($data['cid_keep_last'] ?? 0)));
            $cid_prepend = preg_replace('/[^0-9+]/', '', trim($data['cid_prepend'] ?? ''));
            $cid_prepend = substr($cid_prepend, 0, 30);

            $carrier_strip_prefix = preg_replace('/[^0-9+*#]/', '', trim($data['carrier_strip_prefix'] ?? ''));
            $carrier_strip_prefix = substr($carrier_strip_prefix, 0, 30);
            $carrier_add_prefix = preg_replace('/[^0-9+*#]/', '', trim($data['carrier_add_prefix'] ?? ''));
            $carrier_add_prefix = substr($carrier_add_prefix, 0, 30);
            $carrier_e164_country = (string)preg_replace('/[^0-9]/', '', (string)($data['carrier_e164_country'] ?? ''));
            if ($carrier_e164_country !== '' && DialplanRuleService::getDialPlan($carrier_e164_country) === null) {
                $carrier_e164_country = '';
            }

            $prack_100rel = in_array(strtolower(trim($data['prack_100rel'] ?? '')), ['yes', 'no', 'required'], true)
                ? strtolower(trim($data['prack_100rel']))
                : 'yes';

            $direct_media = in_array(strtolower(trim($data['direct_media'] ?? '')), ['no', 'yes', 'nonat'], true)
                ? strtolower(trim($data['direct_media']))
                : 'no';

            $timers = self::timersValue((string)($data['timers'] ?? ''));

            $send_pai = isset($data['send_pai']) ? intval($data['send_pai']) : 0;
            $send_rpid = isset($data['send_rpid']) ? intval($data['send_rpid']) : 0;
            $custom_pjsip_params = trim($data['custom_pjsip_params'] ?? '');
            // An unknown option here makes the trunk's endpoint disappear at the next Asterisk restart.
            $custom_check = AdvancedPjsipService::checkOptions($custom_pjsip_params, AdvancedPjsipService::optionNames('endpoint'));
            if ($custom_check['errors'] !== []) {
                throw new \Exception(implode(' ', $custom_check['errors']));
            }

            $custom_sip_headers = trim((string) ($data['custom_sip_headers'] ?? ''));
            $header_check = self::parseSipHeaders($custom_sip_headers);
            if ($header_check['errors'] !== []) {
                throw new \Exception(implode(' ', $header_check['errors']));
            }

            $trunk_data = [
                'id' => $trunk_id,
                'trunk_name' => $trunk_name,
                'title' => $title,
                'ip_address' => $ip_address,
                'port' => $port,
                'transport' => $transport,
                'codecs' => $codecs,
                't38_support' => $t38,
                't38_udptl_ec' => $t38_udptl_ec,
                't38_udptl_nat' => $t38_udptl_nat,
                't38_udptl_maxdatagram' => $t38_udptl_maxdatagram,
                'fax_detect' => $fax_detect,
                'fax_detect_timeout' => $fax_detect_timeout,
                'qualify_frequency' => $qualify,
                'send_caller_name' => $send_caller_name,
                'connection_mode' => $connection_mode,
                'auth_username' => $auth_username !== '' ? $auth_username : null,
                'auth_password' => $auth_password !== '' ? $auth_password : null,
                'registration_enabled' => $registration_enabled,
                'registration_expiration' => $registration_expiration,
                'registration_retry_interval' => $registration_retry_interval,
                'max_contacts' => $max_contacts,
                'outbound_proxy' => $outbound_proxy !== '' ? $outbound_proxy : null,
                'match_hosts' => $match_hosts !== '' ? $match_hosts : null,
                'from_user' => $from_user !== '' ? $from_user : null,
                'from_domain' => $from_domain !== '' ? $from_domain : null,
                'outbound_caller_id' => $outbound_caller_id !== '' ? $outbound_caller_id : null,
                'dtmf_mode' => $dtmf_mode,
                'context' => $context,
                'did_trim_digits' => $did_trim_digits,
                'no_did_number' => $no_did_number !== '' ? $no_did_number : null,
                'allow_outbound_routing' => $allow_outbound_routing,
                'outbound_route_group' => $outbound_route_group,
                'max_channels' => $max_channels,
                'max_calls_per_minute' => $max_calls_per_minute,
                'cid_keep_last' => $cid_keep_last,
                'cid_prepend' => $cid_prepend !== '' ? $cid_prepend : null,
                'carrier_strip_prefix' => $carrier_strip_prefix !== '' ? $carrier_strip_prefix : null,
                'carrier_add_prefix' => $carrier_add_prefix !== '' ? $carrier_add_prefix : null,
                'carrier_e164_country' => $carrier_e164_country !== '' ? $carrier_e164_country : null,
                'prack_100rel' => $prack_100rel,
                'direct_media' => $direct_media,
                'timers' => $timers,
                'rtp_symmetric' => $rtp_symmetric,
                'rewrite_contact' => $rewrite_contact,
                'force_rport' => $force_rport,
                'send_pai' => $send_pai,
                'send_rpid' => $send_rpid,
                'custom_pjsip_params' => $custom_pjsip_params !== '' ? $custom_pjsip_params : null,
                'custom_sip_headers' => $custom_sip_headers !== '' ? $custom_sip_headers : null,
                'is_active' => $is_active
            ];

            $is_new = ($trunk_id <= 0);
            $id = DBHelper::save('pbx_trunks', $trunk_data);
            if ($is_new) {
                getDB()->prepare("UPDATE pbx_trunks SET sort_order = (SELECT m FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS m FROM pbx_trunks) x) WHERE id = ?")->execute([$id]);
            }
            if ($renamed) {
                // PJSIP sections and outbound routes are keyed by the system name.
                SIPHelper::deleteSettings($old_name);
                self::renameInOutboundRoutes($old_name, $trunk_name);
            }
            SIPHelper::syncTrunkToSIP($trunk_data);

            markPendingSync('trunks', 'trunk', $trunk_name, "Trunk: {$title} ({$trunk_name})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            // What CONSUMES send_caller_name is the outbound route dialplan
            // (SyncDialplan.php::buildTrunkCallerIdLine -> extensions_outbound.conf),
            // not pjsip_trunks.conf — without marking this domain too, the
            // setting had no effect after "Apply" (found in the 2026-08-31 audit).
            markPendingSync('outbound_dialplan', 'trunk', $trunk_name, "Trunk caller ID: {$title} ({$trunk_name})", 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('inbound_dialplan', 'trunk', $trunk_name, "Trunk inbound route/DID: {$title} ({$trunk_name})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return sprintf(t('srv_trunk.saved'), $title, $trunk_name);
        });
    }

    private static function renameInOutboundRoutes(string $old, string $new): void {
        $db = getDB();
        $rows = $db->query("SELECT id, trunks_json FROM pbx_outbound_routes WHERE trunks_json IS NOT NULL AND trunks_json <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $db->prepare("UPDATE pbx_outbound_routes SET trunks_json = ? WHERE id = ?");
        foreach ($rows as $r) {
            $list = json_decode($r['trunks_json'], true);
            if (!is_array($list)) continue;
            $changed = false;
            foreach ($list as &$t) {
                if (($t['trunk_name'] ?? '') === $old) {
                    $t['trunk_name'] = $new;
                    $changed = true;
                }
            }
            unset($t);
            if ($changed) {
                $upd->execute([json_encode($list, JSON_UNESCAPED_UNICODE), $r['id']]);
            }
        }
    }

    public static function deleteTrunk($trunk_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($trunk_id) {
            $trunk_id = intval($trunk_id);
            $t_row = DBHelper::fetchOne("SELECT trunk_name, title FROM pbx_trunks WHERE id = ?", [$trunk_id]);
            $t_name = $t_row['trunk_name'] ?? null;
            if (!empty($t_name)) {
                SIPHelper::deleteSettings($t_name);
            }
            DBHelper::delete('pbx_trunks', 'id', $trunk_id);
            markPendingSync('trunks', 'trunk', $t_name ?: ('id_' . $trunk_id), "Trunk: " . ($t_row['title'] ?? $t_name ?? $trunk_id) . " (deleted)", 'delete', $_SESSION['user_id'] ?? null);
            return t('srv_trunk.deleted');
        });
    }
}
