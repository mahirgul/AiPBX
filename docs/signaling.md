# Signaling capture and reporting

AiPBX ships an optional signaling pipeline: what crosses the SIP stack can
be captured, kept on this server, shown on the portal's Signaling page, and
— only if an administrator turns it on — forwarded to a monitoring endpoint.

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
Portal Signaling page  /  AI → Dialplan editor (Echo box)
```

**Asterisk side** — `hep.conf`:

```
[general]
enabled = yes
capture_address = 127.0.0.1:9061
capture_password = aipbx
capture_id = 1001
```

`modules.conf` loads `res_hep.so`, `res_hep_pjsip.so`, `res_hep_rtcp.so`.
Turning capture off: `enabled = no` (one word), reload Asterisk. The address
stays loopback unless you deliberately point it elsewhere — a capture port
open to a network turns a PBX into a free SIP sniffer for anyone who finds
it.

**Daemon side** — `python -m aipbx_signaling` (installed as
`aipbx-signaling.service`). Reads `/etc/ai-pbx.env` for database credentials
(the same file the portal reads; the `asterisk` group owns it). Settings in
`sys_settings` are re-read every 30 s, so the portal's Signaling page is the
single source of truth:

| setting                     | meaning                              |
|-----------------------------|--------------------------------------|
| `signaling_enabled`         | capture listener on/off              |
| `signaling_retention_days`  | delete events older than this (1–3650) |
| `signaling_report_enabled`  | forwarding OFF unless set to `1`     |
| `signaling_report_endpoint` | where to forward (see contract)      |
| `signaling_report_key`      | the node key the endpoint requires   |

**Storage** — `pbx_signaling_events`: `ts, call_id, method, proto,
transport, src, dst, from_uri, to_uri, cseq, status_code, first_line, line,
capture_id`. Message text is kept in `first_line`/`line` (bounded); phones
still show in `from_uri`. Rows are pruned by the retention setting.

## The Echo box (AI → Dialplan editor)

The page's "Ask about the phone system" box answers from this same data:
counts of calls (CDRs), captured SIP messages in the last 24 h, and SIP
error responses grouped by status code (`400/403/404/480/486/500/503/603`
…). The queries are fixed SELECTs — see
`web/src/services/ai/EchoReporter.php`. Nothing writes; no packet text
leaves the server unless forwarding is on.

## The forwarding contract (for a monitoring endpoint)

Aj monitoring endpoint is any HTTP service that accepts these two calls —
the same contract the Echo project's cloud connector defines. Forwarding is
off by default and must be turned on deliberately in the portal.

    POST <endpoint>/api/hep
    X-Echo-Node-Key: <key>          (also sent as `Authorization: Bearer <key>`)
    Content-Type: application/json

    {"packets": ["<base64 HEPv3 datagram>", ...]}        (up to 200 per call)

    POST <endpoint>/api/nodes/heartbeat
    X-Echo-Node-Key: <key>
    {"platform": "aipbx", "version": "<portal version>"}  (every 60 s)

Receipts and errors: the endpoint answers `204` on accept; `429` pauses the
sender for 30 s (per-node rate limits are respected, not fought). An
endpoint that stays unreachable loses the oldest queued datagrams — the
queue is bounded at 2000, capture on the PBX never blocks, and the dialplan
editor's Echo box keeps answering from local data alone.

**Privacy note.** The datagrams are the SIP messages themselves: phone
numbers, SIP addresses, Call-IDs, the timing of every call. A PBX may not
send that off the server silently, which is why the default is off and the
page that turns it on says what leaves.
