# Trunks

A trunk is a SIP connection to another PBX (for example a NEC system) or to a carrier. Trunks are
managed on **Trunks**; the edit dialog is split into tabs.

| Tab | Settings |
|-----|----------|
| Basic | System name, title, IP/host, port, transport, codecs, outbound proxy, extra matched hosts |
| Authentication | IP-based or registration mode, username/password, registration timers |
| Caller ID | Outbound caller ID, From user/domain, send name, PAI / RPID headers |
| Media & Fax | DTMF mode, T.38 options, fax detection |
| Network & NAT | Qualify interval, direct media, RTP symmetric, rewrite contact, force rport, session timers, 100rel (PRACK) |
| Routing | Context, max concurrent channels, max calls per minute, inbound DID normalization, transit routing, outbound caller ID normalization, carrier dialled number format |
| Advanced | Extra PJSIP parameters, one per line |

## Identification

Calls from a trunk are recognised by source IP (the trunk address plus *matched hosts*). Two trunks
must not share an address: a call would match both. Change the IP when you copy a trunk.

## Media and signaling

Configured per trunk under **Media & Fax** and **Network & NAT**:

- **DTMF mode:** How keypad touch tones are transmitted:
  - `RFC 4733 / RFC 2833` (`rfc4733`, default and recommended): Out-of-band RTP payload (RFC 4733).
  - `Inband` (`inband`): Transmitted within the voice audio stream (requires uncompressed G.711 codecs).
  - `SIP INFO` (`info`): Sent in SIP `INFO` request messages.
  - `Auto` (`auto`): Negotiated with the remote peer.
- **Session timers:** Periodic `re-INVITE` or `UPDATE` messages to verify the call is still alive:
  - `Yes` (`yes`): Session timers enabled (Asterisk default).
  - `No` (`no`): Session timers disabled. (Older releases offered "Never", which Asterisk does not
    know; 1.50.11 changed such trunks to "No".)
  - `Required` (`required`): Rejects incoming calls if the remote peer does not support session timers.
  - `Always` (`always`): Forces session timers even when the remote peer does not request them.
- **100rel (PRACK):** Reliable transmission of provisional SIP responses (RFC 3262): `Supported (yes)`,
  `Disabled (no)`, or `Required` (`required`).
- **T.38 options:**
  - **T.38 support:** `Active (T.38 UDPTL)` or `Inactive (Audio Pass-through)`. Keep off unless the
    carrier explicitly supports T.38; carriers that reject T.38 make faxes fail.
  - **Error correction:** `Redundancy` (recommended), `FEC` (Forward Error Correction), or `None`.
  - **T.38 NAT:** `Yes` (behind NAT) or `No` (direct IP).
  - **Max Datagram:** 100–2000 bytes (default 400).
  - **Fax detection:** on or off; when on, a fax tone during the first seconds of a call (default 30 s)
    sends the call to fax reception.

## Inbound DID normalization

**Keep only the last N digits of the DID** trims the called number before it is matched against
inbound routes, e.g. `4` turns `03704187840` into `7840`. `0` keeps the full number.

Trimming applies to every call from the trunk. If the trunk also sends transit calls (below), trimming
cuts those numbers too — `4440478` becomes `0478`. Use it only when the trunk sends nothing but
DIDs; otherwise define the inbound routes with the full numbers the trunk sends.

## Limits

Two limits per trunk (**Routing** tab), both for inbound and outbound calls together, 0 = none:

- **Max concurrent channels**: calls on the trunk at the same time.
- **Max calls per minute**: new calls in one minute.

A call over a limit is refused: an inbound one is hung up with cause 34 (no circuit), so the carrier
may try another way; an outbound one counts as congestion, so the next trunk of the route is tried.
Refused calls raise the [health check](health.md) *Trunk limits* ("Trunk nectip refused 412 calls over
its calls-per-minute limit since 15:00"), shown in the alert bell and e-mailed like every alarm.

The per-minute count is kept in Asterisk's database and is approximate when many calls start in the
same instant; it is meant as a brake, not for billing. A per-minute limit a little above the busiest real minute stops a call loop between two PBXes (one
once made 6 000 calls an hour on one trunk) and toll fraud through a stolen account early.

## Transit routing

**Allow outbound routing** lets calls arriving on this trunk use the outbound routes of the selected
**route group** and reach local extensions. A NEC system can then send external calls out through
the carrier trunk via AiPBX. Without it, only inbound routes are available to the trunk.

## Outbound caller ID normalization

Applied to the caller number of every call leaving through this trunk:

1. **Keep only the last N digits** (`0` = the whole number)
2. **Prepend** a prefix

Example — main number `903704187000`, extension `7840` on a NEC system calling out:
keep `4`, prepend `90370418` → the carrier receives `903704187840`.

When a route falls back to the next trunk, the original caller number is restored first, so each trunk
applies its own rule to the original number.

## Carrier dialled number format

Configured under the **Routing** tab (*Carrier Dialled Number Format*):

- **Strip prefix** only: a number starting with it loses it (e.g. `+90`).
- **Add prefix** only: it is put in front of every number (e.g. `0` or a carrier routing code).
- **Both:** a number starting with the strip prefix gets the add prefix instead of it (`+90532…` →
  `0532…`); other numbers are sent unchanged.

**Applied to every trunk attempt:** Unlike outbound route pattern manipulation (which applies once when
the call enters the route), trunk dialled number format applies dynamically to every trunk that is tried.
When an outbound route fails over to a secondary or backup trunk, the dialled number is transformed
according to the secondary trunk's own format rules.

## Renaming, copying, ordering

- The **system name** can be changed after creation. The old PJSIP objects are removed, outbound routes
  that use the trunk are updated, and the dialplan is marked for *Apply*. Old CDRs keep the old name.
- **Copy** creates a new trunk with the same settings (see [Portal basics](portal.md#copying-records)).
- **Order** the list by dragging; the first active trunk is the primary trunk.
