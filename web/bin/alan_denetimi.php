<?php
// Panelde kaydedilen ama uretilen konfigurasyona HIC yansimayan alanlari bulur.
// record_call bu siniftan bir hataydi: DB'de duruyordu, SyncInboundDialplan
// onu hic okumuyordu; panelde isaretli gorunup ses dosyasi hic olusmuyordu.
require_once __DIR__ . '/../config.php';
$db = getDB();

$tablolar = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
// Yalnizca Asterisk konfigurasyonunu ureten tablolar.
$tablolar = array_filter($tablolar, fn($t) => str_starts_with($t, 'pbx_'));

// Sync + dialplan uretici kodun tamami
$kod = '';
// TUM kod tabani taranir: alan panelde okunuyor ama sync'e girmiyorsa da,
// hic kullanilmiyorsa da bulunur.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..'));
foreach ($it as $f) {
    if ($f->isFile() && preg_match('/\.(php|js)$/', $f->getFilename())) {
        $yol = $f->getPathname();
        if (strpos($yol, '/vendor/') !== false || strpos($yol, '/tests/') !== false) continue;
        $kod .= file_get_contents($yol);
    }
}
// Sync kodunu AYRICA topla: alan bir yerde okunup sync'e girmiyorsa bu ayri bir hata.
$syncKod = '';
foreach (array_merge(glob(__DIR__ . '/../src/sync/*.php'), glob(__DIR__ . '/../src/*_helper.php')) as $f) { $syncKod .= file_get_contents($f); }

// Bu alanlar konfigurasyona yazilmaz, dogal olarak referanssizdir.
$yoksay = ['id','created_at','updated_at','is_active','title','description','name',
           'sort_order','deleted_at','notes','user_id','last_login','password_hash'];

$bulgular = [];
foreach ($tablolar as $t) {
    try { $sutunlar = $db->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { continue; }
    foreach ($sutunlar as $s) {
        $ad = $s['Field'];
        if (in_array($ad, $yoksay, true)) continue;
        // Alan adi kodda herhangi bir yerde geciyor mu?
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
