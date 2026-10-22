# call-quality

Score calls from the RTCP reports Asterisk already sends, keep the evidence, and
tell a person when a trunk is losing media. One optional, reversible change: move
a persistently lossy trunk to the end of an outbound route for **new calls**.

Administrator documentation: [docs/call-quality.md](../../docs/call-quality.md).

## What is in here

```
plugin.json                                   manifest: catalog PHP, cron[], cli{}, settings
schema.sql                                    plg_call_quality_evidence (one table, nothing else)
web/src/services/CaptureState.php             is RTCP arriving here? (reads Asterisk's hep.conf)
web/src/services/CallQualityService.php       scoring, evidence, notices, the demotion
admin/page.php                                the panel on /plugins/call-quality
```

No copy of the service goes into `web/`: the loader's `php.files` map loads these
two classes from the plugin directory, `cron[]` runs `collect` every five
minutes in its own process, and `cli{}` exposes the four commands.

## Hooks it uses

```json
"php":   {"files": {"web/src/services/CallQualityService.php": ["CallQualityService"], ...}}
"cron":  [{"job": "collect", "schedule": "*/5 * * * *", "class": "CallQualityService::run", "timeout": 120}]
"cli":   {"report": ..., "evidence": ..., "tick": ..., "capture": ...}
```

A portal without the loader ignores these keys and installs the plugin anyway; it
then finds no jobs, no commands and no autoloader, so nothing runs. That is the
intended failure mode: an inert package, not a broken page.

## What it writes

- `plg_call_quality_evidence` — one row per incident (deduped by call and first
  report), with the RTCP samples that led to the decision.
- `pbx_notices` — through `NoticeService::notify()`, deduped per problem for the
  portal's notice window, e-mailed only if notice mail is on.
- `sys_audit_log` — one row per trunk demotion, domain `call_quality`.
- `pbx_outbound_routes` — only when `auto_fix` is on and the rules in the docs
  all hold; the dialplan is then regenerated for `outbound_dialplan` alone.

It never writes `pbx_signaling_events` (that is the daemon's) and never touches a
call in progress.

## Install

1. Copy this directory into the portal's plugin catalog (`plugins/call-quality`
   for the bundled catalog, `/var/lib/aipbx/plugins/call-quality` for a custom
   one).
2. **Integrations → Plugins → Call Quality Evidence**, then **Enable**. The
   loader applies `schema.sql`.
3. Open `/plugins/call-quality` and read the capture state. It must say *RTCP
   arrives here*; if it does not, fix `hep.conf` first — nothing else will work.
4. Add the cron line once per server (the cron doc has it):
   `* * * * * www-data /var/www/aipbx/web/bin/aipbx_plugin_cron.php`
5. `aipbx_plugin_cli.php call-quality capture` and `... tick` should agree with
   the page.

## Uninstall

Disabling the plugin stops the collector and the page. The table stays until the
loader's uninstall removes it — export the evidence first if it is a record you
need to keep.
