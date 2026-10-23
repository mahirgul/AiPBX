# Signaling capture

> Built by Gregory Merriweather ([@shido6](https://github.com/shido6)). Part of AiPBX itself since 1.50.8:
> **PBX settings → Signaling**. Until then it was switched by the bundled `signaling-capture` plugin, which
> pointed Asterisk at a port nothing listened on; the update turns capture on when that plugin was on and
> removes it.

AiPBX ships an optional signaling pipeline: what crosses the SIP stack can
be captured, kept on this server and read by the dialplan editor's Echo box
and the [Call Quality plugin](https://github.com/mahirgul/AiPBX-plugins/tree/main/plugins/community/call-quality).
Capture is opt-in and off by default, so a normal installation captures
nothing and pays no overhead.

**Turning it on:** PBX settings → **Signaling** → Capture **On**, Save. It takes effect at once (only
`res_hep` is reloaded; calls are not touched). The same tab sets how many days the messages are kept
and shows how many arrived in the last 24 hours.

Nothing on this path can affect a call. The capture is a UDP datagram that
Asterisk sends and moves on; the daemon writes to the database in batches
and survives the database going away (events are lost, capture continues).

## The chain

![Signaling capture chain](img/signaling.svg)

**Asterisk side** — `hep.conf`:

```
[general]
enabled = no
capture_address = 127.0.0.1:9061
capture_password = aipbx
capture_id = 1001
```

`modules.conf` loads `res_hep.so`, `res_hep_pjsip.so`, `res_hep_rtcp.so`.
`hep.conf` is replaced on every update; the portal's switch is
`/etc/asterisk/pbx/hep_aipbx.conf` (`[general] enabled = yes|no`, included
after it). The address stays loopback — a capture port open to a network
turns a PBX into a free SIP sniffer for anyone who finds it.

**Daemon side** — `python -m aipbx_signaling` (installed by `install.sh`,
and therefore by `aipbx-update` on every upgrade, as
`aipbx-signaling.service`). Reads `/etc/ai-pbx.env` for database credentials
(the same file the portal reads; the `asterisk` group owns it). One setting
comes from `sys_settings` (default when absent, clamped 1..3650):

| setting                    | meaning                                 |
|----------------------------|-----------------------------------------|
| `signaling_retention_days` | delete events older than this (1–3650; PBX settings → Signaling) |

The daemon reads HEPv3 as specified: a 6-byte packet header and chunks with
a 6-byte header (vendor, type, length). Until 1.50.8 it read 4-byte chunk
headers, lost the last chunk — the message itself — and stored every capture
as `unknown`; a live Asterisk 22 datagram is now part of its tests.

**Storage** — `pbx_signaling_events`: `ts, call_id, method, proto,
transport, src, dst, from_uri, to_uri, cseq, status_code, first_line, line,
capture_id, rtcp_ssrc, rtcp_lost, rtcp_fraction_lost, rtcp_jitter`. Message
text is kept in `first_line`/`line` (bounded); phones still show in
`from_uri`. Rows are pruned by the retention setting.

**RTCP counters** — an RTP stream reports its own quality. `res_hep_rtcp`
sends each Sender/Receiver Report through the same datagram path, and the
daemon keeps the worst report block of each report in four columns, so a
quality question can be answered from the stream the PBX already sent:

| column               | what it is                                                     |
|----------------------|----------------------------------------------------------------|
| `rtcp_ssrc`          | source SSRC of the worst report block                          |
| `rtcp_lost`          | that block's cumulative packet count                            |
| `rtcp_fraction_lost` | RFC 3550's 8-bit fraction (255 = 100 %), exactly as reported    |
| `rtcp_jitter`        | `ia_jitter`, in RTP timestamp units — **not** milliseconds      |

The sample rate is not part of a report, so jitter is stored raw: converting
it to milliseconds would be a guess dressed up as a measurement. `NULL` means
the report did not carry that counter; it is never stored as `0`, which would
look healthy. SIP rows leave all four `NULL`.

One report can carry several blocks (one per source) and a call is as good as
its lossiest stream, so the block with the highest `fraction_lost` is the one
kept. The full JSON body is still not stored — the counters are the part a
decision needs.

## The Echo box (AI → Dialplan editor)

The page's "Ask about the phone system" box answers from this same data:
counts of calls (CDRs), captured SIP messages in the last 24 h, and SIP
error responses grouped by status code (`400/403/404/480/486/500/503/603`
…). The queries are fixed SELECTs — see
`web/src/services/ai/EchoReporter.php`. Nothing writes, and no packet text
leaves this server.

## What stays on this server

Captured events never leave the machine: `pbx_signaling_events` is read by
the dialplan editor's Echo box and by plugins on this server. Sending
captures to an outside Homer server is not offered: Asterisk's `res_hep` has
one capture address, and pointing it elsewhere stops the local capture.
