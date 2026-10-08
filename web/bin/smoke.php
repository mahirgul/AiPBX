<?php
/**
 * AI PBX smoke test — a one-command "does nothing blow up?" check.
 *
 * Usage: php bin/smoke.php [--only=routes|rbac|conventions|lint]
 * Exit code: 0 = clean, 1 = at least one failure.
 *
 * SAFETY: this tool ONLY reads/renders. It never triggers a POST, a service
 * write, config generation or an Asterisk reload.
 *
 * NOTE: run as root. The bin/ directory belongs to root and MUST be 755:
 * Asterisk runs the feature-code/fax scripts as the asterisk user through
 * the /usr/local/bin links. Until 2026-09-27 it had been set to 750 by hand
 * and the *60/*72 feature codes silently did nothing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

// Repo root (/var/www/html points here on the server; the checkout directory in CI).
define('SMOKE_ROOT', dirname(__DIR__));

$only = null;
// --route=<path>: scans a single route. For debugging, and to shorten the
// window in which the live page stays broken during fault-injection checks.
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
 * Renders a single route in a subprocess and returns the decoded result.
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

    // Routes that only redirect cannot be rendered (header+exit); out of scope.
    $SKIP = ['/', '/index.php', '/logout'];

    // Routes that LEGITIMATELY redirect without their precondition: producing
    // no page is correct, only "no fatal/PHP error" is checked.
    // /force-reset → goes to /login without $_SESSION['pending_reset_user_id'].
    // /login-2fa   → goes to /login without $_SESSION['pending_2fa_user_id'].
    $REDIRECT_OK = ['/force-reset', '/login-2fa', '/auth/google', '/auth/google/callback'];

    // Lower bound for a full page. /reset-password without a token prints a
    // legitimate ~1700-byte form; the threshold sits below that but still
    // catches a really empty/half render.
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

            // PHP warnings come from the subprocess's set_error_handler, not
            // from the output — the app runs with display_errors=Off, so they
            // never show up in the HTML (see the note in _smoke_render.php).
            foreach (($d['php_errors'] ?? []) as $e) {
                fail('rota', "$path [$lang]", 'PHP hatasi: ' . $e);
            }

            // GHOST ID: an element the JS looks up with getElementById that is
            // NOT on the page. Writing .value to null stops the JS on that
            // line; the rest of the button (opening the modal etc.) never runs
            // and nobody without the console open can see why — a SILENT
            // breakage. It really happened on /sounds on 2026-09-01: the MOH
            // upload button wrote to a missing element, the modal never opened
            // and the upload_moh_file backend was completely dead because of
            // it. Checking one language is enough.
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
                continue; // redirect is legitimate — no size/closing-tag check
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
 * Admin-only routes MUST be blocked for a low-privilege role.
 *
 * Every route is rendered TWICE: as admin (MUST render) and as fax_user
 * (MUST NOT). The admin side is a canary: if it cannot render either, the
 * test passes for nothing, and that is reported separately. Without the
 * canary this check could stay green for the wrong reason (noticed on
 * 2026-09-01).
 *
 * SCOPE NOTE: this check guarantees "a low-privilege role cannot see the
 * admin page"; on its own it cannot isolate the privilege-escalation CIRCUIT
 * BREAKER in auth.php, because on the live DB no role has can_access=1 for
 * these modules anyway (verified) — so the page is closed through the normal
 * permission path too. The circuit breaker itself is tested separately with
 * the isolated test database, where permission rows can be controlled (see
 * tests/unit/RbacTest.php).
 */
function check_rbac(): void
{
    $ADMIN_ONLY = ['/roles', '/system-users', '/firewall', '/fail2ban', '/certificates', '/asterisk-settings', '/phones', '/phone-keys'];
    $n = 0;

    // Told apart purely by size. Measured live (2026-09-01): a blocked request
    // prints the standard denial page → 746 bytes; an allowed render is
    // 50–207 KB. Searching the text for "yetki"/"permission" DOES NOT WORK:
    // /roles and /system-users are permission-matrix pages, their content
    // naturally contains those words and raised false alarms.
    // The separating power of the thresholds was verified live: allowed vs.
    // blocked differ ~67×, the gap is very wide.
    //
    // SIDE FINDING (2026-09-01): EVEN with static::requireRole('admin')
    // removed from the controller the page does not open — a second layer
    // prints 403 (defence in depth). Showing in production that this check
    // "can turn red" would need breaking both layers at once; not done.
    $IZINLI_MIN    = 10000;
    $ENGELLI_MAKS  = 5000;

    foreach ($ADMIN_ONLY as $path) {
        // Canary: admin must be able to see this page. Otherwise the real
        // check below would stay green for no reason.
        $asAdmin = render_route($path, 'tr', 'admin');
        $n++;
        $adminBytes = $asAdmin['bytes'] ?? 0;
        if ($adminBytes < $IZINLI_MIN) {
            fail('rbac', $path, "KANARYA: admin bile bu sayfayi render edemedi ({$adminBytes} bayt)"
                . ' — bu kontrol anlamsiz gecerdi');
            continue;
        }

        // The real check: the low-privilege role must not see it.
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

// ---------------------------------------------------------------- EXPORTS
/**
 * Every page with the PDF/Excel buttons (templates/export_buttons.php) must
 * answer ?export=pdf|xlsx with a real file: the view and the controller are
 * checked against each other, then each export is downloaded.
 */
function check_exports(): void
{
    $views = [];
    foreach (glob(SMOKE_ROOT . '/templates/views/*/index.php') as $f) {
        if (str_contains((string) file_get_contents($f), 'export_buttons.php')) {
            $views[] = basename(dirname($f)) . '/index';
        }
    }
    $ROUTES = require SMOKE_ROOT . '/src/routes.php';
    $n = 0;
    foreach ($views as $view) {
        $route = null;
        foreach ($ROUTES as $path => $r) {
            if (!is_array($r)) continue;
            $src = (string) @file_get_contents(SMOKE_ROOT . '/src/controllers/' . $r['controller'] . '.php');
            if (str_contains($src, "renderPage('{$view}'")) {
                $route = $path;
                if (!str_contains($src, 'ReportExport::send(')) {
                    fail('export', $view, 'the page shows export buttons but ' . $r['controller'] . ' does not handle ?export=');
                    continue 2;
                }
                break;
            }
        }
        if ($route === null) {
            fail('export', $view, 'no route renders this view');
            continue;
        }
        foreach (['pdf' => '%PDF-', 'xlsx' => "PK\x03\x04"] as $format => $magic) {
            $out = tempnam(sys_get_temp_dir(), 'smoke');
            $bin = tempnam(sys_get_temp_dir(), 'smoke');
            $cmd = 'SMOKE_QUERY=' . escapeshellarg('export=' . $format . '&date_range=month') . ' php '
                . escapeshellarg(SMOKE_ROOT . '/bin/_smoke_render.php') . ' ' . escapeshellarg($route) . ' tr admin '
                . escapeshellarg($out) . ' > ' . escapeshellarg($bin) . ' 2>/dev/null';
            exec($cmd);
            $body = (string) file_get_contents($bin);
            $d = json_decode((string) file_get_contents($out), true) ?: [];
            @unlink($out);
            @unlink($bin);
            $n++;
            if (($d['status'] ?? '') === 'fatal') {
                fail('export', "{$route} {$format}", $d['error'] ?? 'fatal');
            } elseif (!str_starts_with($body, $magic)) {
                fail('export', "{$route} {$format}", 'not a ' . $format . ' file (' . strlen($body) . ' bytes)');
            }
            foreach (($d['php_errors'] ?? []) as $e) {
                fail('export', "{$route} {$format}", $e);
            }
        }
    }
    group_done('export', $n);
}
if ($only === null || $only === 'exports') { check_exports(); }

// ----------------------------------------------------------- CONVENTIONS
/**
 * Returns the file WITHOUT comments (string literals are kept).
 *
 * Why: a plain grep took an innocent sentence inside a comment for a rule
 * violation — the "syncAllTimeConditions() already does all of..." note in
 * TimeConditionService raised a false alarm (2026-09-01). Convention rules
 * must check the CODE, not the comments.
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
 * Grep-based architecture rule checks. Every rule matches a bug that REALLY
 * happened in this project — none of them is theoretical.
 * All rules run on the comment-free source.
 */
function check_conventions(): void
{
    $n = 0;
    $rel = function (string $p): string { return str_replace(SMOKE_ROOT . '/', '', $p); };

    // 1) The service layer must not call sync directly — that bypasses the
    //    deferred reload ("Apply") system. Incident: PBXHelper::toggleStatus()
    //    had been skipped in the rollout and kept reloading instantly (2026-08-24).
    $n++;
    foreach (glob(SMOKE_ROOT . '/src/services/*.php') as $f) {
        $src = php_code_without_comments($f);
        if (preg_match('/(?<![\w:>$])(syncAll[A-Za-z]+|sync[A-Za-z]+Dialplan|syncEverything)\s*\(/', $src, $m)) {
            fail('konvansiyon', $rel($f),
                "dogrudan {$m[1]}() cagiriyor — markPendingSync() kullanilmali");
        }
    }

    // 2) Code that writes outside the repo must use FileHelper: atomic write +
    //    the ownership trap. Incident: Fail2banService SILENTLY failed to write
    //    with a plain file_put_contents while the user saw "success" (2026-08-31).
    $n++;
    foreach (array_merge(glob(SMOKE_ROOT . '/src/services/*.php'),
                         glob(SMOKE_ROOT . '/src/sync/*.php')) as $f) {
        $src = php_code_without_comments($f);
        if (preg_match('/file_put_contents\s*\(\s*[^,)]*(\/etc\/|OVERRIDE_FILE|ASTERISK_PBX_DIR)/', $src)) {
            fail('konvansiyon', $rel($f),
                'repo disina duz file_put_contents ile yaziyor — FileHelper::writeFile() kullanilmali');
        }
    }

    // 3) Generated file headers must not carry the old corporate brand.
    //    Incident: the de-branding had skipped the generators; the live files
    //    were fixed by hand but would revert on the first sync (2026-09-01).
    $n++;
    foreach (array_merge(glob(SMOKE_ROOT . '/src/sync/*.php'),
                         glob(SMOKE_ROOT . '/src/services/*.php')) as $f) {
        $src = php_code_without_comments($f);
        if (preg_match('/(KB[UÜ] Portal|Karab[uü]k)/u', $src, $m)) {
            fail('konvansiyon', $rel($f), "uretilen ciktida eski marka referansi: '{$m[1]}'");
        }
    }

    // 4) Every controller must call an access check.
    //    EXCEPTION: the authentication flow itself needs NO session — the
    //    login, logout and password reset pages are anonymous by nature, so
    //    looking for an access check there would be wrong.
    $ANONIM_CONTROLLER = [
        'LoginController.php',
        'TwoFactorLoginController.php',
        'LogoutController.php',
        'ForceResetController.php',
        'ResetPasswordController.php',
        'ForgotPasswordController.php',
        'MobileLoginController.php',
        'GoogleAuthController.php',
        // Desk phones fetch their configuration without a session; the token,
        // allowed networks and rate limit guard it (PhoneProvisionService).
        'ProvisionController.php',
    ];
    $n++;
    foreach (glob(SMOKE_ROOT . '/src/controllers/*.php') as $f) {
        if (in_array(basename($f), $ANONIM_CONTROLLER, true)) { continue; }
        $src = php_code_without_comments($f);
        if (!preg_match('/(requireRole|requireLogin|requireApiLogin)\s*\(/', $src)) {
            fail('konvansiyon', $rel($f), 'hicbir yetki kontrolu cagirmiyor');
        }
    }

    // 5) Every action file under api/cc_actions must carry the dispatcher
    //    guard, otherwise it can be called directly.
    $n++;
    foreach (glob(SMOKE_ROOT . '/api/cc_actions/*.php') as $f) {
        if (strpos(file_get_contents($f), 'CC_DISPATCH_ACTIVE') === false) {
            fail('konvansiyon', $rel($f), 'CC_DISPATCH_ACTIVE guard yok — dogrudan cagrilabilir');
        }
    }

    // 6) The INTERNAL_NUMBER_SOURCES manifest must match the schema.
    //    If a row is added to the manifest and the migration is forgotten,
    //    internalNumberEntries() fails in production with "Unknown column" —
    //    five pages stop opening at once.
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

    // 7) Files under api/ must not write dirname(__DIR__, 2) . '/sync/...'.
    //    There is NO /var/www/html/sync/ directory (the real path is src/sync/);
    //    that broken require_once throws a catchable Error and the catch blocks
    //    swallow it silently. Exactly this happened in api/mobile/features.php
    //    and the mobile DND/forwarding setting never reached the dialplan for
    //    months (2026-09-05).
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
 * Syntax and language-file integrity checks.
 */
function check_lint(): void
{
    $n = 0;

    // 1) PHP syntax — every file except vendor.
    //    One process per file is needed (php -l takes a single file); 196
    //    files take 8.2 s serially, 4.0 s with xargs -P8 (measured 2026-09-01).
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

    // `php -l` returns 255 on a parse error and xargs, on seeing 255, stops
    // WITHOUT SCANNING THE REMAINING FILES ("exited with status 255; aborting")
    // — one broken file would mask every other error (seen in a hook test on
    // 2026-09-01). sh -c ... || true resets each call's exit code.
    $o = [];
    exec('xargs -P8 -n1 sh -c \'php -l "$0" 2>&1 || true\' < '
        . escapeshellarg($listFile) . ' 2>&1', $o);
    @unlink($listFile);
    foreach ($o as $line) {
        if ($line === '' || strpos($line, 'No syntax errors') === 0) { continue; }
        fail('lint', 'php -l', trim($line));
    }

    // 2) Language key symmetry — reuses the existing script.
    $n++;
    $o2 = [];
    exec('php ' . escapeshellarg(SMOKE_ROOT . '/bin/lint_lang.php') . ' 2>&1', $o2, $ret2);
    if ($ret2 !== 0) {
        fail('lint', 'lint_lang.php', trim(implode(' | ', array_slice($o2, -6))));
    }

    // 3) JS syntax (vendor/min excluded).
    //
    //    `node --check` is a real parser. This used to be only a brace count,
    //    which MISSES most syntax errors (broken JS can have balanced
    //    braces). Because of that, an edit to header_phone.js on 2026-09-15
    //    was committed without going through a parser — node was not
    //    installed.
    //
    //    Without node the old rough count is kept as a fallback: a weak check
    //    is better than none.
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
    echo "\nCLEAN — all checks passed.\n";
    exit(0);
}

echo "\n" . count($FAILS) . " HATA:\n";
foreach ($FAILS as $f) {
    printf("  [%s] %s\n      %s\n", $f['group'], $f['what'], $f['detail']);
}
exit(1);
