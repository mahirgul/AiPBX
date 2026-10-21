# Delayed audio start on alarm-server calls — packet capture analysis (2026-10-21)

## Summary

**The delay is caused by the alarm server at `10.103.80.44` (New Voice MobiCall, `User-Agent: NVTVoIP/15.0.0.2269`, Opal SIP stack).**

After the called handset (DECT 781, IP phone 846, extension 174) answers, the PBX and the handset start sending real audio within 150–300 ms. MobiCall, however, keeps sending **pure digital silence (PCMA `0xD5`) on both legs for about 1.5–1.7 seconds** before it starts bridging the two calls. Everything the parties say in that window ("Hello?") is lost.

Once the bridge is open the forwarding latency through MobiCall is normal (0–40 ms). The network and the PBX are not the cause.

A secondary issue on the PBX side (`10.103.80.42`): it answers MobiCall's re-INVITE with an invalid `a=ptime:0` and re-starts the RTP stream. This is a protocol error worth fixing, but the captures do not prove it causes the 1.5 s gap.

## Topology seen in the captures

| IP | Role | Evidence |
|---|---|---|
| `10.103.80.42` | PBX (serves handsets 781, 846, 17x, 78x) | Sends `INVITE sip:701@10.103.80.44` / `sip:100@10.103.80.44`; answers `INVITE sip:781@10.103.80.42` etc. No `User-Agent` header. |
| `10.103.80.44` | Alarm server "Alarmserver" (701, 100) | `User-Agent: NVTVoIP/15.0.0.2269`, `Organization: New Voice`, `s=Opal SIP Session`, Call-IDs `...@nec-mobicall` |
| `10.103.80.41` | Unrelated (RDP on port 3389) | Ignored |

Call flow: caller → PBX → MobiCall (A-leg, 701/100). MobiCall then calls the target(s) back through the PBX (B-leg, 781 / 846 / 174...) and bridges A and B internally. All media is PCMA (G.711 A-law), 20 ms, 160-byte payload.

## Evidence

Times are seconds since the first packet of each capture.

### Capture 1 — `21.10.2026_DECT781.pcapng`

A-leg: Call-ID `1fddc61bfC0_qW@10.103.80.42` (caller → 701), RTP `.42:50038 ↔ .44:5046`
B-leg: Call-ID `9f28ea67-...@nec-mobicall` (MobiCall → 781), RTP `.44:5048 ↔ .42:50000`

```
 3.085  .44 -> .42  200 OK (701 answers A-leg)
 4.521  .44 -> .42  INVITE sip:781@10.103.80.42
 4.622  .42 -> .44  180 Ringing
 6.224  .42 -> .44  200 OK            <-- DECT 781 answers
 6.227  .44 -> .42  ACK
 6.303  .44 -> .42  re-INVITE (codec list narrowed: 8 101 100)
 6.303  .42 -> .44  200 OK  with  a=ptime:0   <-- invalid SDP
 6.306  .44 -> .42  ACK
 6.381              first RTP from 781 (.42:50000 -> .44:5048)
 6.561              PBX RTP restarts: 120 ms gap, sequence number jumps back by 369
 7.0 - 7.75         781 user speaks (-31 ... -22 dBov) -> NOT forwarded to caller
 7.863              MobiCall starts forwarding 781 audio to the caller (.44:5046)
 7.878              MobiCall starts forwarding caller audio to 781 (.44:5048)
```

Audio level per 250 ms window (dBov, -72 = pure `0xD5` silence):

```
.42:50000 -> .44:5048 (from DECT 781): 6.25:-45 6.50:-69 6.75:-67 7.00:-52 7.25:-31 7.50:-27 7.75:-22 8.00:-28
.44:5046 -> .42:50038 (to caller)    : 6.25:-72 6.50:-72 6.75:-72 7.00:-72 7.25:-72 7.50:-72 7.75:-23 8.00:-25
.42:50038 -> .44:5046 (from caller)  : 6.75:-50 7.00:-68 7.25:-70 7.50:-42 7.75:-41 8.00:-38 8.25:-38
.44:5048 -> .42:50000 (to DECT 781)  : 6.25:-72 6.50:-72 6.75:-72 7.00:-72 7.25:-72 7.50:-72 7.75:-42 8.00:-38 8.25:-38
```

**Dead air after answer: ~1.65 s.**

### Capture 2 — `21.10.2026_IP_Apparat_846.pcapng`

Two alarm calls run in parallel in this capture; both show the same pattern.

**Call to 701 → group 781/782/783/784/846, answered by IP phone 846**
A-leg RTP `.42:50040 ↔ .44:5448`, B-leg Call-ID `d3be9973-...@nec-mobicall`, RTP `.44:5464 ↔ .42:50008`

```
 8.623  .44 -> .42  INVITE sip:846@10.103.80.42
 9.906  .42 -> .44  200 OK            <-- IP phone 846 answers
 9.909  .44 -> .42  ACK
 9.972  .44 -> .42  re-INVITE
 9.972  .42 -> .44  200 OK  with  a=ptime:0
10.097-10.199       MobiCall CANCELs 781/782/783/784 (487 Request Terminated)
10.273              first RTP from 846
10.5 - 11.25        846 user speaks (-35 ... -28 dBov) -> NOT forwarded
11.357              MobiCall starts forwarding 846 audio to caller (.44:5448)
11.388              MobiCall starts forwarding caller audio to 846 (.44:5464)
```

```
.42:50008 -> .44:5464 (from 846)   : 10.25:-53 10.50:-35 10.75:-28 11.00:-32 11.25:-32
.44:5448 -> .42:50040 (to caller)  : 10.25:-72 10.50:-72 10.75:-72 11.00:-72 11.25:-31
```

**Dead air after answer: ~1.48 s.**

**Call to 100 → group 171/172/173/174/176/external, answered by 174**
A-leg RTP `.42:50024 ↔ .44:5440`, B-leg Call-ID `0d919073-...@nec-mobicall`, RTP `.44:5442 ↔ .42:50000`

```
 6.539  .42 -> .44  200 OK            <-- 174 answers
 6.581  .44 -> .42  re-INVITE  ->  200 OK with a=ptime:0
 6.820              first RTP from 174, loud speech immediately (-13 dBov at 7.00)
 8.233              MobiCall starts forwarding 174 audio to caller (.44:5440)
 8.263              MobiCall starts forwarding caller audio to 174 (.44:5442)
```

```
.42:50000 -> .44:5442 (from 174)  : 6.75:-37 7.00:-13 7.25:-15 7.50:-5 7.75:-33 8.00:-6 8.25:-6
.44:5440 -> .42:50024 (to caller) : 6.75:-70 7.00:-70 7.25:-70 7.50:-70 7.75:-72 8.00:-8 8.25:-6
```

**Dead air after answer: ~1.7 s** — the whole first sentence of 174 is lost.

### Summary table

| Call | Handset 200 OK | Handset audio starts | MobiCall bridge opens | Dead air |
|---|---|---|---|---|
| 701 → DECT 781 | 6.224 | 6.381 | 7.863 / 7.878 | **~1.65 s** |
| 701 → IP 846 | 9.906 | 10.273 | 11.357 / 11.388 | **~1.48 s** |
| 100 → 174 | 6.539 | 6.820 | 8.233 / 8.263 | **~1.70 s** |

### Why the network and the PBX are ruled out

- No RTP loss or jitter on the MobiCall output streams: constant 20 ms packets, continuous sequence numbers, 160-byte payloads during the whole silent period. MobiCall *is* sending packets — they just contain `0xD5` (silence).
- The PBX delivers the handset's audio to MobiCall 150–300 ms after the 200 OK, i.e. promptly.
- After the bridge opens, the energy envelopes of input and output streams correlate with a lag of 0–40 ms, so MobiCall's mixer itself is not slow; it is the *bridge start* that is late.
- The silence is pure `0xD5` (not a prompt or tone), so it is not an intentional announcement to the called party.
- SIP signalling itself is fast (all responses within a few ms); nothing on the wire explains a 1.5 s wait.

### Secondary finding — PBX answers re-INVITE with `a=ptime:0`

In every call, ~70 ms after the ACK, MobiCall sends a re-INVITE (same port, codec list narrowed). The PBX answers:

```
m=audio 50000 RTP/AVP 8 101
a=rtpmap:8 PCMA/8000
a=ptime:0          <-- original answer had a=ptime:20
```

`ptime` must be a positive number of milliseconds (RFC 4566). Right after this the PBX restarts the RTP stream to DECT 781 (120 ms gap, sequence number jump). It is plausible that MobiCall's Opal stack also re-opens its media channel when it receives this answer, but the captures cannot prove that this is what takes 1.5 s.

## Recommendations

1. **MobiCall / New Voice (primary owner):** open a ticket with these captures. Ask why the bridge between the incoming alarm call and the answered callback leg is only connected ~1.5 s after the 200 OK. Check MobiCall settings for: connect/bridge delay after answer, "wait for CANCEL of other group members before connecting", acknowledgement/confirmation logic, and whether the post-answer re-INVITE (codec narrowing) can be disabled.
2. **PBX (`10.103.80.42`):** fix the SDP answer to re-INVITE so it keeps `a=ptime:20` instead of `a=ptime:0`, and does not restart the RTP stream when the media parameters did not change.
3. **Test to separate the two:** temporarily disable the re-INVITE in MobiCall (or fix ptime on the PBX). If the dead air stays ~1.5 s, the delay is purely inside MobiCall's call logic.

## Method

The captures were parsed with Scapy. SIP messages were listed per Call-ID. For each RTP stream the G.711 A-law payload was decoded and the RMS level computed per packet and per 250 ms window. "Bridge opens" is the first packet on a MobiCall output stream after the handset's 200 OK whose level rises above the `0xD5` silence floor; forwarding latency was estimated by cross-correlating the 20 ms energy envelopes of the matching input and output streams.
