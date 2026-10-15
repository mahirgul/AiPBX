# Network services (DHCP, TFTP and FTP for desk phones)

**PBX → Network services** lets desk phones find AiPBX by themselves: the DHCP server hands out
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

**Extra DHCP options** (DHCP mode): one `code,value` per line, e.g. `66,ftp://192.168.10.2/` for
phones that should fetch from FTP. Bytes are written in hex with colons, e.g. `43,0a:04:c0:a8:0a:02`;
without colons a value is sent as text. An option with the same code
as one above replaces AiPBX's value (66, 160, 150, 42, 3, 6). Extra options are sent even when the
phone does not ask for them (dnsmasq `dhcp-option-force`). Quotes, `#`, backslashes and line
breaks are refused: the dnsmasq file is written as root.

For the MAC-based `/provision/` address to answer, the phone network must be in **Allowed
networks** of the provisioning settings (*PBX → Phones*).

**Leases** lists every device that got an address. Phones among them (recognised by the MAC
prefix) that are not known yet appear under **Waiting phones** on the Phones page, so they can be
assigned an extension before they even ask for their configuration.

## TFTP

TFTP serves the files uploaded under *TFTP files* on the page (boot files, firmware). File
names that contain a MAC address are refused for upload: those would be per-phone configuration
files. Shared files such as `y000000000108.cfg` or `000000000000.cfg` are allowed. The per-phone
files are written into the folder by AiPBX itself when *Also write the phones' files into the
TFTP/FTP folder* is ticked on *PBX → Phones → Provisioning settings* (see
[Desk phones](phones.md#server-addresses-networks-without-dns)).

TFTP answers only the **Allowed networks** of the provisioning settings (plus, in DHCP mode, the
phone network itself): the firewall opens UDP 69 for these networks only. In DHCP mode TFTP
listens on the chosen interface only.

## FTP

Some phones fetch their files over FTP instead of TFTP, e.g. NEC DT700/DT800 (user `NECSDT700`).
**FTP server (read only)** is switched on separately from DHCP and TFTP and serves the same
folder as TFTP:

- **Anonymous** login and the fixed user **`NECSDT700`** work without a password (NEC phones log
  in with it; any password they send is accepted).
- **Extra FTP users** can be added on the page with a password (at most 20); tick one to remove it,
  or enter an existing name to set a new password. Only a SHA-512 hash of the password is stored.
- Everybody may only list and download. Nothing can be uploaded, changed or deleted, and nobody
  gets out of the folder. No FTP user is a system account: a server login such as `root` is refused.
- FTP is not encrypted, and anybody on the allowed networks can read the files. Per-phone files
  with SIP passwords are only there when you switch that on (above).
- TFTP and FTP use the same folder (`/var/lib/aipbx/tftp`).
- The firewall opens TCP 21 and the passive ports 40000–40049 for the same networks as TFTP
  (the allowed networks, plus the phone network in DHCP mode). Active mode works too.

## How it works

- dnsmasq (package `dnsmasq-base`) runs as its own unit, `aipbx-dnsmasq`, with DNS switched off
  (`port=0`), so it does not collide with the system resolver or other dnsmasq instances.
- The configuration `/var/lib/aipbx-netsvc/dnsmasq.conf` is written by `aipbx-priv netsvc apply`
  from validated values, in a folder only root can write: a dnsmasq file can run commands as
  root, so the portal never writes it itself. Leases are in `/var/lib/aipbx-netsvc/dnsmasq.leases`,
  TFTP files in `/var/lib/aipbx/tftp`.
- Firewall: UDP 67 (the `dhcp` service) is opened in the firewalld zone of the chosen interface,
  UDP 69 (the `tftp` service) for the allowed networks; *Off* removes both again.
- FTP is vsftpd (packages `vsftpd`, `libpam-pwdfile`) in its own unit `aipbx-vsftpd`; the
  package's own `vsftpd` unit is masked. `aipbx-priv netsvc ftp-on` writes
  `/var/lib/aipbx-netsvc/vsftpd.conf` and the password file `ftp.passwd` of the extra users
  (root only, SHA-512 hashes; the portal hands over only the hashes). The PAM service
  `/etc/pam.d/aipbx-ftp` lets `NECSDT700` in and checks everybody else against that file only.
  Transfers are logged in `/var/log/aipbx-ftp.log`.
- The DHCP check is `/usr/local/sbin/aipbx-dhcp-probe <interface>`: one DHCP discover, the offers
  of the next 4 seconds are listed. Nothing is leased.
