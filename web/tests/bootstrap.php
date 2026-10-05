<?php
/**
 * PHPUnit bootstrap — points the application at an ISOLATED test environment.
 *
 * portalEnv() looks at getenv() first (config.php), so the putenv() calls
 * below redirect the whole application to the test database and a temporary
 * config directory — without a single line of change in production code.
 *
 * THREE SAFETY LOCKS:
 *  1. Tests do not start at all unless DB_NAME is 'asterisk_test'.
 *  2. ASTERISK_PBX_DIR points to a temp directory → nothing is written to
 *     the live /etc/asterisk.
 *  3. AIPBX_NO_ASTERISK=1 → AsteriskHelper::execCLI() sends no command to
 *     the live Asterisk (reloads included).
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

// LOCK 1 — never run tests against the production database.
if (getenv('DB_NAME') !== 'asterisk_test') {
    fwrite(STDERR, "GÜVENLİK: testler yalnızca 'asterisk_test' üzerinde koşabilir, "
        . "şu an DB_NAME='" . getenv('DB_NAME') . "'. İptal edildi.\n");
    exit(1);
}

// LOCK 2 — generated Asterisk configs go to a temp directory.
$tmpConf = sys_get_temp_dir() . '/aipbx-test-conf';
if (!is_dir($tmpConf)) { mkdir($tmpConf, 0755, true); }
putenv('ASTERISK_PBX_DIR=' . $tmpConf);
// Asterisk's own files (rtp.conf, udptl.conf, …) too — they used to be written to the live /etc/asterisk.
if (!is_dir($tmpConf . '/etc')) { mkdir($tmpConf . '/etc', 0755, true); }
putenv('ASTERISK_CONF_DIR=' . $tmpConf . '/etc');

// Sounds, Cloud TTS audio and the settings key stay in the temp dir too.
foreach (['sounds', 'tts'] as $d) {
    if (!is_dir("{$tmpConf}/{$d}")) { mkdir("{$tmpConf}/{$d}", 0755, true); }
}
putenv('SOUNDS_CUSTOM_DIR=' . $tmpConf . '/sounds');
putenv('AI_TTS_DIR=' . $tmpConf . '/tts');
putenv('AIPBX_SETTINGS_KEY=test-settings-key');

// LOCK 3 — no CLI command may reach the live Asterisk.
putenv('AIPBX_NO_ASTERISK=1');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
