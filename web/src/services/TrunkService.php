<?php
/**
 * SIP Trunk Service
 */

class TrunkService {
    public static function saveTrunk($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $trunk_id = intval($data['trunk_id'] ?? 0);
            $trunk_name = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['trunk_name'] ?? ''));
            $title = trim($data['title'] ?? $trunk_name);
            $ip_address = trim($data['ip_address'] ?? '');
            $port = intval($data['port'] ?: 5060);
            $transport = trim($data['transport'] ?? 'udp');
            $codecs = trim($data['codecs'] ?? 'alaw,ulaw');
            $t38 = intval($data['t38_support'] ?? 1);
            $qualify = intval($data['qualify_frequency'] ?: 60);
            $send_caller_name = isset($data['send_caller_name']) ? intval($data['send_caller_name']) : 0;
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($trunk_name) || empty($ip_address)) {
                throw new \Exception("Trunk sistem ismi ve IP adresi zorunludur!");
            }
            // Format doğrulaması: geçersiz bir IP/hostname veya port aralık dışıysa
            // PJSIP config'e yazılıp reload sırasında sessizce hatalı/işlevsiz bir
            // trunk oluşturabiliyordu (2026-08-21 denetiminde bulundu).
            $is_valid_ip = filter_var($ip_address, FILTER_VALIDATE_IP) !== false;
            $is_valid_hostname = (bool)preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $ip_address);
            if (!$is_valid_ip && !$is_valid_hostname) {
                throw new \Exception("Geçersiz IP adresi veya sunucu adı: '{$ip_address}'");
            }
            if ($port < 1 || $port > 65535) {
                throw new \Exception("Port numarası 1-65535 aralığında olmalıdır!");
            }

            // Bağlantı modu: 'ip' (IP tabanlı identify, varsayılan) veya 'register'
            // (karşı santral bize kaydolur). Bilinmeyen değer 'ip'e düşer.
            $connection_mode = (($data['connection_mode'] ?? 'ip') === 'register') ? 'register' : 'ip';

            $auth_username = trim($data['auth_username'] ?? '');
            $auth_password = trim($data['auth_password'] ?? '');

            // Kayıt modunda kimlik bilgisi ZORUNLU: auth= satırı üretilemezse
            // Asterisk gelen REGISTER'ı PAROLASIZ kabul eder, yani aynı IP'ye
            // ulaşabilen herkes trunk'ı ele geçirebilir.
            if ($connection_mode === 'register' && ($auth_username === '' || $auth_password === '')) {
                throw new \Exception(
                    'Kayıt modunda kullanıcı adı ve parola zorunludur — '
                    . 'bunlar olmadan gelen kayıt kimlik doğrulaması yapılamaz.'
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
            $fax_detect_timeout = max(5, min(120, intval($data['fax_detect_timeout'] ?? 30 ?: 30)));

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
            $outbound_caller_id = preg_replace('/[^0-9+]/', '', trim($data['outbound_caller_id'] ?? ''));

            $dtmf_mode = in_array(strtolower(trim($data['dtmf_mode'] ?? '')), ['rfc4733', 'inband', 'info', 'auto'], true)
                ? strtolower(trim($data['dtmf_mode']))
                : 'rfc4733';

            $context = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['context'] ?? '')) ?: 'from-trunk-inbound';
            $did_trim_digits = max(0, min(30, intval($data['did_trim_digits'] ?? 0)));
            $allow_outbound_routing = isset($data['allow_outbound_routing']) ? intval($data['allow_outbound_routing']) : 0;
            $outbound_route_group = max(1, intval($data['outbound_route_group'] ?? 1));
            $max_channels = max(0, intval($data['max_channels'] ?? 0));

            $direct_media = in_array(strtolower(trim($data['direct_media'] ?? '')), ['no', 'yes', 'nonat'], true)
                ? strtolower(trim($data['direct_media']))
                : 'no';

            $timers = in_array(strtolower(trim($data['timers'] ?? '')), ['yes', 'no', 'always', 'never'], true)
                ? strtolower(trim($data['timers']))
                : 'yes';

            $send_pai = isset($data['send_pai']) ? intval($data['send_pai']) : 0;
            $send_rpid = isset($data['send_rpid']) ? intval($data['send_rpid']) : 0;
            $custom_pjsip_params = trim($data['custom_pjsip_params'] ?? '');

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
                'allow_outbound_routing' => $allow_outbound_routing,
                'outbound_route_group' => $outbound_route_group,
                'max_channels' => $max_channels,
                'direct_media' => $direct_media,
                'timers' => $timers,
                'rtp_symmetric' => $rtp_symmetric,
                'rewrite_contact' => $rewrite_contact,
                'force_rport' => $force_rport,
                'send_pai' => $send_pai,
                'send_rpid' => $send_rpid,
                'custom_pjsip_params' => $custom_pjsip_params !== '' ? $custom_pjsip_params : null,
                'is_active' => $is_active
            ];

            $is_new = ($trunk_id <= 0);
            $id = DBHelper::save('pbx_trunks', $trunk_data);
            SIPHelper::syncTrunkToSIP($trunk_data);

            markPendingSync('trunks', 'trunk', $trunk_name, "Trunk: {$title} ({$trunk_name})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            // send_caller_name'i TÜKETEN yer giden rota dialplan'ı
            // (SyncDialplan.php::buildTrunkCallerIdLine -> extensions_outbound.conf),
            // pjsip_trunks.conf değil — bu domain de işaretlenmezse "Uygula"
            // sonrası ayar etkisiz kalıyordu (2026-08-31 denetiminde bulundu).
            markPendingSync('outbound_dialplan', 'trunk', $trunk_name, "Trunk CID ayarı: {$title} ({$trunk_name})", 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('inbound_dialplan', 'trunk', $trunk_name, "Trunk gelen rota/DID ayarı: {$title} ({$trunk_name})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return "SIP Trunk '{$title}' ({$trunk_name}) kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
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
            markPendingSync('trunks', 'trunk', $t_name ?: ('id_' . $trunk_id), "Trunk: " . ($t_row['title'] ?? $t_name ?? $trunk_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            return "Trunk kaydı silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }
}
