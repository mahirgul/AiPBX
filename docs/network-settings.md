# Network settings and a new server address

*Network → Network settings* (admin only) sets the server's network cards and moves every
stored address in one step when the server's IP address changes.

## What the page shows

- **Network cards**: one row per card with its IP address, subnet mask, gateway and DNS, and an
  **Edit** button. Gateway and DNS may stay empty on an inner card. A card nothing configures yet
  gets its own netplan file or NetworkManager connection. Every interface can be edited. One that
  something else owns (Docker, LXD, a tunnel) changes live, and its owner may write its own settings
  back. A wrong address cuts the server off, so a change goes back by itself unless you confirm it
  within 3 minutes.
  - *Default gateway*: only one card has it, and ticking it on one card takes it from the other.
    A card that is not the default keeps its own router: what arrives on its address is answered
    through that router.
  - *Networks behind this router*: routes through the card's router, written as `10.12.0.0/16`.
    If phones are on those networks, add them to *Local Network & VPN Subnets* (PBX Settings) too.
- **Run the check**: the NAT check for no audio or one-way audio ([WebRTC and NAT](webrtc-nat.md)).

The addresses the PBX and the phones use moved off this page in 1.70.0:

- **PBX Settings → PJSIP & Net**: *External IP Address* (`pjsip_external_ip`), *Public media address*, *Local
  Network & VPN Subnets* (`pjsip_local_net`, e.g. `192.168.1.0/24`, comma separated; the server's own card
  networks count as local without being listed) and, while it is set, *This server's address on the
  local network* (`server_ip`). Saving marks the transports and RTP for *Apply*. Asterisk takes a new
  local network at its next restart: this page says so, with a button that restarts Asterisk once no
  call is up.
- **Provisioning → Regions**: the addresses desk phones are given (SIP server, outbound proxy,
  provisioning address, syslog, NTP) and the allowed networks, above the regions that start from
  them; see [Desk phones](phones.md).

An address changed with **Edit** moves every stored address by itself when you confirm it. When the
address changed outside the portal (a new DHCP lease, a cloned VM), the move waits on the **Apply**
page by itself, naming what it changes.

## When the server's IP address changed

A new DHCP lease, a cloned virtual machine or a move to another network leaves the old address in
many places. Calls then fail in ways that are hard to trace: TURN has no audio, desk phones
register to the old address, the self-signed certificate names the wrong IP.

1. **It is noticed.** The health check *Server address* ([Health checks](health.md)) compares the
   stored addresses with the server's own and raises an alarm. The page shows the same warning at
   the top, with the addresses that are not the server's any more.
2. **Apply the new address.** Enter the old and the new address, press *Show what changes*: the
   list shows every place that holds the old address and what it becomes. *Apply the new address*
   then:
   - replaces it in the settings above (server address, public address, phone addresses);
   - adds the new address's network to the local networks when it is outside them;
   - rewrites coturn's `relay-ip` and `allowed-peer-ip` (every address of the server), the mDNS
     alias, and issues a new self-signed certificate when the active one names the old IP;
   - reloads the PJSIP transports and RTP (calls keep running) and rewrites the phone files;
   - restarts coturn as soon as no call is up (a restart drops calls that go through TURN).
3. **Not changed automatically**: DNS records, port forwards on the router, firewall rules that
   name the old address, and phones that were set up by hand with it. *Phone logs* shows which
   phones still ask for the old address. The allowed networks are flagged when the old address
   was inside them and the new one is not; check them by hand.

The same from the command line, for a server whose portal cannot be reached any more:

```
sudo aipbx-readdress 10.77.0.12 10.9.0.5          # shows what would change
sudo aipbx-readdress 10.77.0.12 10.9.0.5 --yes    # applies it
```

## Where the address lives (for troubleshooting)

| Place | Holds |
|---|---|
| `sys_settings`: `server_ip`, `pjsip_external_ip`, `pjsip_local_net` | RTP ICE candidate, NAT of every transport (`/etc/asterisk/pbx/pjsip_transports.conf`, `rtp.conf`) |
| `sys_settings`: `provision_*` | what desk phones are told; their files in `/var/lib/aipbx/tftp` |
| `/etc/turnserver.conf` | `relay-ip`, `allowed-peer-ip` |
| `/etc/systemd/system/aipbx-mdns.service` | the `.local` name's address |
| `/etc/ssl/aipbx/aipbx.crt` (self-signed) | the IP in the certificate |

`sudo aipbx-priv net status` prints the file side. The files are changed by
`aipbx-priv net readdress`, which runs in its own systemd unit (`aipbx-readdress`): the portal's
sandbox keeps `/etc` read-only.

## Time zone and tones

**PBX Settings → Language → Time zone and tones.** The time zone is one setting for the server, the portal, call records and time conditions. A new installation starts on the server's zone. An older one keeps what it has until you choose, and the page warns if the server and the portal differ.

Changing the zone restarts the database, Asterisk and the portal within a few seconds, and calls in progress drop. If calls are in progress the page refuses, unless you tick the box to end them. Working hours in time conditions then follow the new zone.

The tones are the ring and busy sounds callers hear. Changing them does not disturb calls. From the command line: `sudo aipbx-priv timezone America/Chicago` and `sudo aipbx-priv tones set us`.

## This server's address

Network settings shows whether each network card is on DHCP or a static address, and can change it with the gateway and DNS servers. A wrong address cuts the server off, so the change goes back by itself after 3 minutes unless you open the portal at the new address and press **Keep this address**. If the address changed, then use **Apply the new address** so phones and TURN follow it.

Only cards managed with netplan and systemd-networkd can be changed here. A desktop system with NetworkManager is shown only. The previous files are kept in `/var/lib/aipbx-system/netcfg/backup` until the change is confirmed.

If you are locked out in spite of this, from the server's console: `sudo aipbx-netcfg revert`.

## Several network cards

SIP, web and TURN answer on every card, and each card has its own rules on the
[Firewall](network-ports.md) page. Any rule can be removed; the page says first when that can cut
your connection, calls or phones, and a firewall change does not come back by itself. coturn serves the addresses it finds when it starts: a confirmed
address change restarts it, and a card added another way needs `systemctl restart coturn`.
