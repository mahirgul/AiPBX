# Desk phones (provisioning)

**PBX → Phones** sets up SIP desk phones without typing anything on them: the phone fetches its
configuration over HTTPS from AiPBX and registers with the right extension, keys and settings.
Supported now: **Yealink**, **Grandstream** and **Fanvil** (models listed on the page).

The page is admin-only, like *Users* and *Firewall*: the provisioning URLs it shows hand out the
SIP password of the extension.

## Adding phones

| Way | How |
|-----|-----|
| One phone | **+** → MAC address (any format: `00:15:65:AA:BB:CC`, `001565aabbcc`), model, extension |
| Many phones | **CSV import**: one phone per line, `mac,model,extension` (`;` or tab also work, a header line is skipped). Model is the name on the page (`Yealink T46U`), its short name (`T46U`) or its key (`yealink-t46u`). A known MAC is updated. |
| Waiting phones | A phone that asked by MAC address but is not known yet (see *Zero touch* below) is listed under **Waiting phones** with its MAC, vendor, IP and User-Agent; pick a model and an extension and press ✓. |

Each phone gets:

- a **provisioning URL** with a random token: `https://<portal>/provision/<token>/` (*Copy URL*);
- its own **web admin password** (generated, kept encrypted; the lock button shows it). It is
  written to the phone, so the factory password stops working after the first provisioning.

**New URL** replaces the token; the old URL stops working at once (use it when a URL leaked).

## Pointing a phone at AiPBX

**Per-phone URL (works everywhere).** Enter the copied URL as the provisioning / auto-provision
server in the phone's web interface:

| Vendor | Where |
|--------|-------|
| Yealink | *Settings → Auto Provision → Server URL* |
| Grandstream | *Maintenance → Upgrade and Provisioning → Config Server Path* (HTTPS) |
| Fanvil | *Maintenance → Auto Provision → Server Address* (HTTPS) |

The phone then asks for its own file under that URL (`<mac>.cfg` for Yealink and Fanvil,
`cfg<mac>.xml` for Grandstream); AiPBX checks that the MAC in the file name is the phone's.

**Zero touch (DHCP option 66).** Fill **Allowed networks** in *Provisioning settings* with your
phone networks (e.g. `192.168.10.0/24`) and hand out `https://<portal>/provision/` as option 66.
Phones inside these networks then ask for their MAC-based file directly; a known MAC gets its
configuration, an unknown one appears under *Waiting phones*.

## What is written to the phone

| Setting | Value |
|---------|-------|
| SIP server | the installation's domain (the same setting as the links in e-mails: `PORTAL_DOMAIN`, else the external domain on *PBX Settings*), never the address the phone used |
| Transport | UDP (port 5060) or TLS (5061), from *PBX Settings* ports |
| Account | the extension number as user and authentication name, its SIP password, the user's name as display name |
| SRTP | off, optional or required |
| Codecs | the selected ones, in the order G.722, PCMA, PCMU, G.729, Opus |
| Time | NTP server and the offset of the chosen time zone |
| Language | the phone's display language |
| Voicemail | `*97` on the message key |
| Keys | the user's key layout (below) |
| Admin password | the phone's own web admin password |

Changes reach a phone at its next configuration download. **Re-provision** (↻) sends it a SIP
NOTIFY `check-sync` through Asterisk so it reloads at once; **Reboot** (⏻) restarts it (Yealink and
Fanvil; a Grandstream restarts after re-provisioning anyway). Both need the phone to be registered.

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
NOTIFY sections in `asterisk-config/pjsip_notify.conf`. Snom, Cisco SPA and Poly are planned the
same way.
