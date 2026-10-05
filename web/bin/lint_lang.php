<?php
/**
 * Checks the key consistency between lang/tr.php and lang/en.php + that every
 * key called with t() in the code really exists in the files.
 *
 * t() itself does NOT THROW on a missing key (it falls back to
 * $default ?? $key) — a deliberate design (the page never breaks), but the
 * result is a silent missing-translation bug: a raw key such as
 * "sidebar.item_foo" shows on the page until somebody notices. This script
 * turns that silence into a visible error in CI/manual runs. Item 6 (medium
 * priority) of the 2026-08-23 architecture review.
 *
 * Usage: php bin/lint_lang.php
 * Exit code: 0 = clean, 1 = problems found.
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

// 2) Does every key called with t('...') / t("...") in the code exist in both files?
// Note: dynamic keys (e.g. t($var)) cannot be caught with a regex — this
// script only scans CONSTANT string literal calls; dynamic use needs a manual
// review (a rare pattern in this code base).
$dirs = ['src', 'templates', 'modules'];
$root_files = ['config.php', 'auth.php', 'index.php', 'templates/layouts/app_header.php', 'templates/layouts/app_footer.php'];
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
    // Captured group 2 is the character right after the closing quote — if it
    // is a '.', this is a DYNAMIC key such as t('prefix' . $var) (e.g. the
    // group/module badges in roles.php); "prefix" itself is not a real key and
    // is excluded from the missing-key check (listed separately, for info).
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
