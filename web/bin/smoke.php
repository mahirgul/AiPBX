<?php
/**
 * AI PBX Duman Testi — tek komutla "hiçbir şey patlamıyor mu?" kontrolü.
 *
 * Kullanım: php bin/smoke.php [--only=routes|rbac|conventions|lint]
 * Çıkış kodu: 0 = temiz, 1 = en az bir hata.
 *
 * GÜVENLİK: Bu araç SADECE okur/render eder. Hiçbir POST, servis yazımı,
 * config üretimi veya Asterisk reload'u tetiklemez.
 *
 * NOT: root olarak çalıştırılır — /var/www/html/bin sertleştirme gereği
 * drwxr-x--- root:root, asterisk kullanıcısı erişemiyor.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

const SMOKE_ROOT = '/var/www/html';

$only = null;
// --route=<yol>: tek bir rotayı tarar. Hata ayıklama için ve kusur-enjeksiyon
// doğrulamalarında canlı sayfanın bozuk kaldığı pencereyi kısaltmak için var.
$onlyRoute = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--only=') === 0)  { $only = substr($a, 7); }
    if (strpos($a, '--route=') === 0) { $onlyRoute = substr($a, 8); }
}

$FAILS  = [];
$COUNTS = [];

function fail(string $group, string $what, string $detail): void
{
    global $FAILS;
    $FAILS[] = ['group' => $group, 'what' => $what, 'detail' => $detail];
}

function group_done(string $group, int $n): void
{
    global $COUNTS;
    $COUNTS[$group] = $n;
}

/**
 * Tek bir rotayı alt-süreçte render edip çözümlenmiş sonucu döner.
 */
function render_route(string $path, string $lang, string $role): ?array
{
    $out = tempnam(sys_get_temp_dir(), 'smoke');
    $cmd = 'php ' . escapeshellarg(SMOKE_ROOT . '/bin/_smoke_render.php')
         . ' ' . escapeshellarg($path)
         . ' ' . escapeshellarg($lang)
         . ' ' . escapeshellarg($role)
         . ' ' . escapeshellarg($out) . ' 2>/dev/null';
    $o = [];
    exec($cmd, $o, $ret);
    $raw = is_file($out) ? (string) file_get_contents($out) : '';
    @unlink($out);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// ---------------------------------------------------------------- ROTALAR
function check_routes(?string $onlyRoute = null): void
{
    $ROUTES = require SMOKE_ROOT . '/src/routes.php';
    if ($onlyRoute !== null) {
        $ROUTES = isset($ROUTES[$onlyRoute]) ? [$onlyRoute => $ROUTES[$onlyRoute]] : [];
    }

    // Saf yönlendirme yapan rotalar render edilemez (header+exit), kapsam dışı.
    $SKIP = ['/', '/index.php', '/logout'];

    // Ön koşulu olmadan MEŞRU şekilde yönlendiren rotalar: sayfa üretmemeleri
    // doğru davranış, sadece "fatal/PHP hatası yok" kontrol edilir.
    // /force-reset → $_SESSION['pending_reset_user_id'] yoksa /login'e gider.
    // /login-2fa   → $_SESSION['pending_2fa_user_id'] yoksa /login'e gider.
    $REDIRECT_OK = ['/force-reset', '/login-2fa'];

    // Tam sayfa için alt sınır. /reset-password token'sız hâlde ~1700 bayt
    // meşru bir form basıyor; eşik bunun altında ama gerçekten boş/yarım
    // render'ı hâlâ yakalayacak şekilde seçildi.
    $MIN_BYTES = 1000;

    $n = 0;

    foreach ($ROUTES as $path => $route) {
        if (in_array($path, $SKIP, true) || !is_array($route)) { continue; }

        foreach (['tr', 'en'] as $lang) {
            $d = render_route($path, $lang, 'admin');
            $n++;

            if ($d === null) {
                fail('rota', "$path [$lang]", 'alt-surec sonuc uretmedi');
                continue;
            }
            if (($d['status'] ?? '') === 'skip') { $n--; continue; }
            if (($d['status'] ?? '') === 'fatal') {
                fail('rota', "$path [$lang]", $d['error']);
                continue;
            }

            $html = $d['output'] ?? '';

            // PHP uyarıları çıktıdan değil, alt-sürecin set_error_handler'ından
            // gelir — uygulama display_errors=Off ile çalıştığı için HTML'de
            // asla görünmezler (bkz. _smoke_render.php'deki açıklama).
            foreach (($d['php_errors'] ?? []) as $e) {
                fail('rota', "$path [$lang]", 'PHP hatasi: ' . $e);
            }

            // HAYALET ID: JS'in getElementById ile aradığı ama sayfada BULUNMAYAN
            // eleman. null'a .value yazılınca JS o satırda durur; butonun geri
            // kalanı (modalı açmak vb.) hiç çalışmaz ve konsolu açmayan kimse
            // sebebini göremez — SESSİZ bir bozukluk. 2026-09-01'de /sounds'ta
            // gerçekten yaşandı: MOH müzik yükleme butonu olmayan bir elemana
            // yazıyordu, modal hiç açılmıyordu ve upload_moh_file backend'i
            // bu yüzden tamamen ölüydü. Sadece bir dilde bakmak yeterli.
            if ($lang === 'tr') {
                preg_match_all('/\bid=["\']([^"\']+)["\']/', $html, $mv);
                $mevcut = array_flip($mv[1]);
                preg_match_all('/getElementById\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $html, $ar);
                foreach (array_unique($ar[1]) as $id) {
                    if (!isset($mevcut[$id])) {
                        fail('rota', $path, "hayalet id: JS '{$id}' elemanini ariyor ama sayfada yok");
                    }
                }
            }

            if (in_array($path, $REDIRECT_OK, true)) {
                continue; // yönlendirmesi meşru — boyut/kapanış kontrolü yapılmaz
            }
            if (($d['bytes'] ?? 0) < $MIN_BYTES) {
                fail('rota', "$path [$lang]", 'cikti sasirtici sekilde kisa: ' . ($d['bytes'] ?? 0) . ' bayt');
            }
            if (empty($d['closed'])) {
                fail('rota', "$path [$lang]", 'cikti </html> ile kapanmiyor (yarim render?)');
            }
        }
    }
    group_done('rota', $n);
}

if ($only === null || $only === 'routes') { check_routes($onlyRoute); }

// ------------------------------------------------------------------ RBAC
/**
 * Admin'e özel rotalar düşük yetkili bir rolle ENGELLENMELİ.
 *
 * Her rota İKİ kez render edilir: admin ile (render EDEBİLMELİ) ve fax_user
 * ile (EDEMEMELİ). Admin tarafı bir "kanarya": o da render edemiyorsa test
 * boşuna geçiyor demektir ve bu ayrıca raporlanır. Kanarya olmadan bu kontrol
 * yanlış sebeple yeşil kalabilirdi (2026-09-01'de fark edildi).
 *
 * KAPSAM NOTU: bu kontrol "düşük yetkili rol admin sayfasını göremiyor"
 * garantisi verir; auth.php'deki yetki-yükseltme DEVRE KESİCİSİNİ tek başına
 * izole edemez, çünkü canlı DB'de zaten hiçbir role bu modüller için
 * can_access=1 verilmemiş (doğrulandı) — yani sayfa normal izin yolundan da
 * kapalı. Devre kesicinin kendisi, izin satırlarının kontrol edilebildiği
 * izole test veritabanıyla ayrıca test edilir (bkz. tests/unit/RbacTest.php).
 */
function check_rbac(): void
{
    $ADMIN_ONLY = ['/roles', '/system-users', '/firewall', '/fail2ban', '/asterisk-settings'];
    $n = 0;

    // Ayrım saf boyutla yapılır. Canlıda ölçüldü (2026-09-01): engellenen istek
    // standart ret sayfasını basıyor → 746 bayt; izinli render 50–207 KB.
    // Metinde "yetki"/"permission" aramak İŞE YARAMAZ: /roles ve /system-users
    // zaten izin matrisi sayfaları, içerikleri doğal olarak bu kelimeleri
    // taşıyor ve yanlış alarm veriyorlardı.
    // Eşiklerin ayırt etme gücü canlıda ölçülerek doğrulandı: izinli/engelli
    // farkı ~67 kat, aradaki boşluk çok geniş.
    //
    // YAN BULGU (2026-09-01): Controller'daki static::requireRole('admin')
    // kaldırılsa BİLE sayfa açılmıyor — ikinci bir uygulama katmanı 403
    // basıyor (savunma derinliği). Yani bu kontrolün "kırmızıya dönebildiğini"
    // üretimde göstermek iki katmanı birden bozmayı gerektirirdi; bu yapılmadı.
    $IZINLI_MIN    = 10000;
    $ENGELLI_MAKS  = 5000;

    foreach ($ADMIN_ONLY as $path) {
        // Kanarya: admin bu sayfayı görebilmeli. Göremiyorsa aşağıdaki asıl
        // kontrol anlamsız şekilde yeşil kalırdı.
        $asAdmin = render_route($path, 'tr', 'admin');
        $n++;
        $adminBytes = $asAdmin['bytes'] ?? 0;
        if ($adminBytes < $IZINLI_MIN) {
            fail('rbac', $path, "KANARYA: admin bile bu sayfayi render edemedi ({$adminBytes} bayt)"
                . ' — bu kontrol anlamsiz gecerdi');
            continue;
        }

        // Asıl kontrol: düşük yetkili rol görememeli.
        $asLow = render_route($path, 'tr', 'fax_user');
        $n++;
        $lowBytes = $asLow['bytes'] ?? 0;
        if ($lowBytes > $ENGELLI_MAKS) {
            fail('rbac', $path, "fax_user rolu bu admin sayfasini RENDER EDEBILDI ({$lowBytes} bayt,"
                . " admin {$adminBytes} bayt) — yetki acigi!");
        }
    }
    group_done('rbac', $n);
}

if ($only === null || $only === 'rbac') { check_rbac(); }

// ----------------------------------------------------------- KONVANSİYON
/**
 * Dosyanın YORUMSUZ hâlini döner (string literal'ler korunur).
 *
 * Neden: düz grep, yorum içindeki masum bir cümleyi kural ihlali sanıyordu —
 * TimeConditionService'teki "syncAllTimeConditions() zaten hepsini..." açıklaması
 * yanlış alarm vermişti (2026-09-01). Konvansiyon kuralları KODU denetlemeli,
 * yorumu değil.
 */
function php_code_without_comments(string $file): string
{
    $out = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { continue; }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * Grep tabanlı mimari kural kontrolleri. Her kural, bu projede GERÇEKTEN
 * yaşanmış bir hataya karşılık geliyor — hiçbiri teorik değil.
 * Tüm kurallar YORUMSUZ kaynak üzerinde çalışır.
 */
function check_conventions(): void
{
    $n = 0;
    $rel = function (string $p): string { return str_replace(SMOKE_ROOT . '/', '', $p); };

    // 1) Servis katmanı doğrudan sync çağırmamalı — ertelenmiş reload ("Uygula")
    //    sistemi baypas edilmiş olur. Olay: PBXHelper::toggleStatus() rollout'tan
    //    atlanmıştı ve anında reload yapmaya devam ediyordu (2026-08-24).
    $n++;
    foreach (glob(SMOKE_ROOT . '/src/services/*.php') as $f) {
        $src = php_code_without_comments($f);
        if (preg_match('/(?<![\w:>$])(syncAll[A-Za-z]+|sync[A-Za-z]+Dialplan|syncEverything)\s*\(/', $src, $m)) {
            fail('konvansiyon', $rel($f),
                "dogrudan {$m[1]}() cagiriyor — markPendingSync() kullanilmali");
        }
    }

    // 2) Repo dışına yazan kod FileHelper kullanmalı: atomik yazım + sahiplik
    //    tuzağı. Olay: Fail2banService düz file_put_contents ile SESSİZCE
    //    yazamıyordu, kullanıcıya "başarılı" gösteriliyordu (2026-08-31).
    $n++;
    foreach (array_merge(glob(SMOKE_ROOT . '/src/services/*.php'),
                         glob(SMOKE_ROOT . '/src/sync/*.php')) as $f) {
        $src = php_code_without_comments($f);
        if (preg_match('/file_put_contents\s*\(\s*[^,)]*(\/etc\/|OVERRIDE_FILE|ASTERISK_PBX_DIR)/', $src)) {
            fail('konvansiyon', $rel($f),
                'repo disina duz file_put_contents ile yaziyor — FileHelper::writeFile() kullanilmali');
        }
    }

    // 3) Üretilen dosya başlıklarında eski kurumsal marka kalmamalı.
    //    Olay: de-branding üreteçleri atlamıştı; dosyaların canlı hâli elle
    //    düzeltilmiş ama ilk senkronda eski isme geri dönecekti (2026-09-01).
    $n++;
    foreach (array_merge(glob(SMOKE_ROOT . '/src/sync/*.php'),
                         glob(SMOKE_ROOT . '/src/services/*.php')) as $f) {
        $src = php_code_without_comments($f);
        if (preg_match('/(KB[UÜ] Portal|Karab[uü]k)/u', $src, $m)) {
            fail('konvansiyon', $rel($f), "uretilen ciktida eski marka referansi: '{$m[1]}'");
        }
    }

    // 4) Her controller bir yetki kontrolü çağırmalı.
    //    İSTİSNA: kimlik doğrulama akışının kendisi oturum GEREKTİRMEZ —
    //    giriş, çıkış ve şifre sıfırlama sayfaları doğaları gereği anonim
    //    erişilebilir olmalı, onlarda yetki kontrolü aramak yanlış olur.
    $ANONIM_CONTROLLER = [
        'LoginController.php',
        'TwoFactorLoginController.php',
        'LogoutController.php',
        'ForceResetController.php',
        'ResetPasswordController.php',
    ];
    $n++;
    foreach (glob(SMOKE_ROOT . '/src/controllers/*.php') as $f) {
        if (in_array(basename($f), $ANONIM_CONTROLLER, true)) { continue; }
        $src = php_code_without_comments($f);
        if (!preg_match('/(requireRole|requireLogin|requireApiLogin)\s*\(/', $src)) {
            fail('konvansiyon', $rel($f), 'hicbir yetki kontrolu cagirmiyor');
        }
    }

    // 5) api/cc_actions altındaki her aksiyon dosyası dispatcher guard'ı taşımalı,
    //    yoksa doğrudan çağrılabilir.
    $n++;
    foreach (glob(SMOKE_ROOT . '/api/cc_actions/*.php') as $f) {
        if (strpos(file_get_contents($f), 'CC_DISPATCH_ACTIVE') === false) {
            fail('konvansiyon', $rel($f), 'CC_DISPATCH_ACTIVE guard yok — dogrudan cagrilabilir');
        }
    }

    // 6) INTERNAL_NUMBER_SOURCES manifesti ile şema uyumlu olmalı.
    //    Manifest'e satır eklenip migration unutulursa internalNumberEntries()
    //    üretimde "Unknown column" ile patlar — beş sayfa birden açılmaz.
    $n++;
    require_once SMOKE_ROOT . '/src/internal_numbers.php';
    $sdb = getDB();
    foreach (INTERNAL_NUMBER_SOURCES as $key => $src) {
        try {
            $kolonlar = $sdb->query("DESCRIBE `{$src['table']}`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            fail('konvansiyon', "internal_numbers: {$src['table']}", 'tablo okunamadi: ' . $e->getMessage());
            continue;
        }
        foreach (['internal_number' => 'internal_number', 'label_col' => $src['label_col'], 'dest_col' => $src['dest_col']] as $etiket => $kolon) {
            if (!in_array($kolon, $kolonlar, true)) {
                fail('konvansiyon', "internal_numbers[{$key}]",
                     "{$src['table']}.{$kolon} yok ({$etiket}) — manifest ile sema uyumsuz");
            }
        }
    }

    // 7) api/ altindaki dosyalar dirname(__DIR__, 2) . '/sync/...' yazamaz.
    //    /var/www/html/sync/ diye bir dizin YOK (gercek yol src/sync/); bu hatali
    //    require_once yakalanabilir bir Error firlatir ve catch bloklari onu
    //    sessizce yutar. api/mobile/features.php'de tam olarak bu oldu ve mobil
    //    DND/yonlendirme ayari aylarca dialplan'a hic yansimadi (2026-09-05).
    $n++;
    foreach (glob(SMOKE_ROOT . '/api/*/*.php') as $f) {
        $src = php_code_without_comments($f);
        if (strpos($src, "dirname(__DIR__, 2) . '/sync/") !== false
            || strpos($src, 'dirname(__DIR__, 2) . "/sync/') !== false) {
            fail('konvansiyon', $rel($f),
                 "yanlis sync yolu: /var/www/html/sync/ yok, dogrusu /src/sync/");
        }
    }

    group_done('konvansiyon', $n);
}

if ($only === null || $only === 'conventions') { check_conventions(); }

// ------------------------------------------------------------------ LINT
/**
 * Sözdizimi ve dil bütünlüğü kontrolleri.
 */
function check_lint(): void
{
    $n = 0;

    // 1) PHP sözdizimi — vendor hariç tüm dosyalar.
    //    Dosya başına ayrı süreç gerekiyor (php -l tek dosya alır); 196 dosya
    //    seri koşulduğunda 8.2 sn, xargs -P8 ile 4.0 sn (ölçüldü 2026-09-01).
    $n++;
    $listFile = tempnam(sys_get_temp_dir(), 'smokelint');
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(SMOKE_ROOT, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (strpos($p, '/vendor/') !== false) { continue; }
        if (substr($p, -4) !== '.php') { continue; }
        $files[] = $p;
    }
    file_put_contents($listFile, implode("\n", $files) . "\n");

    // `php -l` parse hatasında 255 döner ve xargs 255'i görünce KALAN DOSYALARI
    // TARAMADAN durur ("exited with status 255; aborting") — tek bir bozuk dosya
    // diğer tüm hataları maskelerdi (2026-09-01'de hook testinde görüldü).
    // sh -c ... || true ile her çağrının çıkış kodu sıfırlanıyor.
    $o = [];
    exec('xargs -P8 -n1 sh -c \'php -l "$0" 2>&1 || true\' < '
        . escapeshellarg($listFile) . ' 2>&1', $o);
    @unlink($listFile);
    foreach ($o as $line) {
        if ($line === '' || strpos($line, 'No syntax errors') === 0) { continue; }
        fail('lint', 'php -l', trim($line));
    }

    // 2) Dil anahtarı simetrisi — mevcut script yeniden kullanılıyor.
    $n++;
    $o2 = [];
    exec('php ' . escapeshellarg(SMOKE_ROOT . '/bin/lint_lang.php') . ' 2>&1', $o2, $ret2);
    if ($ret2 !== 0) {
        fail('lint', 'lint_lang.php', trim(implode(' | ', array_slice($o2, -6))));
    }

    // 3) JS sözdizimi (vendor/min hariç).
    //
    //    `node --check` gerçek bir ayrıştırıcıdır. Önceden burada yalnızca
    //    süslü parantez sayımı vardı; o, sözdizimi hatasının çoğunu KAÇIRIR
    //    (dengeli parantezle de bozuk JS yazılabilir). 2026-09-15'te
    //    header_phone.js'e yapılan bir düzenleme bu yüzden ayrıştırıcıdan
    //    geçmeden commit edildi — node kurulu değildi.
    //
    //    node yoksa eski kaba sayım yedek olarak korunur: kontrol hiç
    //    çalışmamasındansa zayıf çalışsın.
    $n++;
    exec('command -v node 2>/dev/null', $nodeOut, $nodeRet);
    $hasNode = ($nodeRet === 0 && !empty($nodeOut));
    foreach (glob(SMOKE_ROOT . '/assets/js/*.js') as $f) {
        if (preg_match('/\.min\.js$/', basename($f))) { continue; }
        if ($hasNode) {
            $o3 = [];
            exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o3, $ret3);
            if ($ret3 !== 0) {
                fail('lint', basename($f), 'node --check: ' . trim(implode(' | ', array_slice($o3, 0, 3))));
            }
        } else {
            $src = file_get_contents($f);
            $open = substr_count($src, '{');
            $close = substr_count($src, '}');
            if ($open !== $close) {
                fail('lint', basename($f), "suslu parantez dengesi bozuk ({ $open / } $close) [node yok]");
            }
        }
    }

    group_done('lint', $n);
}

if ($only === null || $only === 'lint') { check_lint(); }

// ---------------------------------------------------------------- RAPOR
echo "\n";
foreach ($COUNTS as $g => $n) { printf("  %-14s %d kontrol\n", $g, $n); }

if (empty($FAILS)) {
    echo "\nTEMİZ — tüm kontroller geçti.\n";
    exit(0);
}

echo "\n" . count($FAILS) . " HATA:\n";
foreach ($FAILS as $f) {
    printf("  [%s] %s\n      %s\n", $f['group'], $f['what'], $f['detail']);
}
exit(1);
