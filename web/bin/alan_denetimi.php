<?php
// Finds fields saved in the panel that NEVER reach the generated configuration.
// record_call was a bug of this kind: it sat in the DB, SyncInboundDialplan never
// read it; it looked ticked in the panel but no audio file was ever created.
require_once __DIR__ . '/../config.php';
$db = getDB();

$tablolar = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
// Yalnizca Asterisk konfigurasyonunu ureten tablolar.
$tablolar = array_filter($tablolar, fn($t) => str_starts_with($t, 'pbx_'));

// Sync + dialplan uretici kodun tamami
$kod = '';
// The WHOLE code base is scanned: a field read in the panel but never reaching
// sync is found, and so is a field never used at all.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..'));
foreach ($it as $f) {
    if ($f->isFile() && preg_match('/\.(php|js)$/', $f->getFilename())) {
        $yol = $f->getPathname();
        if (strpos($yol, '/vendor/') !== false || strpos($yol, '/tests/') !== false) continue;
        $kod .= file_get_contents($yol);
    }
}
// Collect the sync code SEPARATELY: a field read somewhere but never reaching sync is a separate bug.
$syncKod = '';
foreach (array_merge(glob(__DIR__ . '/../src/sync/*.php'), glob(__DIR__ . '/../src/*_helper.php')) as $f) { $syncKod .= file_get_contents($f); }

// These fields are not written to the configuration, so naturally they have no references.
$yoksay = ['id','created_at','updated_at','is_active','title','description','name',
           'sort_order','deleted_at','notes','user_id','last_login','password_hash'];

$bulgular = [];
foreach ($tablolar as $t) {
    try { $sutunlar = $db->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { continue; }
    foreach ($sutunlar as $s) {
        $ad = $s['Field'];
        if (in_array($ad, $yoksay, true)) continue;
        // Does the field name appear anywhere in the code?
        if (strpos($kod, "'$ad'") === false && strpos($kod, "\"$ad\"") === false
            && strpos($kod, "[$ad]") === false && strpos($kod, "\$d['$ad']") === false) {
            $bulgular['HIC KULLANILMIYOR'][$t][] = $ad;
        } elseif (strpos($syncKod, "'$ad'") === false && strpos($syncKod, "[$ad]") === false) {
            $bulgular['PANELDE VAR, SYNC OKUMUYOR'][$t][] = $ad . ' (' . $s['Type'] . ')';
        }
    }
}

foreach ($bulgular as $sinif => $tablolar2) {
    echo "\n=== $sinif ===\n";
    foreach ($tablolar2 as $t => $alanlar) {
        echo "  $t\n";
        foreach ($alanlar as $a) echo "      - $a\n";
    }
}
if (!$bulgular) echo "  Bulgu yok.\n";
