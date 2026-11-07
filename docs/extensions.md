# Extensions

**PBX → Extensions** lists every extension with its state (*Registered*, *On a Call*,
*Unreachable*), its SIP password and its settings. A user and an extension are one record: a user
added under **System → Users** with an extension appears here, and an extension added here is a user
who can sign in to the portal. Fax users are not listed here; they are on **Fax → Fax Units** (see
[Fax users](fax-users.md)).

Every change goes to Asterisk with **Apply** ([Portal basics](portal.md)). Each extension becomes
`/etc/asterisk/pbx/pjsip_ext_<number>.conf`.

## The table

- **Copy the SIP password** (the copy button beside the hidden password) puts it on the clipboard
  without showing it, for typing into a phone or softphone.
- **Phone keys** (⊞) opens the user's key layout for desk phones ([Desk phones](phones.md#key-layout)).
- **Remove** deletes the extension only; the user account stays.

## The form

| Field | Meaning |
|-------|---------|
| Full name, Extension no | Required. The name is shown to the people this extension calls. |
| SIP password | For desk phones, softphones, the web phone and the apps. Separate from the portal password. |
| SIP authentication | *Enabled* (default) asks every phone for the password. *Disabled* lets a device in by its From header alone: only for devices on a trusted network that cannot authenticate. |
| Internal / External Call CID | The number sent on internal and outbound calls. Which one an outbound route uses is set on the route; a fixed caller ID on the route comes first. |
| Calling permission group, Outbound route group | Which numbers the extension may dial and which outbound routes it uses ([Outbound routes](outbound-routes.md)). |
| Boss - Secretary group | Puts the extension into a boss/secretary pair. |
| Voicemail | Box on or off, PIN (empty: the extension number), e-mail with or without the recording, and when calls go there: no answer, unreachable or always ([Voicemail](voicemail.md)). |
| Send the caller in a header | Sends the caller in P-Asserted-Identity. On by default; some desk phones (NEC DT700/DT800) show "asterisk" as the caller without it. A caller who hides their number stays hidden. Turn it off only for a phone that has trouble with it. |
| Active | Off: the extension cannot register or call, and the user cannot sign in. |

### SIP settings

For the extension's desk phone or softphone only; the web phone and the mobile apps keep their own.
**Default** leaves the system's value, so leave a field on Default unless a phone needs otherwise.

| Setting | Values | Note |
|---------|--------|------|
| Transport | UDP, TCP, TLS | Default follows the transport the phone registered on, which suits nearly every phone. A chosen one must match the phone, or calls do not reach it. See [Transports](transports.md). |
| DTMF mode | RFC 4733, Inband, SIP INFO, Auto | How key presses are sent. |
| Media encryption | Off, SRTP (SDES) | The phone must offer SRTP too, otherwise calls fail. |
| Force rport, Rewrite contact, Symmetric RTP | Yes / No | NAT options, for a phone behind a router that rewrites its addresses. |
| Direct media | Yes / No | Lets audio flow between two phones directly, past the PBX. |
| Codecs, in order | opus, g722, alaw, ulaw, g729 | Comma separated. Empty: the system list. |
| Devices at once | a number | How many devices may register with this extension at the same time. |
| Check interval (seconds) | a number | How often Asterisk checks the phone. 0: never. Empty: the system value. |
| Prompt language | a language | The language of the prompts this extension hears. |

For anything not listed, use [Advanced Configuration](advanced-config.md), which can also apply a
setting to all extensions at once.
