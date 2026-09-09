<?php
/**
 * PHPUnit bootstrap — uygulamayı İZOLE test ortamına yönlendirir.
 *
 * portalEnv() önce getenv()'e baktığı için (config.php), aşağıdaki putenv()
 * çağrıları tüm uygulamayı test veritabanına ve geçici config dizinine
 * yönlendirir — üretim kodunda tek satır değişiklik gerekmeden.
 *
 * ÜÇ EMNİYET KİLİDİ:
 *  1. DB_NAME 'asterisk_test' değilse testler hiç başlamaz.
 *  2. ASTERISK_PBX_DIR geçici dizine yönlendirilir → canlı /etc/asterisk'e
 *     hiçbir şey yazılmaz.
 *  3. AIPBX_NO_ASTERISK=1 → AsteriskHelper::execCLI() canlı Asterisk'e komut
 *     göndermez (reload dahil).
 */

$envFile = __DIR__ . '/.env.test';
if (!is_readable($envFile)) {
    fwrite(STDERR, "HATA: tests/.env.test yok.\nÖnce çalıştır: bash bin/setup-test-db.sh\n");
    exit(1);
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
    putenv($line);
}

// KİLİT 1 — üretim veritabanına karşı test koşmayı kesinlikle engelle.
if (getenv('DB_NAME') !== 'asterisk_test') {
    fwrite(STDERR, "GÜVENLİK: testler yalnızca 'asterisk_test' üzerinde koşabilir, "
        . "şu an DB_NAME='" . getenv('DB_NAME') . "'. İptal edildi.\n");
    exit(1);
}

// KİLİT 2 — üretilen Asterisk config'leri geçici dizine.
$tmpConf = sys_get_temp_dir() . '/aipbx-test-conf';
if (!is_dir($tmpConf)) { mkdir($tmpConf, 0755, true); }
putenv('ASTERISK_PBX_DIR=' . $tmpConf);

// KİLİT 3 — canlı Asterisk'e hiçbir CLI komutu gitmesin.
putenv('AIPBX_NO_ASTERISK=1');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
