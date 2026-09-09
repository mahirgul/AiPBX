<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Firewall (firewalld) Yönetim Servisi
 * `firewall-cmd`'yi sudo ile çalıştırır (asterisk kullanıcısı için
 * /etc/sudoers.d/web_portal'da NOPASSWD tanımlı, 2026-08-31).
 */
class FirewallService {

    /**
     * Bu portlar HİÇBİR ZAMAN kaldırılamaz — SSH (22), web paneli (80/443),
     * SIP sinyalleşme (5060) kapanırsa admin kendi erişimini/PBX'i kilitleyebilir.
     * Protokolden bağımsız (hem tcp hem udp) korunuyor.
     */
    const PROTECTED_PORTS = ['22', '80', '443', '5060'];

    /**
     * Ayrıca korunan aralıklar: RTP ses portları — kapatılırsa tüm çağrılarda
     * ses kesilir (2026-08-31 denetiminde eklendi).
     */
    const PROTECTED_RANGES = [[10000, 20000]];

    /**
     * PROTECTED_PORTS'un servis-adı karşılığı — bir rich-rule portu numarayla
     * değil firewalld servis adıyla da açabiliyor (`service name="ssh"`).
     */
    const PROTECTED_SERVICES = ['ssh', 'http', 'https', 'sip', 'sips'];

    /**
     * Verilen port ya da aralık ("22" veya "20-30") korumalı bir portu/aralığı
     * kapsıyor mu? Önceden sadece tam eşleşme kontrol ediliyordu; "20-30/tcp"
     * gibi bir aralık kuralı SSH'ı kapsadığı halde kaldırılabiliyordu.
     */
    private static function coversProtectedPort(string $port): bool {
        $parts = explode('-', $port, 2);
        $from = (int) $parts[0];
        $to = isset($parts[1]) ? (int) $parts[1] : $from;
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        foreach (self::PROTECTED_PORTS as $p) {
            if ((int) $p >= $from && (int) $p <= $to) return true;
        }
        foreach (self::PROTECTED_RANGES as [$rf, $rt]) {
            if ($from <= $rt && $to >= $rf) return true; // aralıklar kesişiyor
        }
        return false;
    }

    /**
     * Verilen port numarasının, aralığının veya rich-rule kuralının
     * korumalı olup olmadığını döner (UI'da kilit rozeti göstermek için).
     */
    public static function isProtected(string $portOrRule): bool {
        if (preg_match('/port="([0-9\-]+)"/', $portOrRule, $m)) {
            return self::coversProtectedPort($m[1]);
        }
        if (preg_match('/service name="([a-zA-Z0-9_-]+)"/', $portOrRule, $m)) {
            return in_array(strtolower($m[1]), self::PROTECTED_SERVICES, true);
        }
        return self::coversProtectedPort($portOrRule);
    }

    private static function run(string $args): array {
        $cmd = 'sudo /usr/bin/firewall-cmd ' . $args . ' 2>&1';
        exec($cmd, $out, $ret);
        return ['success' => $ret === 0, 'output' => implode("\n", $out)];
    }

    /**
     * Mevcut zone durumunu (aktif portlar + rich rule'lar) yapılandırılmış
     * bir diziye çevirir. `firewall-cmd --list-all`'ın metin çıktısını
     * (resmi/stabil bir API değil ama firewalld sürümleri arasında bu format
     * uzun süredir değişmiyor) satır satır ayrıştırıyor.
     */
    public static function getStatus(): array {
        // NOT: okuma komutları da sudo ile çalışmak ZORUNDA — firewall-cmd/
        // fail2ban-client root olmayan kullanıcıda "Authorization failed" /
        // "must be root" döndürüyor. Web uygulaması PHP-FPM altında 'asterisk'
        // kullanıcısı olarak çalıştığı için sudo'suz çağrılar canlıda BOŞ liste
        // üretiyordu (2026-08-31 denetiminde bulundu; CLI testleri root ile
        // koştuğu için gözden kaçmıştı).
        $zone_out = shell_exec('sudo /usr/bin/firewall-cmd --get-default-zone 2>&1');
        $zone = trim((string) $zone_out) ?: 'public';

        $list = shell_exec('sudo /usr/bin/firewall-cmd --list-all 2>&1');
        $list = (string) $list;

        $ports = [];
        if (preg_match('/^\s*ports:\s*(.*)$/m', $list, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $p) {
                if ($p === '') continue;
                [$num, $proto] = array_pad(explode('/', $p), 2, 'tcp');
                $ports[] = ['port' => $num, 'protocol' => $proto];
            }
        }

        $rich_rules = [];
        if (preg_match('/rich rules:\s*\n(.*)$/s', $list, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $rich_rules[] = $line;
            }
        }

        return [
            'zone' => $zone,
            'active' => (bool) firewalld_is_active(),
            'ports' => $ports,
            'rich_rules' => $rich_rules,
        ];
    }

    /**
     * Yeni bir port kuralı ekler. $sourceSubnet doluysa (ör. "192.0.2.0/24")
     * SADECE o kaynaktan gelen trafiği açan bir rich-rule üretir (daha güvenli,
     * kampüs-dışına kapalı); boşsa herkese açık genel bir --add-port kuralı.
     * Önce canlıya (reload olmadan anında etkili), sonra --permanent ile
     * uygulanır — ikisi de gerekiyor (biri anlık, biri kalıcılık için).
     */
    public static function addPortRule(string $port, string $protocol, string $sourceSubnet, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $port = preg_replace('/[^0-9\-]/', '', trim($port));
        $protocol = in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp';
        $sourceSubnet = trim($sourceSubnet);

        if ($port === '' || !preg_match('/^[0-9]+(-[0-9]+)?$/', $port)) {
            return ['success' => false, 'error' => 'Geçersiz port numarası!'];
        }
        // Tam formatı doğrula: önceden sadece "/" öncesi IP kontrol ediliyordu,
        // CIDR eki ve tırnak gibi karakterler serbest kalıyordu — ör.
        // `1.2.3.4/32" accept` doğrulamayı geçip rich-rule söz dizimini
        // bozabiliyordu (2026-08-31 denetiminde bulundu).
        if ($sourceSubnet !== '' && !preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', $sourceSubnet)) {
            return ['success' => false, 'error' => 'Geçersiz kaynak IP/subnet formatı! (ör. 192.0.2.0/24)'];
        }
        if ($sourceSubnet !== '' && !filter_var(explode('/', $sourceSubnet)[0], FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Geçersiz kaynak IP adresi!'];
        }

        if ($sourceSubnet !== '') {
            $rule = "rule family=\"ipv4\" source address=\"{$sourceSubnet}\" port port=\"{$port}\" protocol=\"{$protocol}\" accept";
            $arg = '--add-rich-rule=' . escapeshellarg($rule);
        } else {
            $arg = '--add-port=' . escapeshellarg("{$port}/{$protocol}");
        }

        $r1 = self::run($arg);
        $r2 = self::run($arg . ' --permanent');

        if (!$r1['success'] || !$r2['success']) {
            return ['success' => false, 'error' => "Firewall kuralı eklenemedi: " . trim($r1['output'] . ' ' . $r2['output'])];
        }
        writeAuditLog(null, 'firewall', $port, "Firewall kuralı eklendi: {$port}/{$protocol}" . ($sourceSubnet !== '' ? " (kaynak: {$sourceSubnet})" : ' (genel)'), 'create', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => "Kural eklendi: {$port}/{$protocol}" . ($sourceSubnet !== '' ? " ({$sourceSubnet})" : '')];
    }

    /**
     * Genel (rich-rule olmayan) bir --port girişini kaldırır. Korumalı
     * portlar (PROTECTED_PORTS) asla kaldırılamaz.
     */
    public static function removePortRule(string $port, string $protocol, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $port = preg_replace('/[^0-9\-]/', '', trim($port));
        $protocol = in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp';

        if (self::coversProtectedPort($port)) {
            return ['success' => false, 'error' => "Bu kural kritik bir portu (SSH/Web/SIP/RTP) kapsıyor — kaldırılamaz!"];
        }

        $arg = '--remove-port=' . escapeshellarg("{$port}/{$protocol}");
        $r1 = self::run($arg);
        $r2 = self::run($arg . ' --permanent');

        if (!$r1['success'] || !$r2['success']) {
            return ['success' => false, 'error' => "Firewall kuralı kaldırılamadı: " . trim($r1['output'] . ' ' . $r2['output'])];
        }
        writeAuditLog(null, 'firewall', $port, "Firewall kuralı kaldırıldı: {$port}/{$protocol}", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => "Kural kaldırıldı: {$port}/{$protocol}"];
    }

    /**
     * Bir rich-rule'u (tam metin eşleşmesiyle) kaldırır. Korumalı bir portu
     * (22/80/443/5060) kapsayan rich-rule'lar da BİLEREK engellenir.
     */
    public static function removeRichRule(string $rule, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $rule = trim($rule);
        if ($rule === '') {
            return ['success' => false, 'error' => 'Geçersiz kural.'];
        }
        // Rich-rule içindeki port değeri aralık da olabilir ("10000-20000") —
        // coversProtectedPort() ile aralık kesişimi de kontrol ediliyor
        // (2026-08-31 denetimi: önceden sadece tam eşleşme bakılıyordu, RTP
        // aralığını kaldıran kural engellenmiyordu).
        if (preg_match('/port="([0-9\-]+)"/', $rule, $m) && self::coversProtectedPort($m[1])) {
            return ['success' => false, 'error' => "Bu kural kritik bir portu (SSH/Web/SIP/RTP) kapsıyor — kaldırılamaz!"];
        }
        // Bir rich-rule portu SERVİS ADIYLA da belirtebilir
        // (`service name="ssh"`) — bu durumda yukarıdaki port kontrolü hiç
        // eşleşmez ve kritik bir erişim kuralı kaldırılabilirdi. Şu an canlıda
        // bu biçimde bir kural yok, ileriye dönük savunma (2026-08-31).
        if (preg_match('/service name="([a-zA-Z0-9_-]+)"/', $rule, $m)
            && in_array(strtolower($m[1]), self::PROTECTED_SERVICES, true)) {
            return ['success' => false, 'error' => "Bu kural kritik bir servisi ({$m[1]}) kapsıyor — kaldırılamaz!"];
        }

        $arg = '--remove-rich-rule=' . escapeshellarg($rule);
        $r1 = self::run($arg);
        $r2 = self::run($arg . ' --permanent');

        if (!$r1['success'] || !$r2['success']) {
            return ['success' => false, 'error' => "Kural kaldırılamadı: " . trim($r1['output'] . ' ' . $r2['output'])];
        }
        writeAuditLog(null, 'firewall', 'rich-rule', "Firewall rich-rule kaldırıldı: {$rule}", 'delete', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => 'Kural kaldırıldı.'];
    }
}

/**
 * firewalld servisi gerçekten aktif mi (salt-okunur, sudo gerektirmez).
 */
function firewalld_is_active(): bool {
    $out = shell_exec('systemctl is-active firewalld 2>&1');
    return trim((string) $out) === 'active';
}
