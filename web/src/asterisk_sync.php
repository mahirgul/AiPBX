<?php
/**
 * Master Asterisk Sync Orchestrator (/etc/asterisk/pbx/)
 * Facade linking domain-driven sync modules in src/sync/
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/file_helper.php';
require_once __DIR__ . '/asterisk_helper.php';

/**
 * Helper to safely write file with ownership
 */
function writePBXConf($filename, $content) {
    $file_path = ASTERISK_PBX_DIR . '/' . $filename;
    FileHelper::writeFile($file_path, $content, 'asterisk', 'asterisk', 0644);
    return $file_path;
}

/**
 * Config yaz + reload et + BAŞARISIZ OLURSA OTOMATİK GERİ AL (rollback).
 * 2026-08-24/25: dünkü "reload başarısızlığı hiç yakalanmıyordu" düzeltmesinin
 * doğal devamı — artık bir reload'un GERÇEKTEN başarısız olduğunu biliyoruz,
 * bu fonksiyon o bilgiyi kullanıp Asterisk'i EN SON ÇALIŞAN sürümde tutuyor.
 *
 * Akış: yeni içerik yazılmadan ÖNCE mevcut dosyanın bir kopyası ".prev" olarak
 * saklanır. $reloadFn() (genelde bir veya daha fazla AsteriskHelper::
 * assertReloadsOk() çağrısı) başarısız olursa (Exception fırlatırsa):
 *   - Önceki bir sürüm VARSA: o sürüm geri yazılır ve AYNI reload TEKRAR
 *     denenir. Bu da başarılı olursa "geri alındı" diye net bir hata
 *     fırlatılır (admin'e "bozuk config reddedildi, eskisi hâlâ çalışıyor"
 *     mesajı gider). Bu da başarısız olursa (çok nadir — Asterisk'in kendisi
 *     başka bir sebeple sorunlu olabilir) "elle müdahale gerekiyor" diye
 *     AYRI bir hata fırlatılır — hiçbir durumda sessizce "başarılı" denmez.
 *   - Önceki bir sürüm YOKSA (bu domain için ilk kayıt): geri dönecek bir şey
 *     yok, yeni (muhtemelen hatalı) dosya olduğu gibi kalır, sadece orijinal
 *     hata fırlatılır.
 *
 * $reloadFn HER ZAMAN çağrılır (yazma başarısız/başarılı fark etmez) — bu
 * fonksiyon dosya yazımını YAPMAZ, sadece $writeFn ile SARAR; syncXxx()
 * fonksiyonları writePBXConf() çağrısını ve reload çağrısını buraya taşır.
 */
function writeConfWithRollback($filename, $content, callable $reloadFn, $context) {
    $file_path = ASTERISK_PBX_DIR . '/' . $filename;
    $backup_path = $file_path . '.prev';
    $had_previous = is_file($file_path);
    $previous_content = $had_previous ? @file_get_contents($file_path) : null;

    writePBXConf($filename, $content);

    try {
        $reloadFn();
        // Başarılı — bir sonraki başarısızlıkta geri dönülecek "bilinen iyi"
        // sürüm olarak bu içeriği sakla (mevcut dosyanın kendisi zaten bu).
        if ($previous_content !== null) {
            @file_put_contents($backup_path, $previous_content);
            @chown($backup_path, 'asterisk');
            @chgrp($backup_path, 'asterisk');
        }
        return true;
    } catch (\Exception $e) {
        if ($previous_content === null) {
            throw new \Exception("{$context} başarısız oldu (bu ayar için önceki bir sürüm olmadığından otomatik geri alma yapılamadı): " . $e->getMessage());
        }
        writePBXConf($filename, $previous_content);
        try {
            $reloadFn();
        } catch (\Exception $e2) {
            throw new \Exception("{$context} başarısız oldu VE otomatik geri alma da başarısız oldu — ELLE MÜDAHALE GEREKİYOR. İlk hata: " . $e->getMessage() . " | Geri alma hatası: " . $e2->getMessage());
        }
        throw new \Exception("{$context} başarısız oldu, ÖNCEKİ ÇALIŞAN AYARLARA OTOMATİK OLARAK GERİ ALINDI (değişikliğiniz uygulanmadı, sistem önceki hâliyle çalışmaya devam ediyor). Asterisk'in verdiği hata: " . $e->getMessage());
    }
}

/**
 * "DB oku → TÜM ilgili .conf dosyasını yeniden üret → yaz → reload" senkron
 * fonksiyonlarının etrafına konsolidasyon-domeni bazlı bir dosya kilidi (flock)
 * ekler. FileHelper::writeFile() tek bir dosyanın YAZIMINI atomik yapıyor ama
 * bu, iki eşzamanlı isteğin AYNI syncAllXxx() fonksiyonunu üst üste bindirmesini
 * (A eski DB anlık görüntüsünü B'den SONRA yazıp B'nin değişikliğini üretilen
 * dosyada geçici olarak kaybetmesini) engellemiyordu — 2026-08-23 mimari
 * incelemesinde bulunan bir race condition. Kilit domeni bazlı (extensions,
 * queues, ivrs, ... ayrı ayrı) — ilgisiz alanlardaki eşzamanlı düzenlemeler
 * birbirini beklemez, sadece AYNI hedef dosyaya yazan çağrılar serileşir.
 * Kilit dosyası açılamazsa (izin/disk sorunu) SESSİZCE kilitsiz devam eder —
 * senkron işlemini asla tamamen engellemez, en kötü ihtimalle eski (kilitsiz)
 * davranışa döner.
 */
function withSyncLock($lock_name, callable $fn) {
    $lock_dir = sys_get_temp_dir() . '/aipbx_sync_locks';
    if (!is_dir($lock_dir)) {
        @mkdir($lock_dir, 0700, true);
    }
    $lock_file = $lock_dir . '/' . preg_replace('/[^a-z0-9_]/', '', $lock_name) . '.lock';
    $lock_fp = @fopen($lock_file, 'c');
    if ($lock_fp === false) {
        return $fn();
    }
    flock($lock_fp, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($lock_fp, LOCK_UN);
        fclose($lock_fp);
    }
}

/**
 * Ertelenmiş reload sistemi: domain -> gerçek syncXxx() fonksiyon adı eşlemesi.
 * syncEverything()'in çağırdığı 11 domainle birebir aynı liste — bu bilinçli,
 * "hangi domain'ler var" tanımının tek bir yerde durması için.
 */
const PENDING_SYNC_DOMAIN_MAP = [
    'extensions'        => 'syncAllExtensions',
    'trunks'            => 'syncAllTrunks',
    'queues'            => 'syncAllQueues',
    'ivrs'              => 'syncAllIVRs',
    'time_conditions'   => 'syncAllTimeConditions',
    'inbound_dialplan'  => 'syncInboundDialplan',
    'outbound_dialplan' => 'syncOutboundDialplan',
    'general_dialplan'  => 'syncGeneralDialplan',
    'moh'               => 'syncAsteriskMOH',
    'featurecodes'      => 'syncFeatureCodes',
    'transports'        => 'syncTransports',
    'rtp'               => 'syncRtpSettings',
    'udptl'             => 'syncUdptlSettings',
    'internal_numbers'  => 'syncInternalNumbers',
];

/**
 * Bir kaydın Asterisk'e YANSITILMASI gerektiğini işaretler — gerçek
 * regen+reload'ı HEMEN çalıştırmaz, /pending-sync sayfasından admin
 * "Gönder"e basana kadar bekletir (2026-08-24, kullanıcı isteği: "admin
 * birden çok değişiklik yapmak isteyebilir, hepsini tek seferde uygulasın").
 * $domain PENDING_SYNC_DOMAIN_MAP'te olmalı. Aynı (domain, entity_type,
 * entity_id) Gönder'den önce tekrar düzenlenirse satır GÜNCELLENİR
 * (tekilleşir) — aynı kayıt için liste şişmez, son hâli gösterilir.
 *
 * Kapsam DIŞI (bilerek): telefon feature code'ları (feature_code_action.php,
 * kendi syncEverything() çağrısını KORUYOR — kullanıcı *78 çevirdiğinde
 * anında etkili olmalı, admin onayı beklemez) ve ajan kuyruk giriş/mola
 * (canlı AMI komutları, hiç config dosyası içermiyor, bu sistemin kapsamına
 * hiç girmiyor).
 */
function markPendingSync($domain, $entity_type, $entity_id, $entity_label, $action = 'update', $user_id = null) {
    if (!array_key_exists($domain, PENDING_SYNC_DOMAIN_MAP)) {
        throw new \InvalidArgumentException("Bilinmeyen pending-sync domain'i: {$domain}");
    }
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO sys_pending_sync (domain, entity_type, entity_id, entity_label, action, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE entity_label = VALUES(entity_label), action = VALUES(action), changed_by = VALUES(changed_by), changed_at = NOW()"
    );
    $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id]);

    // sys_pending_sync SATIRLARI Gönder'den sonra SİLİNİR — kalıcı bir "kim
    // ne zaman ne yaptı" kaydı ayrı, INSERT-only sys_audit_log'a düşer.
    writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id);
}

/**
 * Kalıcı denetim kaydı — hiçbir satırı asla silinmez/güncellenmez (2026-08-24).
 * markPendingSync() (bir kayıt değiştiğinde) ve applyPendingSync() (Gönder'e
 * basıldığında, domain başına) tarafından çağrılır. $user_id null olabilir
 * (ör. CLI'dan tetiklenen bir işlem) — username o anki DB anlık görüntüsünden
 * denormalize edilip saklanır, kullanıcı sonradan silinse bile kayıt okunabilir kalır.
 */
function writeAuditLog($domain, $entity_type, $entity_id, $entity_label, $action, $user_id = null) {
    try {
        $db = getDB();
        $username = null;
        if ($user_id) {
            $username = $db->prepare("SELECT full_name FROM sys_users WHERE id = ?");
            $username->execute([$user_id]);
            $username = $username->fetchColumn() ?: null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare(
            "INSERT INTO sys_audit_log (domain, entity_type, entity_id, entity_label, action, user_id, username, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$domain, $entity_type, (string)$entity_id, $entity_label, $action, $user_id, $username, $ip]);
    } catch (\Exception $e) {
        // Denetim kaydı yazılamaması asıl işlemi (ayar kaydetme/uygulama)
        // ASLA engellememeli — sadece sessizce atlanır.
    }
}

/**
 * Sidebar rozeti için hafif bir sayaç.
 */
function getPendingSyncCount() {
    try {
        return (int) getDB()->query("SELECT COUNT(*) FROM sys_pending_sync")->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * /pending-sync sayfası için: tüm bekleyen satırlar, domain bazlı gruplanmış.
 */
function getPendingSyncList() {
    $db = getDB();
    $rows = $db->query(
        "SELECT ps.*, u.full_name AS changed_by_name
         FROM sys_pending_sync ps
         LEFT JOIN sys_users u ON u.id = ps.changed_by
         ORDER BY ps.domain ASC, ps.changed_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['domain']][] = $r;
    }
    return $grouped;
}

/**
 * "Gönder" butonuna basılınca çalışır: bekleyen HER DOMAIN için gerçek
 * syncXxx() fonksiyonunu bir kez çağırır (o domain'de kaç kayıt değiştiyse
 * değişsin, domain başına TEK regen — syncAllQueues() zaten tüm kuyrukları
 * tek seferde üretiyor, withSyncLock() kilidi burada da geçerli). Başarılı
 * domain'in satırları silinir; bir domain hata verirse o domain'in satırları
 * KALIR (bir sonraki Gönder denemesinde tekrar denenir), diğer domainleri
 * ETKİLEMEZ. Dönüş: ['domain' => ['success' => bool, 'error' => string|null]].
 */
function applyPendingSync($user_id = null) {
    $db = getDB();
    $domains = $db->query("SELECT DISTINCT domain FROM sys_pending_sync")->fetchAll(PDO::FETCH_COLUMN);

    $results = [];
    foreach ($domains as $domain) {
        $fn = PENDING_SYNC_DOMAIN_MAP[$domain] ?? null;
        if (!$fn || !function_exists($fn)) {
            $results[$domain] = ['success' => false, 'error' => "Bilinmeyen domain: {$domain}"];
            continue;
        }
        try {
            $fn();
            $del = $db->prepare("DELETE FROM sys_pending_sync WHERE domain = ?");
            $del->execute([$domain]);
            $results[$domain] = ['success' => true, 'error' => null];
            writeAuditLog($domain, 'sync_apply', $domain, $domain, 'apply', $user_id);
        } catch (\Throwable $e) {
            $results[$domain] = ['success' => false, 'error' => $e->getMessage()];
            // 2026-08-25: önceden buraya sadece domain adı ("queues") yazılıyordu —
            // rollback/reload hatasının GERÇEK metni (writeConfWithRollback()'in
            // "OTOMATİK GERİ ALINDI"/"ELLE MÜDAHALE GEREKİYOR" mesajları dahil)
            // sadece o anki HTTP yanıtında görünüyordu, kalıcı kayıtta kaybolurdu.
            // entity_label varchar(255) — ham Asterisk çıktısı bunu aşabilir,
            // güvenli şekilde kırpılıyor.
            $label = "{$domain}: " . mb_substr($e->getMessage(), 0, 230);
            writeAuditLog($domain, 'sync_apply', $domain, $label, 'apply_failed', $user_id);
        }
    }
    return $results;
}

// Require Domain Sync Modules
require_once __DIR__ . '/sync/SyncTransports.php';
// SyncDialplan.php 2026-08-31'de dört ayrı alana bölündü (tek dosyada 516 satırdı).
// DialplanBuilders ÖNCE gelmeli — diğerleri onun ürettiği satır fonksiyonlarını kullanıyor.
require_once __DIR__ . '/sync/DialplanBuilders.php';
require_once __DIR__ . '/sync/SyncInboundDialplan.php';
require_once __DIR__ . '/sync/SyncOutboundDialplan.php';
require_once __DIR__ . '/sync/SyncGeneralDialplan.php';
require_once __DIR__ . '/sync/SyncMOH.php';
require_once __DIR__ . '/sync/SyncExtensions.php';
require_once __DIR__ . '/sync/SyncTrunks.php';
require_once __DIR__ . '/sync/SyncQueues.php';
require_once __DIR__ . '/sync/SyncIVRs.php';
require_once __DIR__ . '/sync/SyncTimeConditions.php';
require_once __DIR__ . '/sync/SyncFeatureCodes.php';
require_once __DIR__ . '/sync/SyncInternalNumbers.php';
require_once __DIR__ . '/sync/SyncRtpSettings.php';
require_once __DIR__ . '/sync/SyncUdptlSettings.php';

/**
 * Sistem varsayılan dilini (/etc/asterisk/asterisk.conf [options] defaultlanguage=)
 * günceller. DİKKAT: bu, diğer tüm sync* fonksiyonlarından FARKLI — "core reload" ile
 * DEĞİL, sadece TAM Asterisk yeniden başlatmasıyla devreye giriyor (asterisk.conf
 * [options] sadece açılışta okunuyor). Bu yüzden değer GERÇEKTEN değiştiyse true,
 * değişmediyse false döner — çağıran taraf (asterisk_settings.php) sadece true
 * dönerse restart tetiklemeli, her kayıtta gereksiz restart atmamalı.
 */
function syncDefaultLanguage($lang) {
    $lang = preg_replace('/[^a-zA-Z_]/', '', $lang) ?: 'en';
    $conf_path = '/etc/asterisk/asterisk.conf';
    $content = @file_get_contents($conf_path);
    if ($content === false) return false;

    if (preg_match('/^defaultlanguage\s*=\s*(.*)$/m', $content, $m) && trim($m[1]) === $lang) {
        return false; // zaten aynı deger, degisiklik yok
    }

    if (preg_match('/^defaultlanguage\s*=.*$/m', $content)) {
        $content = preg_replace('/^defaultlanguage\s*=.*$/m', "defaultlanguage = {$lang}", $content, 1);
    } else {
        // [options] bölümünün sonuna ekle (bir sonraki [section] veya dosya sonuna kadar)
        $content = preg_replace('/(\[options\][^\[]*)/', "$1defaultlanguage = {$lang}\n", $content, 1);
    }

    file_put_contents($conf_path, $content);
    return true;
}

/**
 * Full Initial / Bulk System Sync Trigger
 */
function syncEverything() {
    // Her syncXxx() fonksiyonu KENDİ ilgili reload'unu (dialplan/pjsip/queue/moh)
    // zaten çağırıyor — burada aynı reload'ları tekrar tetiklemek gereksizdi
    // (tek bir syncEverything() çağrısında dialplan reload 5 kez, pjsip reload
    // 2 kez, moh reload 2 kez tekrarlanıyordu, 2026-08-21 denetiminde bulundu).
    syncTransports();
    syncAllExtensions();
    syncAllTrunks();
    syncAllQueues();
    syncAllIVRs();
    syncAllTimeConditions();
    syncInboundDialplan();
    syncOutboundDialplan();
    syncFeatureCodes();
    // Numara context'i, onu include eden genel dialplan'dan ONCE uretilmeli;
    // aksi halde ilk kurulumda Asterisk var olmayan bir context'i include eder.
    syncInternalNumbers();
    syncGeneralDialplan();
    syncAsteriskMOH();
    syncRtpSettings();
    syncUdptlSettings();

    return true;
}

if (php_sapi_name() === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    syncEverything();
}

