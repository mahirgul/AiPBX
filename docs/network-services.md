# Network services (DHCP and TFTP for desk phones)

**Admin → Network services** lets desk phones find AiPBX by themselves: the DHCP server hands out
the provisioning address (option 66), and TFTP serves boot files or firmware to phones that need
them. The configuration of each phone (with its SIP password) is always fetched over HTTPS from
`/provision/` (see [Desk phones](phones.md)), never over TFTP.

The page is admin-only. Everything is **off** by default.

## Modes

| Mode | What runs | Use it when |
|------|-----------|-------------|
| Off | nothing | the phones get the provisioning URL by hand or from your DHCP server |
| TFTP only | TFTP | your company's DHCP server stays; set the options from the table on the page there |
| DHCP for a phone network | DHCP on one interface, optionally TFTP | the phones sit on their own network or VLAN that has no DHCP server yet |

**A second DHCP server on a network breaks it.** Before switching DHCP on, the page sends a DHCP
discover on the chosen interface (*Check for DHCP servers*); saving does the same check again and
refuses when another server answers, unless *Switch on even if another DHCP server answers* is
ticked.

## DHCP for a phone network

Interface, first and last address, netmask, gateway, DNS servers (up to 3), NTP servers (option 42,
up to 3), lease time. The range must lie in the network of the chosen interface and must not
contain the server's own address. The phones get:

| Option | Value | For |
|--------|-------|-----|
| 66 | `https://<portal>/provision/` | Yealink, Grandstream, Fanvil, Snom, Cisco |
| 160 | `https://<portal>/provision/` | Poly |
| 150 | this server (when TFTP is on) | Cisco SPA |
| 42 | the NTP servers entered | all phones |

For the MAC-based `/provision/` address to answer, the phone network must be in **Allowed
networks** of the provisioning settings (*PBX → Phones*).

**Leases** lists every device that got an address. Phones among them (recognised by the MAC
prefix) that are not known yet appear under **Waiting phones** on the Phones page, so they can be
assigned an extension before they even ask for their configuration.

## TFTP

TFTP serves only the files uploaded under *TFTP files* on the page (boot files, firmware). File
names that contain a MAC address are refused: those would be per-phone configuration files.
Shared files such as `y000000000108.cfg` or `000000000000.cfg` are allowed.

TFTP answers only the **Allowed networks** of the provisioning settings (plus, in DHCP mode, the
phone network itself): the firewall opens UDP 69 for these networks only. In DHCP mode TFTP
listens on the chosen interface only.

## How it works

- dnsmasq (package `dnsmasq-base`) runs as its own unit, `aipbx-dnsmasq`, with DNS switched off
  (`port=0`), so it does not collide with the system resolver or other dnsmasq instances.
- The configuration `/var/lib/aipbx-netsvc/dnsmasq.conf` is written by `aipbx-priv netsvc apply`
  from validated values, in a folder only root can write: a dnsmasq file can run commands as
  root, so the portal never writes it itself. Leases are in `/var/lib/aipbx-netsvc/dnsmasq.leases`,
  TFTP files in `/var/lib/aipbx/tftp`.
- Firewall: UDP 67 (the `dhcp` service) is opened in the firewalld zone of the chosen interface,
  UDP 69 (the `tftp` service) for the allowed networks; *Off* removes both again.
- The DHCP check is `/usr/local/sbin/aipbx-dhcp-probe <interface>`: one DHCP discover, the offers
  of the next 4 seconds are listed. Nothing is leased.
