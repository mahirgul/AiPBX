<?php
// Developer tool: finds fields saved in the panel that NEVER reach the generated
// configuration. record_call was a bug of this kind: it sat in the DB,
// SyncInboundDialplan never read it; it looked ticked in the panel but no audio
// file was ever created. Run: php web/bin/field_audit.php
if (PHP_SAPI !== 'cli') {   // cron/dialplan tool; never over HTTP
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';
$db = getDB();

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
// Only the tables that generate Asterisk configuration.
$tables = array_filter($tables, fn($t) => str_starts_with($t, 'pbx_'));

// The WHOLE code base is scanned: a field read in the panel but never reaching
// sync is found, and so is a field never used at all.
$code = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..'));
foreach ($it as $f) {
    if ($f->isFile() && preg_match('/\.(php|js)$/', $f->getFilename())) {
        $path = $f->getPathname();
        if (strpos($path, '/vendor/') !== false || strpos($path, '/tests/') !== false) continue;
        $code .= file_get_contents($path);
    }
}
// Collect the sync code SEPARATELY: a field read somewhere but never reaching sync is a separate bug.
$syncCode = '';
foreach (array_merge(glob(__DIR__ . '/../src/sync/*.php'), glob(__DIR__ . '/../src/*_helper.php')) as $f) { $syncCode .= file_get_contents($f); }

// These fields are not written to the configuration, so naturally they have no references.
$ignore = ['id','created_at','updated_at','is_active','title','description','name',
           'sort_order','deleted_at','notes','user_id','last_login','password_hash'];

$findings = [];
foreach ($tables as $t) {
    try { $columns = $db->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { continue; }
    foreach ($columns as $c) {
        $field = $c['Field'];
        if (in_array($field, $ignore, true)) continue;
        // Does the field name appear anywhere in the code?
        if (strpos($code, "'$field'") === false && strpos($code, "\"$field\"") === false
            && strpos($code, "[$field]") === false && strpos($code, "\$d['$field']") === false) {
            $findings['NEVER USED'][$t][] = $field;
        } elseif (strpos($syncCode, "'$field'") === false && strpos($syncCode, "[$field]") === false) {
            $findings['IN THE PANEL, NOT READ BY SYNC'][$t][] = $field . ' (' . $c['Type'] . ')';
        }
    }
}

foreach ($findings as $kind => $byTable) {
    echo "\n=== $kind ===\n";
    foreach ($byTable as $t => $fields) {
        echo "  $t\n";
        foreach ($fields as $a) echo "      - $a\n";
    }
}
if (!$findings) echo "  No findings.\n";
