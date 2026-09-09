<?php
/**
 * Flexible Key-Value SIP Settings Helper Module for PJSIP Endpoints & Trunks
 * Interacts with MariaDB `sip` table (`id`, `keyword`, `data`, `flags`)
 */

require_once __DIR__ . '/../config.php';

class SIPHelper {

    /**
     * Get raw settings array for a specific endpoint or trunk ID
     */
    public static function getSettings($id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT keyword, data, flags FROM sip WHERE id = ? ORDER BY flags ASC, keyword ASC");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get settings as key-value associative map ['keyword' => 'data']
     */
    public static function getSettingsMap($id) {
        $rows = self::getSettings($id);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['keyword']] = $r['data'];
        }
        return $map;
    }

    /**
     * Bulk upsert multiple key-value pairs
     */
    public static function setSettings($id, array $pairs, $flags = 0) {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO sip (id, keyword, data, flags) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), flags = VALUES(flags)");
        foreach ($pairs as $key => $val) {
            if ($val === null) continue;
            $stmt->execute([$id, (string)$key, (string)$val, (int)$flags]);
        }
        return true;
    }

    /**
     * Delete all settings for an ID
     */
    public static function deleteSettings($id) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM sip WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Belirli anahtarları siler (tümünü değil).
     *
     * Neden gerekli: setSettings() yalnızca VERİLEN anahtarları yazıyor, artık
     * gönderilmeyenleri silmiyor. Bu yüzden panelden boşaltılan bir alanın eski
     * değeri `sip` tablosunda kalıyor ve SyncTrunks orayı pbx_trunks'tan ÖNCE
     * okuduğu için alan bir daha ASLA temizlenemiyordu — "set edilir ama geri
     * alınamaz" (2026-09-01'de match_hosts'ta yaşandı: WebRTC'yi kıran
     * match=127.0.0.1 panelden kaldırılamadı).
     */
    public static function deleteKeys($id, array $keys) {
        if (empty($keys)) return true;
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM sip WHERE id = ? AND keyword = ?");
        foreach ($keys as $k) {
            $stmt->execute([$id, (string) $k]);
        }
        return true;
    }

    /**
     * Generate cryptographically secure random SIP authentication password
     */
    public static function generateStrongSIPPassword($length = 16) {
        $bytes = random_bytes($length);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*_-';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[ord($bytes[$i]) % ($max + 1)];
        }
        return $password;
    }

    /**
     * Populate standard PJSIP default parameters for an Extension
     */
    public static function syncExtensionToSIP($ext, $fullName = '', $secret = '', $authDigest = 1) {
        if (empty($ext)) return false;

        $ext_str = (string)$ext;
        $clean_name = toCleanAscii($fullName ?: ('Extension ' . $ext_str));
        $pass = !empty($secret) ? $secret : self::generateStrongSIPPassword();

        $defaults = [
            'account' => $ext_str,
            'secret' => $pass,
            'callerid' => "\"{$clean_name}\" <{$ext_str}>",
            'context' => 'from-internal-pbx',
            'max_contacts' => '10',
            'auth_digest' => $authDigest ? 'yes' : 'no'
        ];

        return self::setSettings($ext_str, $defaults);
    }

    /**
     * Populate standard PJSIP default parameters for a Trunk
     */
    public static function syncTrunkToSIP(array $t) {
        $t_name = trim($t['trunk_name'] ?? '');
        if (empty($t_name)) return false;

        $ip = trim($t['ip_address'] ?? '');
        $port = intval($t['port'] ?? 5060) ?: 5060;
        $raw_tr = strtolower(trim($t['transport'] ?? 'udp'));
        if (strpos($raw_tr, 'transport-') === 0) {
            $transport = $raw_tr;
        } elseif (in_array($raw_tr, ['udp', 'tcp', 'tls', 'wss', 'ws'])) {
            $transport = 'transport-' . $raw_tr;
        } else {
            $transport = 'transport-udp';
        }
        $codecs = !empty($t['codecs']) ? $t['codecs'] : 'alaw,ulaw';
        $qualify = intval($t['qualify_frequency'] ?? 60) ?: 60;
        $title = toCleanAscii($t['title'] ?? $t_name);

        $context = !empty($t['context']) ? trim($t['context']) : 'from-trunk-inbound';
        $dtmf_mode = !empty($t['dtmf_mode']) ? trim($t['dtmf_mode']) : 'rfc4733';
        $direct_media = !empty($t['direct_media']) ? trim($t['direct_media']) : 'no';
        $timers = !empty($t['timers']) ? trim($t['timers']) : 'yes';
        $rtp_symmetric = !empty($t['rtp_symmetric']) ? trim($t['rtp_symmetric']) : 'yes';
        $force_rport = !empty($t['force_rport']) ? trim($t['force_rport']) : 'yes';
        $rewrite_contact = !empty($t['rewrite_contact']) ? trim($t['rewrite_contact']) : 'yes';
        $max_contacts = intval($t['max_contacts'] ?? 1) ?: 1;

        $defaults = [
            'type' => 'trunk',
            'title' => $title,
            'ip_address' => $ip,
            'port' => (string)$port,
            'context' => $context,
            'transport' => $transport,
            'disallow' => 'all',
            'allow' => $codecs,
            'direct_media' => $direct_media,
            'rtp_symmetric' => $rtp_symmetric,
            'force_rport' => $force_rport,
            'rewrite_contact' => $rewrite_contact,
            'qualify_frequency' => (string)$qualify,
            'max_contacts' => (string)$max_contacts,
            'dtmf_mode' => $dtmf_mode,
            'timers' => $timers
        ];

        if (!empty($t['outbound_proxy'])) {
            $defaults['outbound_proxy'] = trim($t['outbound_proxy']);
        }
        if (!empty($t['match_hosts'])) {
            $defaults['match_hosts'] = trim($t['match_hosts']);
        }

        if (!empty($t['t38_support'])) {
            $t38_ec = !empty($t['t38_udptl_ec']) ? trim($t['t38_udptl_ec']) : 'redundancy';
            $t38_nat = !empty($t['t38_udptl_nat']) ? trim($t['t38_udptl_nat']) : 'yes';
            $t38_max = intval($t['t38_udptl_maxdatagram'] ?? 400) ?: 400;
            $defaults['t38_udptl'] = 'yes';
            $defaults['t38_udptl_ec'] = $t38_ec;
            $defaults['t38_udptl_nat'] = $t38_nat;
            $defaults['t38_udptl_maxdatagram'] = (string)$t38_max;
        } else {
            $defaults['t38_udptl'] = 'no';
        }

        if (isset($t['fax_detect']) && (int)$t['fax_detect'] === 1) {
            $f_timeout = intval($t['fax_detect_timeout'] ?? 30) ?: 30;
            $defaults['fax_detect'] = 'yes';
            $defaults['fax_detect_timeout'] = (string)$f_timeout;
        } else {
            $defaults['fax_detect'] = 'no';
        }

        if (!empty($t['from_user'])) {
            $defaults['from_user'] = trim($t['from_user']);
        }
        if (!empty($t['from_domain'])) {
            $defaults['from_domain'] = trim($t['from_domain']);
        }
        if (!empty($t['outbound_caller_id'])) {
            $defaults['outbound_caller_id'] = trim($t['outbound_caller_id']);
        }
        if (!empty($t['send_pai'])) {
            $defaults['send_pai'] = 'yes';
        }
        if (!empty($t['send_rpid'])) {
            $defaults['send_rpid'] = 'yes';
        }
        if (!empty($t['auth_username'])) {
            $defaults['auth_username'] = trim($t['auth_username']);
        }
        if (!empty($t['auth_password'])) {
            $defaults['auth_password'] = trim($t['auth_password']);
        }
        if (!empty($t['registration_enabled'])) {
            $defaults['registration_enabled'] = '1';
            $defaults['registration_expiration'] = (string)(intval($t['registration_expiration'] ?? 3600) ?: 3600);
            $defaults['registration_retry_interval'] = (string)(intval($t['registration_retry_interval'] ?? 60) ?: 60);
        }

        // Panelden BOŞALTILAN opsiyonel alanların bayat satırlarını temizle.
        // Bu liste, yukarıda `if (!empty($t[...]))` ile KOŞULLU yazılan her
        // anahtarı içerir; koşul sağlanmadığında anahtar $defaults'a hiç girmez
        // ve eski satır silinmezse sonsuza kadar pbx_trunks'ı gölgeler.
        // YENİ bir koşullu alan eklenirse BURAYA DA eklenmeli — aksi halde o
        // alan da "set edilir ama geri alınamaz" hale gelir.
        $kosullu_alanlar = [
            'match_hosts', 'outbound_proxy',
            't38_udptl_maxdatagram', 'fax_detect', 'fax_detect_timeout',
            'from_user', 'from_domain', 'outbound_caller_id',
            'send_pai', 'send_rpid',
            'auth_username', 'auth_password',
            'registration_enabled', 'registration_expiration', 'registration_retry_interval',
        ];
        self::deleteKeys($t_name, array_values(array_diff($kosullu_alanlar, array_keys($defaults))));

        return self::setSettings($t_name, $defaults);
    }
}
