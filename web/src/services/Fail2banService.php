<?php
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * fail2ban Yönetim Servisi
 * `fail2ban-client`'ı sudo ile çalıştırır (asterisk kullanıcısı için
 * /etc/sudoers.d/web_portal'da NOPASSWD tanımlı, 2026-08-31). Kalıcılık için
 * jail.local'e DOKUNULMAZ — ayrı bir override dosyası (OVERRIDE_FILE) kullanılır,
 * jail.d/ dizini zaten asterisk grubuna yazılabilir (sudo gerekmez, sadece
 * fail2ban-client komutları için sudo gerekiyor).
 */
class Fail2banService {

    /**
     * Bu IP'ler ignoreip listesinden asla kaldırılamaz — localhost her zaman
     * güvende kalmalı. "127.0.0.1/8" DEĞİL "127.0.0.0/8" — fail2ban-client
     * girilen değeri ağ adresine normalize edip öyle döndürüyor (canlıda
     * doğrulandı), UI'da gösterilen/kaldırılabilen değer bu, koruma da buna
     * göre eşleşmeli.
     */
    const PROTECTED_IGNOREIPS = ['127.0.0.0/8', '::1'];

    const OVERRIDE_FILE = '/etc/fail2ban/jail.d/99-ai-pbx.local';

    private static function run(string $args): array {
        $cmd = 'sudo /usr/bin/fail2ban-client ' . $args . ' 2>&1';
        exec($cmd, $out, $ret);
        return ['success' => $ret === 0, 'output' => implode("\n", $out)];
    }

    public static function listJails(): array {
        $out = shell_exec('sudo /usr/bin/fail2ban-client status 2>&1');
        if (!preg_match('/Jail list:\s*(.*)$/m', (string) $out, $m)) return [];
        $names = array_filter(array_map('trim', explode(',', $m[1])));
        return array_values($names);
    }

    /**
     * Bir jail için canlı durum (banlı IP'ler, sayaçlar) + ayarlar (bantime/
     * findtime/maxretry) — hepsi fail2ban-client'tan canlı okunuyor (dosya
     * ayrıştırma yerine), tek doğruluk kaynağı çalışan servis.
     */
    public static function jailDetail(string $jail): ?array {
        $jails = self::listJails();
        if (!in_array($jail, $jails, true)) return null;

        $status = (string) shell_exec('sudo /usr/bin/fail2ban-client status ' . escapeshellarg($jail) . ' 2>&1');
        $banned_ips = [];
        if (preg_match('/Banned IP list:\s*(.*)$/m', $status, $m)) {
            $banned_ips = array_values(array_filter(preg_split('/\s+/', trim($m[1]))));
        }
        $currently_banned = 0;
        if (preg_match('/Currently banned:\s*(\d+)/', $status, $m)) $currently_banned = (int) $m[1];
        $total_banned = 0;
        if (preg_match('/Total banned:\s*(\d+)/', $status, $m)) $total_banned = (int) $m[1];

        return [
            'name' => $jail,
            'currently_banned' => $currently_banned,
            'total_banned' => $total_banned,
            'banned_ips' => $banned_ips,
            'bantime' => (int) trim((string) shell_exec('sudo /usr/bin/fail2ban-client get ' . escapeshellarg($jail) . ' bantime 2>&1')),
            'findtime' => (int) trim((string) shell_exec('sudo /usr/bin/fail2ban-client get ' . escapeshellarg($jail) . ' findtime 2>&1')),
            'maxretry' => (int) trim((string) shell_exec('sudo /usr/bin/fail2ban-client get ' . escapeshellarg($jail) . ' maxretry 2>&1')),
        ];
    }

    public static function getIgnoreIps(): array {
        $jails = self::listJails();
        if (empty($jails)) return [];
        $out = (string) shell_exec('sudo /usr/bin/fail2ban-client get ' . escapeshellarg($jails[0]) . ' ignoreip 2>&1');
        $ips = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^\s*[|`]-\s*(.+)$/', $line, $m)) {
                $ips[] = trim($m[1]);
            }
        }
        return $ips;
    }

    /**
     * IP ya da CIDR bloğunu doğrular; hata varsa mesajı, geçerliyse null döner.
     *
     * Neden (2026-08-31 denetiminde bulundu): önceki kontrol sadece "/" ÖNCESİNİ
     * `filter_var()`'a veriyordu, yani CIDR eki hiç doğrulanmıyordu — "0.0.0.0/0"
     * girilirse TÜM İNTERNET beyaz listeye alınıp fail2ban fiilen devre dışı
     * kalıyordu. FirewallService tarafında zaten tam-format doğrulaması vardı,
     * fail2ban tarafı geride kalmıştı.
     *
     * En geniş kabul edilen blok /8 (ör. "10.0.0.0/8" gibi meşru özel ağlar
     * çalışmaya devam etsin diye); daha genişi kazara devre dışı bırakma riski.
     */
    private static function validateIpOrCidr(string $value): ?string {
        $parts = explode('/', $value, 2);
        $addr = $parts[0];
        $is_v6 = strpos($addr, ':') !== false;

        $flag = $is_v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (filter_var($addr, FILTER_VALIDATE_IP, $flag) === false) {
            return 'Geçersiz IP adresi!';
        }
        if (!isset($parts[1])) return null; // düz IP, ek yok

        if (!preg_match('/^\d{1,3}$/', $parts[1])) {
            return 'Geçersiz CIDR eki! (ör. 192.0.2.0/24)';
        }
        $prefix = (int) $parts[1];
        $max = $is_v6 ? 128 : 32;
        $min = $is_v6 ? 32 : 8;
        if ($prefix > $max) {
            return "Geçersiz CIDR eki! (en fazla /{$max})";
        }
        if ($prefix < $min) {
            return "Bu blok fazla geniş (/{$prefix}) — fail2ban'ı fiilen devre dışı bırakır. En geniş /{$min} kabul ediliyor.";
        }
        return null;
    }

    public static function unbanIp(string $jail, string $ip, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $jail = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($jail));
        $ip = trim($ip);
        if (!in_array($jail, self::listJails(), true) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Geçersiz jail veya IP adresi.'];
        }
        $res = self::run('set ' . escapeshellarg($jail) . ' unbanip ' . escapeshellarg($ip));
        if (!$res['success']) {
            return ['success' => false, 'error' => 'IP ban kaldırılamadı: ' . trim($res['output'])];
        }
        writeAuditLog(null, 'fail2ban', $jail, "IP ban kaldırıldı ({$jail}): {$ip}", 'unban', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => "{$ip} adresinin banı kaldırıldı."];
    }

    /**
     * Bir jail'in bantime/findtime/maxretry değerlerini hem canlıya (anında
     * etkili, fail2ban-client set) hem override dosyasına (fail2ban yeniden
     * başlarsa kalıcı olsun diye) yazar.
     */
    public static function updateJailConfig(string $jail, int $bantime, int $findtime, int $maxretry, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $jail = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($jail));
        if (!in_array($jail, self::listJails(), true)) {
            return ['success' => false, 'error' => 'Geçersiz jail.'];
        }
        if ($bantime < 60 || $findtime < 60 || $maxretry < 1) {
            return ['success' => false, 'error' => 'Değerler mantıksız (bantime/findtime en az 60sn, maxretry en az 1 olmalı).'];
        }

        $r1 = self::run('set ' . escapeshellarg($jail) . ' bantime ' . $bantime);
        $r2 = self::run('set ' . escapeshellarg($jail) . ' findtime ' . $findtime);
        $r3 = self::run('set ' . escapeshellarg($jail) . ' maxretry ' . $maxretry);
        if (!$r1['success'] || !$r2['success'] || !$r3['success']) {
            return ['success' => false, 'error' => 'Ayarlar canlıya uygulanamadı: ' . trim($r1['output'] . ' ' . $r2['output'] . ' ' . $r3['output'])];
        }

        $state = self::readOverrideState();
        $state['jails'][$jail] = ['bantime' => $bantime, 'findtime' => $findtime, 'maxretry' => $maxretry];
        $persist_err = self::writeOverrideState($state);

        writeAuditLog(null, 'fail2ban', $jail, "Jail ayarları güncellendi ({$jail}): bantime={$bantime} findtime={$findtime} maxretry={$maxretry}", 'update', $_SESSION['user_id'] ?? null);
        if ($persist_err !== null) return self::persistFailure("{$jail} ayarları", $persist_err);
        return ['success' => true, 'message' => "{$jail} ayarları güncellendi."];
    }

    public static function addIgnoreIp(string $ip, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $ip = trim($ip);
        if (($err = self::validateIpOrCidr($ip)) !== null) {
            return ['success' => false, 'error' => $err];
        }
        foreach (self::listJails() as $jail) {
            self::run('set ' . escapeshellarg($jail) . ' addignoreip ' . escapeshellarg($ip));
        }
        $state = self::readOverrideState();
        if (!in_array($ip, $state['ignoreip'], true)) $state['ignoreip'][] = $ip;
        $persist_err = self::writeOverrideState($state);

        writeAuditLog(null, 'fail2ban', 'ignoreip', "IP beyaz listeye eklendi: {$ip}", 'create', $_SESSION['user_id'] ?? null);
        if ($persist_err !== null) return self::persistFailure("{$ip} beyaz listeye eklendi", $persist_err);
        return ['success' => true, 'message' => "{$ip} beyaz listeye eklendi."];
    }

    public static function removeIgnoreIp(string $ip, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $ip = trim($ip);
        if (in_array($ip, self::PROTECTED_IGNOREIPS, true)) {
            return ['success' => false, 'error' => "{$ip} (localhost) beyaz listeden asla kaldırılamaz!"];
        }
        foreach (self::listJails() as $jail) {
            self::run('set ' . escapeshellarg($jail) . ' delignoreip ' . escapeshellarg($ip));
        }
        $state = self::readOverrideState();
        $state['ignoreip'] = array_values(array_diff($state['ignoreip'], [$ip]));
        $persist_err = self::writeOverrideState($state);

        writeAuditLog(null, 'fail2ban', 'ignoreip', "IP beyaz listeden kaldırıldı: {$ip}", 'delete', $_SESSION['user_id'] ?? null);
        if ($persist_err !== null) return self::persistFailure("{$ip} beyaz listeden kaldırıldı", $persist_err);
        return ['success' => true, 'message' => "{$ip} beyaz listeden kaldırıldı."];
    }

    /**
     * Değişiklik CANLIYA uygulandı ama kalıcı dosyaya yazılamadı — bu yarı
     * başarılı durumu asla "başarılı" diye gösterme, admin'in fail2ban yeniden
     * başladığında değişikliği kaybedeceğini bilmesi gerekiyor. Detaylı sebep
     * audit log'a da yazılıyor (bkz. projedeki reload/rollback hata deseni:
     * entity_label sadece adı değil hata metnini de taşır).
     */
    private static function persistFailure(string $what, string $reason): array {
        writeAuditLog(null, 'fail2ban', 'persist_failed', mb_substr("KALICI KAYIT BAŞARISIZ ({$what}): {$reason}", 0, 255), 'error', $_SESSION['user_id'] ?? null);
        return [
            'success' => false,
            'error' => "{$what} — CANLIYA uygulandı, ANCAK kalıcı olarak kaydedilemedi ({$reason}). "
                     . 'fail2ban yeniden başlatılırsa bu değişiklik KAYBOLUR.',
        ];
    }

    private static function readOverrideState(): array {
        $state = ['ignoreip' => self::getIgnoreIps(), 'jails' => []];
        if (is_file(self::OVERRIDE_FILE)) {
            $parsed = @parse_ini_file(self::OVERRIDE_FILE, true, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                foreach ($parsed as $section => $kv) {
                    if ($section === 'DEFAULT') continue;
                    $state['jails'][$section] = [
                        'bantime' => (int) ($kv['bantime'] ?? 0),
                        'findtime' => (int) ($kv['findtime'] ?? 0),
                        'maxretry' => (int) ($kv['maxretry'] ?? 0),
                    ];
                }
            }
        }
        return $state;
    }

    /**
     * Override dosyasını yazar. Başarılıysa null, başarısızsa SEBEBİ döner —
     * çağıranlar bunu kullanıcıya göstermek ZORUNDA.
     *
     * Neden dönüş değeri var (2026-08-31 denetiminde bulundu): önceki hâli
     * `file_put_contents()`'ın sonucunu hiç kontrol etmiyordu ve dosya
     * root:root 644 kalmıştı (ilk kez root ile koşan bir CLI testi oluşturmuş)
     * — PHP-FPM 'asterisk' kullanıcısı olarak çalıştığı için yazım SESSİZCE
     * başarısız oluyordu: jail ayarı/beyaz liste değişikliği canlıya
     * uygulanıyor ama kalıcı olmuyordu, admin'e ise "başarılı" gösteriliyordu.
     * fail2ban yeniden başlayınca değişiklik kaybolurdu.
     */
    private static function writeOverrideState(array $state): ?string {
        // GÜVENLİK KİLİDİ (2026-08-31 denetiminde bulundu): ignoreip listesi boş
        // gelirse (ör. fail2ban-client okuması bir sebeple başarısız olduysa) bu
        // dosya `[DEFAULT] ignoreip =` (BOŞ) yazıp jail.local'daki gerçek beyaz
        // listeyi ezerdi — fail2ban yeniden başlayınca localhost ve admin IP'si
        // korumasız kalırdı. Boş listeyle ASLA yazma; korumalı IP'ler her
        // durumda listede olmaya zorlanır.
        $ignoreip = array_values(array_unique(array_merge(self::PROTECTED_IGNOREIPS, array_filter($state['ignoreip']))));
        if (empty($state['ignoreip'])) {
            // okuma başarısız → mevcut dosyaya dokunma (sessizce bozma)
            return 'mevcut beyaz liste okunamadı, dosya güvenlik gereği hiç değiştirilmedi';
        }

        $lines = [];
        $lines[] = '# AI PBX panelinden yönetiliyor (otomatik üretilir, elle düzenlemeyin)';
        $lines[] = '[DEFAULT]';
        $lines[] = 'ignoreip = ' . implode(' ', $ignoreip);
        $lines[] = '';
        foreach ($state['jails'] as $jail => $cfg) {
            $lines[] = "[{$jail}]";
            $lines[] = 'bantime = ' . intval($cfg['bantime']);
            $lines[] = 'findtime = ' . intval($cfg['findtime']);
            $lines[] = 'maxretry = ' . intval($cfg['maxretry']);
            $lines[] = '';
        }

        // Doğrudan file_put_contents() DEĞİL: FileHelper geçici dosya + rename()
        // kullanıyor. jail.d/ dizini asterisk grubuna yazılabilir olduğu için bu
        // yöntem, hedef dosya root'a ait olsa BİLE çalışır (rename dosyanın
        // değil dizinin iznine bakar) — sahiplik kaynaklı sessiz başarısızlık
        // bir daha oluşamaz.
        if (!FileHelper::writeFile(self::OVERRIDE_FILE, implode("\n", $lines) . "\n")) {
            return self::OVERRIDE_FILE . ' yazılamadı (dosya izni/sahipliği?)';
        }
        return null;
    }
}

/**
 * fail2ban servisi gerçekten aktif mi (salt-okunur, sudo gerektirmez).
 */
function fail2ban_is_active(): bool {
    $out = shell_exec('systemctl is-active fail2ban 2>&1');
    return trim((string) $out) === 'active';
}
