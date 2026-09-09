<?php
/**
 * lang/tr.php ve lang/en.php arasındaki anahtar tutarlılığını + kodda t()
 * ile çağrılan her anahtarın dosyalarda gerçekten var olduğunu kontrol eder.
 *
 * t()'in kendisi eksik bir anahtarda hata FIRLATMIYOR ($default ?? $key'e
 * düşüyor) — bu bilinçli bir tasarım (sayfa asla kırılmaz) ama sonucu sessiz
 * bir eksik-çeviri hatası: sayfada "sidebar.item_foo" gibi çiğ bir anahtar
 * görünür, kimse fark etmeyene kadar. Bu script o sessizliği CI/manuel
 * çalıştırmada görünür hataya çevirir. 2026-08-23 mimari incelemesinin 6.
 * orta öncelikli maddesi.
 *
 * Kullanım: php bin/lint_lang.php
 * Çıkış kodu: 0 = temiz, 1 = sorun bulundu.
 */

$root = dirname(__DIR__);
$tr = require $root . '/lang/tr.php';
$en = require $root . '/lang/en.php';

if (!is_array($tr) || !is_array($en)) {
    fwrite(STDERR, "HATA: lang/tr.php veya lang/en.php bir dizi döndürmüyor.\n");
    exit(1);
}

$problems = 0;

// 1) tr <-> en anahtar simetrisi
$only_in_tr = array_diff(array_keys($tr), array_keys($en));
$only_in_en = array_diff(array_keys($en), array_keys($tr));

if (!empty($only_in_tr)) {
    echo "Sadece tr.php'de olan anahtarlar (" . count($only_in_tr) . "):\n";
    foreach ($only_in_tr as $k) echo "  - $k\n";
    $problems += count($only_in_tr);
}
if (!empty($only_in_en)) {
    echo "Sadece en.php'de olan anahtarlar (" . count($only_in_en) . "):\n";
    foreach ($only_in_en as $k) echo "  - $k\n";
    $problems += count($only_in_en);
}

// 2) Kodda t('...') / t("...") ile çağrılan her anahtar iki dosyada da var mı?
// Not: dinamik anahtarlar (ör. t($var)) regex ile yakalanamaz — bu script
// sadece SABİT string literal çağrıları tarar, dinamik kullanım manuel
// gözden geçirme gerektirir (kod tabanında bu nadir bir desen).
$dirs = ['src', 'templates', 'modules'];
$root_files = ['config.php', 'header.php', 'footer.php', 'auth.php', 'index.php'];
$used_keys = [];

$scan_paths = [];
foreach ($dirs as $d) {
    $full = $root . '/' . $d;
    if (is_dir($full)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') $scan_paths[] = $file->getPathname();
        }
    }
}
foreach ($root_files as $rf) {
    $full = $root . '/' . $rf;
    if (is_file($full)) $scan_paths[] = $full;
}

$dynamic_prefixes = [];
foreach ($scan_paths as $path) {
    $src = file_get_contents($path);
    if ($src === false) continue;
    // Yakalanan grup 2, kapanış tırnağından hemen sonraki karakter — bir '.'
    // ise bu t('prefix' . $var) gibi DİNAMİK bir anahtar (ör. roles.php'nin
    // grup/modül rozetleri), "prefix" kendisi gerçek bir anahtar değildir,
    // eksik-anahtar kontrolünden hariç tutulur (ayrı, bilgi amaçlı listelenir).
    if (preg_match_all('/\bt\(\s*[\'"]([a-zA-Z0-9_.]+)[\'"](\s*\.)?/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $key = $match[1];
            if (!empty($match[2])) {
                $dynamic_prefixes[$key][] = $path;
                continue;
            }
            $used_keys[$key][] = $path;
        }
    }
}

$missing = [];
foreach ($used_keys as $key => $files) {
    if (!array_key_exists($key, $tr) || !array_key_exists($key, $en)) {
        $missing[$key] = $files;
    }
}

if (!empty($missing)) {
    echo "\nKodda kullanılan ama lang dosyalarında eksik olan anahtarlar (" . count($missing) . "):\n";
    foreach ($missing as $key => $files) {
        $in_tr = array_key_exists($key, $tr) ? 'tr:var' : 'tr:YOK';
        $in_en = array_key_exists($key, $en) ? 'en:var' : 'en:YOK';
        echo "  - $key ($in_tr, $in_en) — " . $files[0] . (count($files) > 1 ? ' +' . (count($files) - 1) . ' dosya daha' : '') . "\n";
    }
    $problems += count($missing);
}

if (!empty($dynamic_prefixes)) {
    echo "\nDinamik anahtar öneki (t('prefix' . \$var) deseni, kontrol edilemedi — " . count($dynamic_prefixes) . "):\n";
    foreach ($dynamic_prefixes as $prefix => $files) {
        echo "  - {$prefix}* — " . $files[0] . (count($files) > 1 ? ' +' . (count($files) - 1) . ' dosya daha' : '') . "\n";
    }
}

echo "\n" . ($problems === 0
    ? "TEMİZ: tr/en anahtar sayısı eşit (" . count($tr) . "), kodda kullanılan " . count($used_keys) . " sabit anahtarın hepsi her iki dosyada da mevcut.\n"
    : "SORUN BULUNDU: $problems madde yukarıda listelendi.\n");

exit($problems === 0 ? 0 : 1);
