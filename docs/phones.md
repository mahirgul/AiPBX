# Desk phones (provisioning)

**Network & devices → Phones** sets up SIP desk phones without typing anything on them: the phone fetches its
configuration over HTTPS from AiPBX and registers with the right extension, keys and settings.
Supported: **Yealink**, **Grandstream**, **Fanvil**, **Snom**, **Cisco SPA**, **Poly** and **NEC** DT700/DT800 (models listed
on the page).
Cisco's enterprise **7900/8800 series** (CallManager firmware) are supported too, but they need firmware, an
Asterisk patch and a folder of shared files that AiPBX cannot ship: see [Cisco 7900 / 8800 series](cisco-callmanager.md).
A 7900 that is still running SCCP firmware is switched to Cisco's SIP load as part of provisioning — name the
load for each model on the settings tab, and restart the phone once so it reads its file.

The page is admin-only, like *Users* and *Firewall*: the provisioning URLs it shows hand out the
SIP password of the extension.

## Adding phones

| Way | How |
|-----|-----|
| One phone | **+** → MAC address (any format: `00:15:65:AA:BB:CC`, `001565aabbcc`), model, extension |
| Many phones | **CSV import**: one phone per line, `mac,model,extension` (`;` or tab also work, a header line is skipped). Model is the name on the page (`Yealink T46U`), its short name (`T46U`) or its key (`yealink-t46u`). A known MAC is updated. |
| Waiting phones | A phone that asked by MAC address but is not known yet (see *Zero touch* below) is listed under **Waiting phones** with its MAC, vendor, IP and User-Agent; pick a model and an extension and press ✓. |
| Found on the network | The discovery engine (*Phones found on the network*, below) records phones it sees on the network without asking anybody; press ✓, ignore them, or let an extension pool provision them. |

Each phone gets:

- a **provisioning URL** with a random token: `https://<portal>/provision/<token>/` (*Copy URL*);
- its own **web admin password** (generated, kept encrypted; the lock button shows it). It is
  written to the phone, so the factory password stops working after the first provisioning.

**New URL** replaces the token; the old URL stops working at once (use it when a URL leaked).

## Phones found on the network (discovery)

A phone does not have to be typed in. The **discovery engine** (`engine/`, a Python module the install puts on a
5-minute cron entry) looks at the network and records what it found under *Discovered on the network* at the
top of the page; from there a phone is one click, or none at all.

| Source | Sends packets? | What it sees |
|--------|----------------|--------------|
| AiPBX's DHCP lease file | no | phones that took an address from AiPBX's own DHCP server |
| the ARP table | no | anything on the local segment that talked recently |
| dnsmasq's DHCP log | no | phones that asked for DHCP, even after the lease file was rotated or dnsmasq restarted |
| a bounded ARP sweep | **yes** | the same as the ARP table, but for every address, so an idle phone answers too |

Only a MAC whose prefix is a desk-phone vendor (`PhoneModels::OUI`) counts: laptops, printers, access points and
the PBX itself are filtered out, and anything unrecognised is not even recorded. The sweep is off by default
(it is the only source that puts traffic on the wire) and bounded to 1024 addresses per network, two networks
and about 30 seconds per run; the rest of a network is left for the next run.

**What happens to a discovered phone:**

| State | How it gets there |
|-------|-------------------|
| **New** | the engine saw it. On the page: ✓ *Provision this phone* (pick the model and an extension), or ✗ *Ignore*. |
| **Ignored** | an administrator said no. It stays on the page, and the automatic step never touches it again. |
| **Provisioned** | a phone exists for that MAC. It is shown as a phone that is on the network. |
| **Was a phone, now deleted** | the phone was deleted. Nothing re-creates it on its own; ✓ provisions it again if you mean it. |

**Automatic provisioning** happens only when you ask for it, in *Phones → Discovery*:

- **Extension pool** (e.g. `5000-5099`): a discovered phone takes the next extension in the pool that is an
  active SIP user without a phone yet. Empty: every phone is only recorded, and you press ✓ yourself.
- **Model per vendor** (e.g. `yealink=yealink-t46u`): the MAC tells us who made the phone, never which model it
  is, so a vendor without a line here is never provisioned automatically.

A MAC that is already a phone is **never** provisioned again — not by the automatic step, and not by ✓, which
refuses and says so. Only a phone seen in the last 24 hours is taken automatically, at most 25 per run, and the
whole path goes through the same code as the +, the CSV import and the waiting list, so tokens, admin passwords,
vendor templates and the TFTP export all keep working the same way.

On a shell: `php web/bin/aipbx_phone_discovery.php status` shows what the engine saw and what the settings would
do about it (read-only), and `python3 -m aipbx_engine check` (as `www-data`) verifies the engine could run.

## Pointing a phone at AiPBX

**Per-phone URL (works everywhere).** Enter the copied URL as the provisioning / auto-provision
server in the phone's web interface:

| Vendor | Where |
|--------|-------|
| Yealink | *Settings → Auto Provision → Server URL* |
| Grandstream | *Maintenance → Upgrade and Provisioning → Config Server Path* (HTTPS) |
| Fanvil | *Maintenance → Auto Provision → Server Address* (HTTPS) |
| Snom | *Setup → Advanced → Update → Setting URL*: the copied URL followed by `snom-{mac}.xml` |
| Cisco SPA | *Admin login → Advanced → Voice → Provisioning → Profile Rule*: the copied URL followed by `spa$MA.xml` |
| Poly | *Settings → Provisioning Server*: server type HTTPS, the copied URL as server address |
| NEC | The phone's file (configuration) server setting, or DHCP option 66: the copied URL |
| Cisco 79xx/88xx (CallManager) | Not a URL: these phones ask their server for `SEP<MAC>.cnf.xml` by name, so they are pointed at the PBX by DHCP **option 150** and read the file from the TFTP folder. See [Cisco 7900 / 8800 series](cisco-callmanager.md). |

The phone then asks for its own file under that URL; AiPBX checks that the MAC in the file name
is the phone's:

| Vendor | Per-phone file(s) | Shared file (not served) |
|--------|-------------------|--------------------------|
| Yealink | `<mac>.cfg` | `y0000000000xx.cfg` |
| Grandstream | `cfg<mac>.xml` (or `cfg<mac>`) | `cfg.xml` |
| Fanvil | `<mac>.cfg` | `f0<model>.cfg` |
| Snom | `snom<model>-<MAC>.htm` (plug and play) or `snom-<mac>.xml` (setting URL) | `snom<model>.htm` |
| Cisco SPA | `spa<mac>.xml` | `spa<model>.cfg` |
| Poly | `<mac>.cfg` (master file, names the next one) and `phone<mac>.cfg` (settings) | `000000000000.cfg` |
| Cisco 79xx/88xx | `SEP<MAC>.cnf.xml` | `SIPDefault.cnf.xml`, `Softkeys.xml`, `DialTemplate.xml` |
| NEC | `dt-<mac>.cfg` (master), `dt-<mac>-phone.cfg`, `dt-<mac>-local.cfg` | `dt-000000000000.cfg`, `dt-sip.cfg` |

Cisco SPA phones only ask for a MAC-based name when their Profile Rule says so, so zero touch
(below) needs the Profile Rule set once.

**Zero touch (DHCP option 66).** Fill **Allowed networks** in *Network settings* with your
phone networks (e.g. `192.168.10.0/24`) and hand out `https://<portal>/provision/` (or the
*provisioning address*, below) as option 66.
Phones inside these networks then ask for their MAC-based file directly; a known MAC gets its
configuration, an unknown one appears under *Waiting phones*.
AiPBX can also be that DHCP server for a phone network or VLAN, or serve TFTP next to your own
DHCP server: see [Network services](network-services.md).

## Server addresses (networks without DNS)

Phone networks often have no DNS or no internet access, so a phone cannot resolve the PBX's name.
*Network & devices → Network settings → Addresses for desk phones* takes IP addresses instead (empty: the domain):

| Field | Written to / used for |
|-------|-----------------------|
| **SIP server (registration)** | the registrar in every vendor's file |
| **Outbound proxy** | the outbound proxy of the line (Yealink, Grandstream, Fanvil, Snom, Poly, Cisco SPA, NEC), same port as the SIP server; empty: none |
| **Provisioning address** | where phones fetch their files, e.g. `10.23.3.70` (no scheme: HTTP), `10.23.3.70:8080` or `https://pbx.example.com:8443`: the per-phone URLs on the page and DHCP option 66/160 |
| **NTP server** | a name or an IP address (NEC: IP address only); this server's IP with [NTP server](network-services.md#ntp-server-time) on under Network services |
| **Syslog server** | where phones send their log (UDP 514), an IPv4 address (NEC); this server's IP with *Syslog* on under Network services: read on [Network & devices → Phone logs](network-services.md#syslog-phone-logs) |

Re-provision the phones after changing them. `/provision/` is also answered over plain **HTTP**
(no redirect to HTTPS), for phones that cannot do HTTPS to an IP address or do not follow a
redirect; the allowed networks and the per-phone token still apply. With an IP over HTTPS the
certificate does not match the address: most phones accept that, some need HTTP.

**TFTP / FTP.** For phones that read their file from TFTP or FTP (NEC), tick *Also write the
phones' files into the TFTP/FTP folder*: every assigned phone's file (`dt-<mac>.cfg`, `<mac>.cfg`,
…) is written into the folder of [Network services](network-services.md) and kept current — right
after a change on this page and every 5 minutes (`bin/provision_tftp.php`, so a SIP password
changed elsewhere follows). Files of removed phones are deleted, and all of them when the option
is switched off. The files contain the SIP password; TFTP and FTP answer only the allowed networks.

## What is written to the phone

| Setting | Value |
|---------|-------|
| SIP server | the *SIP server* address of the provisioning settings, else the installation's domain (the same setting as the links in e-mails: `PORTAL_DOMAIN`, else the external domain on *PBX Settings*), never the address the phone used |
| Outbound proxy | the *Outbound proxy* of the provisioning settings (empty: none) |
| Transport | UDP (port 5060) or TLS (5061), from *PBX Settings* ports |
| Account | the extension number as user and authentication name, its SIP password, the user's name as display name |
| SRTP | off, optional or required |
| Codecs | the selected ones, in the order G.722, PCMA, PCMU, G.729, Opus |
| Time | NTP server and the offset of the chosen time zone |
| Language | the phone's display language |
| Voicemail | `*97` on the message key |
| Keys | the user's key layout (below) |
| Admin password | the phone's own web admin password |

**Show the phone's file** (the file icon in a phone's row) opens exactly what the phone downloads, one
tab per file (Poly: the master file and the settings file), with a copy button. The SIP and admin
passwords are hidden; users who may edit phones can show them, which is written to the audit log.
A phone without an active user gets no file, so the button is shown only for assigned phones.

Changes reach a phone at its next configuration download. **Re-provision** (↻) sends it a SIP
NOTIFY through Asterisk so it reloads at once; **Reboot** (⏻) restarts it. Both need the phone to be
registered.

| Vendor | Re-provision (`pjsip_notify.conf`) | Reboot |
|--------|-----------------------------------|--------|
| Yealink | `yealink-check-cfg` | `yealink-reboot` |
| Grandstream | `grandstream-check-cfg` | — (restarts after re-provisioning anyway) |
| Fanvil | `fanvil-check-cfg` | `fanvil-reboot` |
| Snom | `snom-check-cfg` | `snom-reboot` |
| Cisco SPA | `sipura-check-cfg` (`resync`) | `linksys-cold-restart` |
| Poly | `polycom-check-cfg` | — (restarts by itself when the new settings need it) |
| NEC | `nec-check-sync` (the phone restarts and reads its file) | — |

## Key layout

**Phone keys** (from the Phones page, or the ⊞ button on *Extensions*) draws the keys of the user's
phone model, plus one page per expansion module where the model takes one. Each key has a type,
a number and a label:

| Type | Number | On the phone |
|------|--------|--------------|
| BLF | extension to watch | lamp shows free / ringing / busy; pressing calls, a ringing key picks up with `*21` + extension |
| Speed dial | any number | calls it |
| Call park | parking slot | parks / retrieves |
| Do not disturb | — | toggles DND |
| Line | — | the account's line key (usually key 1) |

Empty keys are cleared on the phone, so a key removed on the page disappears from the phone too.
**Copy to other users** gives the same layout to several users at once ("the same 15 keys for the
sales team").

Limits in this first version:

- **Grandstream:** only the first 7 multi-purpose keys are written (the P-values of later keys and
  of the GXP2200EXT module differ by model and firmware).
- **Fanvil:** expansion modules are not written yet; codecs stay at the phone's defaults.
- **Snom:** keys are one list (`fkey` 0…): the D7 module keys follow the phone's own keys. Park
  keys are `orbit` keys.
- **Cisco SPA:** line keys use *Extended Function* (`fnc=blf+sd+cp`, `sd`, `prk`, `dnd`), SPA500S
  keys `Unit_<n>_Key_<k>`. Three preferred codecs at most; Opus is not offered.
- **Poly:** keys use line key reassignment; BLF keys watch the number, speed dial and park keys
  are *automata* entries of the same list (they dial without a lamp). Expansion module keys follow
  the phone's own keys.
- **NEC:** the file follows NEC's data file template for DT700/DT800 group by group. The phone
  checks every value against its schema and rejects the **whole file** when one is out of range
  (syslog: *XmlConfig: configuration is invalid*, then it restarts), so AiPBX keeps to the schema:
  the NTP and syslog servers are written only as IP addresses (a name is left empty), the display
  name is the extension (no spaces allowed), all six codecs get a distinct rank, the time zone is
  whole hours with daylight saving off. The admin password is **not** written: the phone's menu
  takes digits only, so it keeps its own (`6633222` by default). Telnet stays on. Keys are not
  written (the phone keeps its own line key layout). The phone's firmware files are NEC's and are
  not part of AiPBX. Turkish is not among the phone's languages, English is used instead.
  The phone reads three files: `dt-<mac>.cfg` is its **master file** (as NEC's
  `dt-000000000000.cfg`) and only names the data file `dt-<mac>-phone.cfg`, which holds the
  settings; a data file under the master name is not applied (the phone logs *data config file
  name is unavailable*). Line ends are Unix (LF); the phones read them as well as NEC's CRLF. After
  the data file the phone reads `dt-<mac>-local.cfg`, its *local override* (settings changed on the
  phone's menu); AiPBX writes it too, in the phone's own format with the same values, so an old
  local file cannot override AiPBX's settings and the phone does not log a missing file.
  The local file starts with `<VERSION Version="n"/>`: the phone applies it only when `n` is higher
  than the version it applied last. AiPBX keeps `n` per phone and raises it by one whenever any of
  the three files changes (a setting, the SIP password, the server address …); an unchanged file
  keeps its number. Numbering starts at 3, above the version-2 files the first phones were set up
  with. When files are copied to another file server by hand, copy all three after every change.
  The file server (where the phone fetches `dt-<mac>.cfg`) cannot be set from the file: set it in
  the phone's menu (FTP, user `NECSDT700`) or by DHCP.

## Logging and protection

- Every request is logged (time, MAC, file, result, IP, User-Agent); the last 50 are on the page,
  rows are kept 90 days. Passwords and tokens are never logged.
- **Rate limit:** requests per minute per IP address (default 60); more get HTTP 429.
- **Allowed networks:** when set, phones outside them get nothing, not even with a valid URL.
  When empty, only the per-phone URLs work (from anywhere, e.g. home workers), and MAC-based file
  names are refused.
- Apache's access log records request paths, which contain the token of a per-phone URL. Keep
  access to the server's logs as restricted as access to the portal.

## Adding a vendor

The templates live in `web/src/services/phones/`: a `PhoneTemplate` subclass per vendor (file
names, rendering, NOTIFY events), the models with their key counts in `PhoneModels`, and the
NOTIFY sections in `asterisk-config/pjsip_notify.conf`. Values go through `PhoneTemplate::clean()`
(line-based files) or `PhoneTemplate::xml()` (XML), so a name or label cannot add settings.
