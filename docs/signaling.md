# Signaling capture (Plugin)

> **Plugin (`signaling-capture`)**: Optional plugin from the official catalog. Enable it under **Integrations → Plugins** (see [Plugin system & developer guide](plugin-development.md)).

AiPBX ships an optional signaling pipeline: what crosses the SIP stack can
be captured, kept on this server and read by the dialplan editor's Echo box.
Capture is opt-in: `asterisk-config/hep.conf` ships with `enabled = no`, so
a normal installation captures nothing and pays no overhead.

Nothing on this path can affect a call. The capture is a UDP datagram that
Asterisk sends and moves on; the daemon writes to the database in batches
and survives the database going away (events are lost, capture continues).

## The chain

```
Asterisk (res_hep, res_hep_pjsip, res_hep_rtcp)
    │  HEPv3 over UDP, loopback only
    ▼
aipbx-signaling (systemd: aipbx-signaling.service, 127.0.0.1:9061)
    │  parsed -> batched rows
    ▼
MariaDB: pbx_signaling_events
    │  read
    ▼
AI → Dialplan editor (Echo box)
```

**Asterisk side** — `hep.conf`:

```
[general]
enabled = no
capture_address = 127.0.0.1:9061
capture_password = aipbx
capture_id = 1001
```

`modules.conf` loads `res_hep.so`, `res_hep_pjsip.so`, `res_hep_rtcp.so`.
Turning capture on: `enabled = yes` (one word), reload Asterisk. The address
stays loopback unless you deliberately point it elsewhere — a capture port
open to a network turns a PBX into a free SIP sniffer for anyone who finds
it.

**Daemon side** — `python -m aipbx_signaling` (installed by `install.sh`,
and therefore by `aipbx-update` on every upgrade, as
`aipbx-signaling.service`). Reads `/etc/ai-pbx.env` for database credentials
(the same file the portal reads; the `asterisk` group owns it). One setting
comes from `sys_settings` (default when absent, clamped 1..3650):

| setting                    | meaning                                 |
|----------------------------|-----------------------------------------|
| `signaling_retention_days` | delete events older than this (1–3650)  |

A Signaling page that edits this in the portal is a follow-up; today the
setting is written to `sys_settings` directly, and whether Asterisk captures
at all is the `hep.conf` switch above.

**Storage** — `pbx_signaling_events`: `ts, call_id, method, proto,
transport, src, dst, from_uri, to_uri, cseq, status_code, first_line, line,
capture_id`. Message text is kept in `first_line`/`line` (bounded); phones
still show in `from_uri`. Rows are pruned by the retention setting.

## The Echo box (AI → Dialplan editor)

The page's "Ask about the phone system" box answers from this same data:
counts of calls (CDRs), captured SIP messages in the last 24 h, and SIP
error responses grouped by status code (`400/403/404/480/486/500/503/603`
…). The queries are fixed SELECTs — see
`web/src/services/ai/EchoReporter.php`. Nothing writes, and no packet text
leaves this server.

## What stays on this server

Captured events never leave the machine: `pbx_signaling_events` is read by
the dialplan editor's Echo box, locally.
Shipping captures to a third-party cloud is exactly the kind of connector
the planned plugin system should carry — not core.
