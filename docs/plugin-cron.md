# Plugin cron: scheduled jobs from the catalog

The loader reads `plugin.json`, stores and renders settings, applies `schema.sql`,
and includes a plugin's hook *files* (`admin/page.php`, `events/handler.php`,
`sync/*.php`). It also runs `worker/tick.php` — every two seconds, from
`web/bin/webhook_worker.php` — for each plugin whose row has `worker_enabled`.

What it does not do is give a plugin a **schedule**: the tick is unscheduled, runs
inside one long-lived daemon process, and leaves no record of a single run
(only a per-plugin error streak and, after five consecutive failures, Safe Mode).
It also does not autoload a plugin's library classes, so a hook that wants a
service class still `require_once`s it by path.

This adds three optional manifest keys and the runtime that honours them, so a
plugin's PHP, its scheduled jobs and its commands all run **from the plugin's own
directory**, with nothing written into the portal tree.

## Manifest

```json
{
    "slug": "call-quality",
    "php": {
        "files": {
            "web/src/services/CallQualityService.php": ["CallQualityService"]
        }
    },
    "cron": [
        {"job": "collect", "schedule": "*/5 * * * *", "class": "CallQualityService::tick",
         "timeout": 120}
    ],
    "cli": {
        "report": {"class": "CallQualityService::report"}
    }
}
```

| key | shape | notes |
|---|---|---|
| `php.files` | `{"<file>": ["<Class>", …]}` | global class names — the migration path for existing packages. The class list is checked *before* the file is included, so a collision is a recorded problem, not a fatal redeclare. |
| `php.namespace` + `php.path` | `"Vendor\\Pkg\\"` + `"src/"` | PSR-4. One autoloader per plugin; a prefix may be claimed once, and nothing is required until a class is asked for. |
| `cron[]` | `job`, `schedule`, `class` \| `file`, `timeout` | `job` is a-z, 0-9, `-`, `_` (≤ 32). `class` is `Class::method`, called as `method(array $settings, string $slug)`. `file` must return a callable. Timeout 1–3600 s, default 60. |
| `cli{}` | command → `class` \| `file` | Called with `(array $settings, string $slug, array $argv)`. |

All three are optional, and every path resolves inside the plugin's own
directory. A manifest with a bad `php` or `cron` section is not half-booted: none
of its PHP loads and none of its jobs run. An unknown key is ignored, so these
manifests stay installable on a portal that does not have this change.

## How a job runs

One crontab line, as the portal user:

```
* * * * * www-data /var/www/aipbx/web/bin/aipbx_plugin_cron.php
```

Per tick: ask the loader which plugins are enabled, read their `cron` lists, run
what is due.

- **Never twice at once.** An `flock` per slug+job on the host (the kernel
  releases it when the process dies, which a table row cannot promise), plus an
  "already started this minute" check so two dispatchers agree.
- **A bad job cannot take the dispatcher down.** Each job runs in its own PHP
  process (`bin/plugin_job.php <slug> <job>`) with a SIGKILL deadline; `exit`,
  fatals, `memory_limit` and blocked sockets stay in the child. The web tick has
  no such boundary: an exception is caught, a fatal is not.
- **It is visible.** `sys_plugin_cron_runs` holds one row per slug+job (last
  start, last finish, status, duration, tail of output, run and failure counts),
  and `aipbx_plugin_cron.php --status` prints it.
- **Missed minutes are not backfilled.** Ten catch-up runs of a collector are
  worse than one.

Manual runs (`--run=slug/job`) ignore the schedule but still take the lock, and
still refuse a plugin the loader has not enabled — a stored manifest is not
permission to execute code.

## Commands

```
aipbx_plugin_cron.php --status          # plugin, job, schedule, on/off, last run, exit status
aipbx_plugin_cron.php --list            # manifests the runtime can see
aipbx_plugin_cron.php --run=<slug>/<job>  # the manual path
aipbx_plugin_cli.php <plugin> <command> [args…]
```

`aipbx_plugin_cli.php` replaces the per-plugin scripts that would otherwise be
copied into `web/bin` and symlinked into `/usr/local/bin`: the command, its class
and its settings all live in the catalog.

## Relation to `hooks.worker`

There are two ways to fit this into what the loader already has, and this branch
ships the first while leaving the second possible without a rewrite.

**A. `cron[]` beside the worker hook (what this does).** The declared jobs are
run by a separate dispatcher, on their own schedule, in their own processes. A
plugin may keep `worker/tick.php` for "do something on every poll" and add
`cron[]` for "do this every five minutes", and neither knows about the other.

- Keeps the 2-second daemon's job unchanged, so nothing that works today can
  break by installing this.
- Gives a schedule, a lock, a timeout and a run record to the jobs that need
  them; a 2-second tick cannot express "every five minutes" without the plugin
  tracking the clock itself, which is what `auto-dialer` does today.
- Costs a second crontab line, a second table and a second code path to audit.

**B. Scheduling on top of `tick.php`.** `cron[]` would not declare how to run a
job at all; it would declare when `worker/tick.php` may run, and
`tickAllEnabledWorkers()` would consult `sys_plugin_cron_runs` before calling it.

- One entry point per plugin, one table, no new `class`/`file` syntax.
- Inherits Safe Mode, `worker_enabled` and the existing audit rows for free.
- But every job of a plugin shares one process and one timeout, the tick stops
  being "every poll" for any plugin that adopts it, and a five-minute job still
  holds the daemon while it runs — a collector that takes eight seconds delays
  every other plugin's webhook delivery by the same amount.

The signature mismatch is worth naming either way: the loader calls its hook as
`$fn(string $slug, array $settings)`, and the entries here are called as
`(array $settings, string $slug)`. Option B would have to pick one order; option
A keeps the hook untouched and documents the difference rather than silently
swapping arguments for one of them.

## Security model

Plugin PHP runs with the portal's privileges. This does not sandbox that; it
makes the loader honest about what it will execute:

- every path is resolved with `realpath()` and must stay inside the plugin
  directory (a symlink cannot climb out);
- a plugin file or directory writable by group or other is refused — a
  world-writable catalog is anyone on the host running PHP as the portal;
- one namespace prefix per plugin, and a class collision is reported rather than
  resolved by load order;
- cron runs as the portal user on purpose. Work that genuinely needs root belongs
  in a core cron or behind an `aipbx-priv` verb a human reviewed.

Whether a plugin is *enabled* is the loader's decision, passed into the runtime
as a callable, so the two cannot disagree.

## Wiring to the loader

`web/src/core/PluginBootstrap.php` is the only file that talks to the loader;
every other file takes its dependencies as arguments and is testable without one.
It asks `PluginManager` for what it already knows — `catalogDir()` and
`customDir()` for the two catalogs (custom uploads win for the same slug, as in
`discoverManifests()`), `isEnabled($slug)` for permission, and
`getAllSettings($slug, false)` for the settings, unmasked exactly as the web tick
receives them. Without `PluginManager` in the tree it answers "no plugin is
enabled" and reads the bundled catalog alone.

| variable | default | meaning |
|---|---|---|
| `AIPBX_CUSTOM_PLUGINS_DIR` | `/var/lib/aipbx/plugins` | the loader's own custom catalog; read here only when `PluginManager` does not expose `customDir()` |
| `AIPBX_PLUGIN_LOCK_DIR` | `/var/lib/aipbx/plugin-locks` | where the per-job locks live |

## Limits

- No dependency installation: a plugin that needs a Python daemon keeps shipping
  that through `install.sh`.
- No UI: `--status` returns the rows a portal table needs.
- The plugin directory is the unit of install and removal; nothing is copied into
  `web/`.
- A cron job's failures are counted in its own row and are **not** fed to the
  loader's Safe Mode streak. Five failed report jobs should not disable a
  plugin that is serving calls; a plugin that keeps failing gets a visible row
  and an operator, not an automatic disable.
