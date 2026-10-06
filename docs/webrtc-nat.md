# WebRTC, NAT and TURN

The browser phone and the mobile apps are WebRTC clients: signalling goes over a TLS WebSocket
(`wss://your-domain/ws`), audio over DTLS-SRTP negotiated with ICE. Almost every *"no audio"*,
*"only one side hears"* or *"calls start late"* report comes from NAT or a firewall. This page explains
what AiPBX does about it and what you need to configure.

## How a WebRTC call reaches Asterisk

```
browser / app ──443──▶ nginx ─┬─ ALPN present ──▶ Apache ── /ws ──▶ Asterisk (signalling)
                              └─ no ALPN / stun.turn ──▶ coturn TURNS (media relay)
media: client ⇄ Asterisk RTP directly when possible, otherwise client ⇄ coturn relay ⇄ Asterisk
```

ICE tries the candidates in order of preference: the direct path (client to Asterisk's address) and,
when that is blocked, the **TURN relay** on the same server. Clients are given only a **TURNS**
(TURN over TLS) server — no plain STUN/TURN and no public STUN servers — because firewalls with deep
packet inspection recognise and drop unencrypted STUN/TURN by its signature, and dead candidates only
slow ICE down. (The Android app falls back to a public STUN server only when no TURN is configured.)

TURN credentials are per user and valid for one hour (HMAC-SHA1 with coturn's `static-auth-secret`,
the *TURN REST API* scheme); no static TURN password is ever sent to a client. The browser phone
refreshes them every 30 minutes, so a call-centre tab that stays open all day keeps working.

## What to configure

### 1. External IP and local networks — *PBX Settings*

When the server sits behind NAT (a cloud VM with a private address, a server behind the office
router), set **External IP Address** and list your **Local Network & VPN Subnets** under
**Admin → PBX Settings**, then *Apply*. AiPBX writes:

- `external_media_address` / `external_signaling_address` and `local_net` on every PJSIP transport —
  desk phones and trunks outside the local networks are given the public address, phones inside keep
  the private one;
- an `[ice_host_candidates]` section in `rtp.conf` mapping the server's private address to the public
  one, with `include_local_address=yes` so clients on the LAN can still use the private address.

### 2. TURNS port — `/etc/ai-pbx.env`

| Setting | Clients are given | Needs open |
|---------|-------------------|-----------|
| `TURNS_PORT=443` (installer default) | `turns:your-domain:443?transport=tcp` | only 443/tcp |
| `TURNS_PORT=5349` | `turns:your-domain:5349?transport=tcp` | 5349/tcp |

coturn listens on 5349; the nginx multiplexer on 443 passes TLS connections without ALPN (browsers,
the apps) or with `stun.turn` to it. Clients are given 443, so WebRTC audio works on networks that
allow nothing but 443 (hotels, hospitals, guest Wi-Fi, strict corporate networks). Change it in
`/etc/ai-pbx.env`; the portal reads the file on every request, and clients pick the new port up with
their next credential refresh or sign-in. Updates keep the value, except that the
old default 5349 is switched to 443 once.

coturn relays on the server's own address (`relay-ip` in `/etc/turnserver.conf`, set by the
installer). TURNS connections that arrive through the 443 edge come from `127.0.0.1`; without
`relay-ip` coturn opened the relay on loopback, where Asterisk's media never arrived.

### 3. A trusted certificate

TURNS is TLS: browsers refuse a self-signed certificate or one for another name **without any visible
error**, and every call that needs the relay is silent. Check **Security → Certificates**; see
[Certificates](certificates.md).

### 4. Firewall

| Port | Used for |
|------|----------|
| 443/tcp | portal, `/ws` signalling, chat; TURNS when `TURNS_PORT=443` |
| 3478/udp+tcp, 5349/udp+tcp | STUN/TURN and TURNS directly on coturn |
| 49152-65535/udp | coturn relay ports |
| 10000-20000/udp | Asterisk RTP (the default RTP range is 10000-12999) |

## Desk phones behind NAT

Endpoints for SIP devices are generated with `force_rport`, `rewrite_contact` and `rtp_symmetric`:
replies and audio go to the address and port packets really came from, not to the private address a
phone writes into its messages. `direct_media=no` keeps audio flowing through Asterisk, so two phones
behind two different NATs never need to reach each other, and `qualify` pings keep the router's NAT
entry open.

## One extension, several devices

A desk phone wants plain RTP; WebRTC needs DTLS-SRTP, ICE and AVPF. Every extension therefore has two
endpoints with separate AORs — `1000` for SIP devices and `1000-webrtc` for the browser and the apps —
and the dialplan rings both contact lists in parallel. Mobile AORs keep a single contact
(`max_contacts=1`, `remove_existing=yes`): when a phone moves from Wi-Fi to mobile data, the new
registration replaces the dead one at once.

## Why `stunaddr` is off

With `stunaddr` set in `rtp.conf`, Asterisk sent a STUN request for every RTP session; on ICE sockets
the answer never reached it, so each WebRTC call waited 3 × 3 seconds before the dialplan started
(users saw calls start 4-5 s late on the web, up to 30 s on mobile). The static `[ice_host_candidates]`
mapping gives Asterisk its public address without a network lookup, so `stunaddr` is off by default.
The `rtp_stunaddr_enabled` system setting turns it back on for setups that need it.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Audio only on the server's LAN | *External IP Address* set and applied? `asterisk -rx "rtp show settings"`, `grep -A3 ice_host /etc/asterisk/rtp.conf` |
| No audio on strict networks | certificate trusted for the domain; `TURNS_PORT`; `relay-ip` in `/etc/turnserver.conf`; `tail /var/log/turnserver/turnserver.log` |
| Silent calls after hours on the web | browser console: TURN credential refresh errors; coturn log `401 Unauthorized` |
| Which backend got a 443 connection | `tail /var/log/nginx/stream.log` shows the ALPN and the backend for every connection |

`chrome://webrtc-internals` (or `about:webrtc` in Firefox) shows the candidates of a running call: a
selected pair of type `relay` means the call goes through coturn.
