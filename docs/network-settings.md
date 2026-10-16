# Network settings and a new server address

*Network & devices → Network settings* (admin only) collects the server's addresses in one place
and moves all of them in one step when the server's IP address changes.

## What the page shows

- **This server** (read only): host name, interfaces with their addresses, the default gateway,
  the DNS servers and the main address (the source of the default route). The server's own
  network is set up on the server (netplan); changing it from this page is planned
  ([roadmap 13](roadmap.md#13-network-settings-page)).
- **Server addresses**:
  - *This server's address on the local network* (`server_ip`): the address calls reach the
    server at; Asterisk advertises it as its RTP ICE candidate. Empty: the main address.
  - *Public address* (`pjsip_external_ip`): the address in front of NAT, for callers outside.
  - *Local networks* (`pjsip_local_net`): the networks Asterisk treats as inside NAT, written as
    `192.168.1.0/24`, comma separated.
  Saving marks the transports and RTP for *Apply*. These fields were on *PBX settings* before.
- **Addresses for desk phones**: SIP server, outbound proxy, provisioning address, syslog server,
  NTP server and the allowed networks. Saving rewrites the phone files at once. These fields were
  in *Phones → Provisioning settings* before; see [Desk phones](phones.md).

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
sudo aipbx-readdress 10.8.0.12 10.9.0.5          # shows what would change
sudo aipbx-readdress 10.8.0.12 10.9.0.5 --yes    # applies it
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
