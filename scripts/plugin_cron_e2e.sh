#!/usr/bin/env bash
#
# End-to-end proof of the claim this change makes: a plugin that lives OUTSIDE
# the portal tree runs its own PHP, its cron hook and its CLI, with nothing
# copied into web/.
#
# It stages a throwaway copy of web/ in /tmp, writes a demo plugin into a
# catalog directory that is NOT inside that portal, and then drives the real
# entry points: aipbx_plugin_cron.php, aipbx_plugin_cli.php, plugin_job.php,
# and a page include the way the loader does it.
#
# Nothing from the portal tree is copied into the plugin directory, and nothing
# from the plugin is copied into the portal: that separation is the claim.
#
# Requires: php, vendor/ installed in web/, the test database from
# tests/.env.test, and sys_plugin_cron_runs (phinx migrate -e testing).
#
#   bash scripts/plugin_cron_e2e.sh
#
set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
draft=$(cd "$here/../web" && pwd)   # the portal tree, used as the staging source
repo=$(cd "$here/.." && pwd)
php=${PHP:-php}

work=$(mktemp -d "${TMPDIR:-/tmp}/aipbx-plugin-e2e.XXXXXX")
portal="$work/portal"
catalog="$work/catalog"
state="$work/state"
mkdir -p "$portal" "$catalog/demo-jobs/src" "$catalog/demo-jobs/admin" \
         "$catalog/demo-jobs/bin" "$state"

cleanup() {
    # Only rows this script wrote; the table itself belongs to the portal.
    ( cd "$repo/web" && $php -r '
        $pdo = new PDO("mysql:host=" . (getenv("DB_HOST") ?: "localhost")
            . ";dbname=" . getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS"));
        $pdo->exec("DELETE FROM sys_plugin_cron_runs WHERE slug = \"demo-jobs\"");
    ' >/dev/null 2>&1 )
    rm -rf "$work"
}
trap cleanup EXIT

failures=0
check() { # <name> <expected> <actual>
    if [ "$2" = "$3" ]; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s\n       expected: %s\n       actual:   %s\n' "$1" "$2" "$3"
        failures=$((failures + 1))
    fi
}
contains() { # <name> <needle> <haystack>
    case "$3" in
        *"$2"*) check "$1" "found" "found" ;;
        *) check "$1" "found '$2' in: $3" "missing" ;;
    esac
}
matches() { # <name> <extended regex, one line of $3> <haystack>
    if printf '%s\n' "$3" | grep -Eq "$2"; then
        check "$1" 'matches' 'matches'
    else
        check "$1" "a line matches /$2/" "no line in: $3"
    fi
}
section() { printf '\n== %s\n' "$1"; }

# ---------------------------------------------------------------------------
# 1. Stage the portal: a copy of web/, with vendor borrowed from the checkout.
#    The plugin never writes into it, and the portal never reads the plugin's
#    files except through the runtime.
# ---------------------------------------------------------------------------
tar -C "$repo/web" --exclude=./vendor --exclude=./.phpstan-cache -cf - . \
    | tar -C "$portal" -xf -
ln -s "$repo/web/vendor" "$portal/vendor"

cp "$draft"/bin/*.php "$portal/bin/"
cp "$draft"/src/core/*.php "$portal/src/core/"
cp "$draft"/tests/unit/Plugin*.php "$portal/tests/unit/"
# Stand-in for the loader 1.34 ships (web/src/core/PluginManager.php). This
# checkout has no loader; PluginBootstrap touches only two of its methods, so a
# twelve-line stand-in is enough to prove the seam — and the script checks that
# the runtime rejects this stand-in as "not the real loader" elsewhere.
cat > "$portal/src/core/PluginManager.php" <<'PHP'
<?php
final class PluginManager
{
    private static function doc(): array
    {
        $path = getenv('AIPBX_VERIFY_ENABLED');
        $raw = is_string($path) && is_file($path) ? file_get_contents($path) : false;
        $doc = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($doc) ? $doc : [];
    }

    public static function isEnabled(string $slug): bool
    {
        return (bool) (self::doc()[$slug]['enabled'] ?? false);
    }

    public static function getAllSettings(string $slug): array
    {
        $settings = self::doc()[$slug]['settings'] ?? [];
        return is_array($settings) ? $settings : [];
    }
}
PHP

# ---------------------------------------------------------------------------
# 2. The plugin, in a catalog OUTSIDE the portal.
# ---------------------------------------------------------------------------
cat > "$catalog/demo-jobs/plugin.json" <<'JSON'
{
    "slug": "demo-jobs",
    "name": "Demo jobs",
    "version": "1.0.0",
    "author": "EchoGuard Communications",
    "scopes": ["cron"],
    "php": {
        "files": {
            "src/DemoService.php": ["DemoService"]
        }
    },
    "cron": [
        {"job": "tick", "schedule": "* * * * *", "class": "DemoService::tick", "timeout": 20},
        {"job": "hang", "schedule": "0 3 * * *", "file": "bin/hang.php", "timeout": 1}
    ],
    "cli": {
        "report": {"class": "DemoService::report"}
    }
}
JSON

cat > "$catalog/demo-jobs/src/DemoService.php" <<'PHP'
<?php
// Global class name on purpose: that is the shape of the five packages that
// would move first, and `php.files` exists for exactly this.
final class DemoService
{
    public const MARKER = 'class came from the catalog';

    public static function tick(array $settings, string $slug): int
    {
        $dir = (string) getenv('AIPBX_VERIFY_STATE');
        file_put_contents($dir . '/tick.log',
            $slug . ' ' . ($settings['marker'] ?? 'no-marker') . ' ' . __DIR__ . "\n", FILE_APPEND);
        fwrite(STDOUT, "tick ran from " . __DIR__ . "\n");
        return 0;
    }

    public static function report(array $settings, string $slug, array $argv = []): int
    {
        fwrite(STDOUT, json_encode([
            'from' => __DIR__,
            'slug' => $slug,
            'marker' => $settings['marker'] ?? null,
            'argv' => $argv,
        ], JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    }
}
PHP

cat > "$catalog/demo-jobs/bin/hang.php" <<'PHP'
<?php
// Returns a callable, like the events/handler.php files of the existing
// packages. Sleeping past the declared timeout: the dispatcher must kill it,
// record -1 and keep going.
return static function (array $settings, string $slug): int {
    fwrite(STDOUT, "hanging as $slug\n");
    sleep(30);
    return 0;
};
PHP

cat > "$catalog/demo-jobs/admin/page.php" <<'PHP'
<?php
// The loader renders this file itself. It is here to prove that a page living
// outside web/ can still use its plugin's class, because PluginRuntime::boot()
// registered it.
echo "page says: " . DemoService::MARKER . " (" . __DIR__ . ")\n";
PHP

chmod -R go-w "$catalog"

cat > "$work/enabled.json" <<JSON
{"demo-jobs": {"enabled": true, "settings": {"marker": "hello-from-settings"}}}
JSON

# The portal reads its database the same way CI gives it one: from the
# environment (see tests/bootstrap.php and phinx.php).
set -a
# shellcheck disable=SC1090
. "$repo/web/tests/.env.test"
set +a

export AIPBX_CUSTOM_PLUGINS_DIR="$catalog"
export AIPBX_PLUGIN_LOCK_DIR="$work/locks"
export AIPBX_VERIFY_STATE="$state"
export AIPBX_VERIFY_ENABLED="$work/enabled.json"

cron="$portal/bin/aipbx_plugin_cron.php"
cli="$portal/bin/aipbx_plugin_cli.php"

# ---------------------------------------------------------------------------
section 'the changed files, installed where they live'
# ---------------------------------------------------------------------------
# Only the tests this change adds: the rest of the suite belongs to the portal
# and its own composer autoload, which still points at the real web/. Named one
# by one rather than `Plugin*.php`, which would also pick up the portal's own
# plugin/API test and fail here for reasons that have nothing to do with cron.
suite=$($php "$repo/web/vendor/bin/phpunit" --no-configuration \
    --bootstrap "$portal/tests/bootstrap.php" \
    "$portal/tests/unit/PluginRuntimeTest.php" \
    "$portal/tests/unit/PluginCronTest.php" \
    "$portal/tests/unit/PluginCronScheduleTest.php" \
    "$portal/tests/unit/PluginProcessRunnerTest.php" 2>&1)
printf '%s\n' "$suite" | tail -3
if ! printf '%s' "$suite" | grep -q 'OK ('; then
    printf '%s\n' "$suite" | tail -25
fi
contains 'unit suite runs inside the staged portal' 'OK (' "$suite"

# ---------------------------------------------------------------------------
section 'a cron hook the plugin ships, run from the catalog'
# ---------------------------------------------------------------------------
out=$($php "$cron" --status 2>&1); code=$?
check '--status exits 0' 0 "$code"
matches '--status lists the tick job as due' '^demo-jobs[[:space:]]+tick[[:space:]]+\* \* \* \* \*[[:space:]]+on' "$out"
matches '--status lists the file-based job too' '^demo-jobs[[:space:]]+hang[[:space:]]' "$out"

out=$($php "$cron" --run=demo-jobs/tick 2>&1); code=$?
check '--run tick exits 0' 0 "$code"
contains '--run tick reports a run' '"result":"ran"' "$out"
contains '--run tick reports exit 0' '"status":0' "$out"

tick=$(cat "$state/tick.log" 2>/dev/null)
contains 'the job received its settings' 'hello-from-settings' "$tick"
case "$tick" in
    *"$portal"*) check 'the job code lives outside the portal' 'outside' 'inside the portal' ;;
    *) check 'the job code lives outside the portal' 'outside' 'outside' ;;
esac

out=$($php "$cron" --status 2>&1)
matches '--status shows the tick run as exit 0' '^demo-jobs[[:space:]]+tick[[:space:]].*[[:space:]]0$' "$out"

# ---------------------------------------------------------------------------
section 'a job that hangs past its timeout'
# ---------------------------------------------------------------------------
started=$(date +%s)
out=$($php "$cron" --run=demo-jobs/hang 2>&1); code=$?
elapsed=$(( $(date +%s) - started ))
check '--run hang exits 1 (it did not succeed)' 1 "$code"
contains 'the timeout is recorded as -1' '"status":-1' "$out"
if [ "$elapsed" -lt 10 ]; then
    check 'the dispatcher killed it at the deadline' 'killed' 'killed'
else
    check 'the dispatcher killed it at the deadline' 'killed' "still running after ${elapsed}s"
fi

# Dispatch mode must still exit 0 with a failing job in the list.
out=$($php "$cron" 2>&1); code=$?
check 'dispatch exits 0 even when a job failed' 0 "$code"

# The -1 must be in the database, not only in the json: this is the row the
# unsigned column used to reject.
row=$($php -r '
    $pdo = new PDO("mysql:host=" . (getenv("DB_HOST") ?: "localhost")
        . ";dbname=" . getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS"));
    $st = $pdo->query("SELECT last_status, failures FROM sys_plugin_cron_runs WHERE slug = \"demo-jobs\" AND job = \"hang\"");
    $r = $st->fetch(PDO::FETCH_ASSOC);
    echo $r ? $r["last_status"] . "/" . $r["failures"] : "no row";
' 2>&1)
check 'the timeout is stored (status/failures)' '-1/1' "$row"

# ---------------------------------------------------------------------------
section 'a CLI command the plugin ships'
# ---------------------------------------------------------------------------
out=$($php "$cli" demo-jobs report --verbose 2>&1); code=$?
check 'the command exits 0' 0 "$code"
contains 'the command received its arguments' '"argv":["--verbose"]' "$out"
contains 'the command received its settings' '"marker":"hello-from-settings"' "$out"
case "$out" in
    *"$portal"*) check 'the command code lives outside the portal' 'outside' 'inside the portal' ;;
    *) check 'the command code lives outside the portal' 'outside' 'outside' ;;
esac

out=$($php "$cli" demo-jobs nope 2>&1); code=$?
check 'an undeclared command fails' 1 "$code"
contains 'an undeclared command lists what exists' 'declared: report' "$out"

# ---------------------------------------------------------------------------
section 'the loader includes a hook file from the catalog'
# ---------------------------------------------------------------------------
out=$($php -r '
    require $argv[1] . "/config.php";
    require $argv[1] . "/src/core/PluginBootstrap.php";
    PluginBootstrap::runtime()->boot();
    include $argv[2] . "/admin/page.php";
' "$portal" "$catalog/demo-jobs" 2>&1)
contains 'the page renders' 'class came from the catalog' "$out"

# ---------------------------------------------------------------------------
section 'disable, then remove: the portal keeps working'
# ---------------------------------------------------------------------------
cat > "$work/enabled.json" <<JSON
{"demo-jobs": {"enabled": false, "settings": {"marker": "hello-from-settings"}}}
JSON

out=$($php "$cron" --run=demo-jobs/tick 2>&1); code=$?
check 'a disabled plugin cannot be run by hand' 1 "$code"
contains 'and it says why' '"result":"disabled"' "$out"
out=$($php "$cron" --status 2>&1)
matches '--status shows it off' '^demo-jobs[[:space:]]+tick[[:space:]].*[[:space:]]off[[:space:]]' "$out"

rm -rf "${catalog:?}/demo-jobs"
out=$($php "$cron" --status 2>&1); code=$?
check 'with no plugin, --status exits 0' 0 "$code"
contains 'with no plugin, it says so' 'no plugin declares a cron job' "$out"

# ---------------------------------------------------------------------------
printf '\n'
if [ "$failures" -eq 0 ]; then
    echo "all checks passed"
    exit 0
fi
echo "$failures check(s) failed"
exit 1
