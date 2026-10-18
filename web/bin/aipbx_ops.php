#!/usr/bin/env php
<?php
/**
 * AiPBX bounded op executor — connector v0.1.
 *
 * Run as:  php aipbx_ops.php <op> '<json args>'
 * Exit 0 = ok. stdout is a single JSON object. Same allowlist the cloud
 * enforces, re-checked HERE as well (defense in depth: the cloud can be
 * wrong or out of date, and a node must never be talked into more than it
 * agreed to run).
 *
 * No free-form shell. No schema writes. Every op maps to ONE fixed call into
 * AiPBX's own code — AsteriskHelper / applyPendingSync() — so a cloud command
 * rides the same code path, the same config rollback and the same audit log
 * as an admin pressing the button in the portal.
 *
 * This is the AiPBX counterpart of EchoGuard's echoguard_ops.php. It does NOT
 * shell out to fwconsole: AiPBX has no FreePBX module layer, and pretending
 * otherwise is exactly how a node reports success for work it never did.
 */

error_reporting(E_ALL);

/** stdout is ONE JSON object; exit code carries ok/fail. Never returns. */
function out($data, $code = 0): never {
    fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    exit($code);
}

/** Bounded run WITHOUT a shell (argv array) — caller-supplied values never reach a shell. */
function runArgv(array $argv, $timeout = 30) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($argv, $descriptors, $pipes, null, null, ['timeout' => $timeout]);
    if (!is_resource($proc)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

/**
 * Ops this node will ever run. Names match the cloud's ALLOWED_OPS on purpose:
 * one vocabulary on both sides, so an op can never mean two different things.
 */
const AIPBX_OPS = ['snapshot', 'diagnose', 'reload', 'module_reload'];

/**
 * module_reload map — AiPBX-native module names ONLY.
 *
 * The cloud's allowlist is FreePBX-shaped (core, pjsip, sipsettings, ivr,
 * timeconditions, ringgroups, queues, miscapps, customappctx, confirm,
 * findmefollow). AiPBX has no FreePBX GUI module layer: `ivr`/`queues`/
 * `timeconditions` are DATABASE entities here, and the way to make an edit to
 * them take effect is applyPendingSync() (the `reload` op), not a module
 * reload. Of the cloud's list only `core` and `pjsip` mean anything on
 * AiPBX — the rest are refused with `unsupported_module` rather than being
 * silently translated into a different action than the operator asked for.
 */
const AIPBX_MODULES = [
    'core'  => 'core reload',
    'pjsip' => 'pjsip reload',
];

/** Cloud module names that look valid but have no AiPBX meaning — refused, with the reason. */
const FREEPBX_ONLY_MODULES = [
    'sipsettings', 'ivr', 'timeconditions', 'ringgroups', 'queues',
    'miscapps', 'customappctx', 'confirm', 'findmefollow',
];

$op = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true);
if (!is_array($args)) {
    $args = [];
}

if (!in_array($op, AIPBX_OPS, true)) {
    out(['error' => 'unknown_op', 'allowed' => AIPBX_OPS], 64);
}

// AiPBX's own bootstrap + helpers: getDB(), t(), writeAuditLog(), AsteriskHelper.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/audit_log.php';
require_once dirname(__DIR__) . '/src/asterisk_helper.php';
require_once dirname(__DIR__) . '/src/asterisk_sync.php';

/** The AiPBX version this node is running (repo-root VERSION file). */
function aipbx_version() {
    $path = dirname(__DIR__, 2) . '/VERSION';
    $v = is_readable($path) ? trim((string) file_get_contents($path)) : '';
    return $v !== '' ? $v : 'unknown';
}

/** One `asterisk -rx` line via AiPBX's helper (which already knows -rx always exits 0). */
function cli_line($command) {
    $res = AsteriskHelper::execCLI($command);
    $out = trim((string) ($res['output'] ?? ''));
    return $out === '' ? null : explode("\n", $out)[0];
}

/** Disk usage rows for the mounts an operator cares about. */
function disk_rows(array $mounts = ['/', '/var']) {
    $res = runArgv(array_merge(['df', '-P'], $mounts), 10);
    $rows = [];
    $lines = explode("\n", trim((string) $res['stdout']));
    array_shift($lines); // header
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 6) {
            $rows[] = ['mount' => $parts[5], 'used_pct' => (int) rtrim($parts[4], '%')];
        }
    }
    return $rows;
}

/**
 * Results of AiPBX's OWN health checks (Admin → Health checks), collected
 * through the existing CLI so the cloud sees exactly what the portal shows.
 * `--all` also means no alarm e-mails are sent from a remote-triggered run.
 */
function health_results($timeout = 90) {
    $script = __DIR__ . '/aipbx_health.php';
    if (!is_readable($script)) {
        return ['available' => false, 'reason' => 'aipbx_health.php not found'];
    }
    $res = runArgv(['php', $script, '--all', '--no-mail'], $timeout);
    $results = [];
    foreach (explode("\n", (string) $res['stdout']) as $line) {
        if (preg_match('/^\s*(PASS|FAIL|WARN|SKIP|OK|OFF)\s+(\S+)\s*(.*)$/i', $line, $m)) {
            $results[$m[2]] = ['status' => strtolower($m[1]), 'message' => trim($m[3])];
        }
    }
    $failed = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'fail') {
            $failed++;
        }
    }
    return [
        'available' => true,
        'exit_code' => $res['code'],
        'failed' => $failed,
        'checks' => $results,
    ];
}

switch ($op) {

    case 'snapshot': {
        $disk = disk_rows();
        out([
            'platform' => 'aipbx',
            'aipbx_version' => aipbx_version(),
            'asterisk_version' => cli_line('core show version'),
            'active_channels' => cli_line('core show channels count'),
            'uptime' => cli_line('core show uptime'),
            'disk' => $disk,
            'pending_sync' => getPendingSyncCount(),
            'taken_at' => gmdate('c'),
        ]);
    }

    case 'diagnose': {
        $statuses = AsteriskHelper::getPJSIPStatuses();
        $buckets = ['idle' => 0, 'busy' => 0, 'down' => 0, 'unknown' => 0];
        $problems = [];
        foreach ($statuses as $endpoint => $raw) {
            $class = AsteriskHelper::classifyStatus($raw) ?? 'unknown';
            $buckets[$class] = ($buckets[$class] ?? 0) + 1;
            if ($class === 'down' || $class === 'unknown') {
                $problems[] = ['endpoint' => $endpoint, 'status' => (string) $raw];
            }
        }

        $registrations = [];
        $reg = AsteriskHelper::execCLI('pjsip show registrations');
        foreach (explode("\n", (string) ($reg['output'] ?? '')) as $line) {
            if (preg_match('/Registered|Unregistered|Rejected/i', $line)) {
                $registrations[] = trim($line);
            }
        }

        $health = health_results();
        $pending = getPendingSyncCount();

        $hints = [];
        if ($problems) {
            $hints[] = count($problems) . ' SIP endpoint(s) are down or in an unrecognised state.';
        }
        foreach ($registrations as $line) {
            if (preg_match('/Rejected|Unregistered/i', $line)) {
                $hints[] = 'A trunk registration is rejected/unregistered — check credentials and reachability.';
                break;
            }
        }
        if ($pending > 0) {
            $hints[] = "{$pending} configuration change(s) are waiting for Apply (/pending-sync).";
        }
        if (!empty($health['available'])) {
            foreach ($health['checks'] as $key => $r) {
                if ($r['status'] === 'fail') {
                    $hints[] = "Health check {$key} failed: {$r['message']}";
                }
            }
        }
        foreach (disk_rows() as $row) {
            if ($row['used_pct'] >= 90) {
                $hints[] = "{$row['mount']} is {$row['used_pct']}% full.";
            }
        }

        out([
            'platform' => 'aipbx',
            'endpoints' => ['total' => count($statuses), 'by_state' => $buckets, 'problems' => $problems],
            'registrations' => $registrations,
            'health' => $health,
            'pending_sync' => $pending,
            'hints' => $hints,
            'taken_at' => gmdate('c'),
        ]);
    }

    case 'reload': {
        // AiPBX's own review-then-apply path: regenerate configs for every
        // pending domain, reload Asterisk per domain, roll back a config that
        // fails to load, and audit-log the whole thing.
        $before = getPendingSyncCount();
        try {
            $results = applyPendingSync(null);
        } catch (\Throwable $e) {
            out(['error' => 'apply_failed', 'detail' => $e->getMessage()], 70);
        }

        $failed = [];
        $applied = [];
        foreach ($results as $domain => $r) {
            if (!empty($r['success'])) {
                $applied[] = $domain;
            } else {
                $failed[$domain] = $r['error'] ?? 'unknown error';
            }
        }
        $payload = [
            'platform' => 'aipbx',
            'pending_before' => $before,
            'pending_after' => getPendingSyncCount(),
            'applied' => $applied,
            'failed' => $failed,
            'nothing_pending' => $before === 0,
            'taken_at' => gmdate('c'),
        ];
        if ($failed) {
            out($payload + ['error' => 'apply_partial_failure'], 70);
        }
        out($payload);
    }

    case 'module_reload': {
        $module = isset($args['module']) ? (string) $args['module'] : '';
        if ($module === '') {
            out(['error' => 'module_required', 'supported' => array_keys(AIPBX_MODULES)], 64);
        }
        if (!array_key_exists($module, AIPBX_MODULES)) {
            $reason = in_array($module, FREEPBX_ONLY_MODULES, true)
                ? "This is a FreePBX GUI module; AiPBX has no module of that name. Its equivalent entities are applied through the 'reload' op (applyPendingSync)."
                : 'Unknown module.';
            out([
                'error' => 'unsupported_module',
                'module' => $module,
                'reason' => $reason,
                'supported' => array_keys(AIPBX_MODULES),
            ], 64);
        }

        $command = AIPBX_MODULES[$module];
        $res = AsteriskHelper::execCLI($command);
        writeAuditLog('connector', 'module_reload', $module, $module, 'reload', null);
        out([
            'platform' => 'aipbx',
            'module' => $module,
            'command' => $command,
            'success' => !empty($res['success']),
            'output' => (string) ($res['output'] ?? ''),
            'taken_at' => gmdate('c'),
        ], !empty($res['success']) ? 0 : 70);
    }
}

// Not reachable: $op is validated against AIPBX_OPS above and every op has a
// case. The switch is the whole interface, on purpose.
