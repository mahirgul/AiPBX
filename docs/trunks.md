# Trunks

A trunk is a SIP connection to another PBX (for example a NEC system) or to a carrier. Trunks are
managed on **Trunks**; the edit dialog is split into tabs.

| Tab | Settings |
|-----|----------|
| Basic | System name, title, IP/host, port, transport, codecs, outbound proxy, extra matched hosts |
| Authentication | IP-based or registration mode, username/password, registration timers |
| Caller ID | Outbound caller ID, From user/domain, send name, PAI / RPID headers |
| Media & Fax | DTMF mode, T.38 options, fax detection |
| Network & NAT | Qualify interval, direct media, RTP symmetric, rewrite contact, force rport, session timers |
| Routing | Context, channel limit, inbound DID normalization, transit routing, outbound caller ID normalization |
| Advanced | Extra PJSIP parameters, one per line |

## Identification

Calls from a trunk are recognised by source IP (the trunk address plus *matched hosts*). Two trunks
must not share an address: a call would match both. Change the IP when you copy a trunk.

## Inbound DID normalization

**Keep only the last N digits of the DID** trims the called number before it is matched against
inbound routes, e.g. `4` turns `03704187840` into `7840`. `0` keeps the full number.

Trimming applies to every call from the trunk. If the trunk also sends transit calls (below), trimming
cuts those numbers too — `4440478` becomes `0478`. Use it only when the trunk sends nothing but
DIDs; otherwise define the inbound routes with the full numbers the trunk sends.

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

## Renaming, copying, ordering

- The **system name** can be changed after creation. The old PJSIP objects are removed, outbound routes
  that use the trunk are updated, and the dialplan is marked for *Apply*. Old CDRs keep the old name.
- **Copy** creates a new trunk with the same settings (see [Portal basics](portal.md#copying-records)).
- **Order** the list by dragging; the first active trunk is the primary trunk.
