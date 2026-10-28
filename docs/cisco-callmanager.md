# Cisco 7900 / 8800 series (CallManager firmware)

Cisco's **enterprise desk phones** — 7941G, 7942G, 7945G, 7961G, 7962G, 7965G, 7975G and 8841, 8851,
8861, 8865 — are not the [Cisco SPA sets](phones.md) AiPBX supports alongside Yealink and the rest.
They ship with *CallManager* (CUCM) firmware, they speak a Cisco-flavoured SIP, and they are configured by
one big XML file called `SEP<MAC>.cnf.xml`.

They work with Asterisk, and the community project **[usecallmanager.nz](https://usecallmanager.nz/)**
documents how: it publishes the Asterisk patch that adds what Cisco's firmware expects (BLF, call-forward
and DND synchronisation, pickup, parking), a sample `tftpboot` folder with the shared files these phones ask
for, and a full reference for every element of `SEP<MAC>.cnf.xml`.

AiPBX writes the per-phone file for you (**Phones → +**, model `Cisco 79xx/88xx`), from the same extension,
password, codec and key data it uses for every other vendor. Everything around it — the firmware, the
Asterisk patch and the shared files — is a one-time setup on your own server, described below.

## What you need before a phone works

| | What | Where it comes from |
|---|---|---|
| 1 | **Cisco SIP firmware** for each model | Cisco's software download page (a free account). The files are `SIP<version>.loads` plus `.sbn`/`.loads` companions. AiPBX cannot ship them: they are Cisco's. |
| 2 | **The Asterisk patch** (for BLF, forwards, pickup) | [usecallmanager.nz/patching-asterisk.html](https://usecallmanager.nz/patching-asterisk.html) — patch, rebuild, reinstall Asterisk. Without it the phones register and make calls, but the shared-line features are missing. |
| 3 | **The shared files** (`Softkeys.xml`, `DialTemplate.xml`, ringtones, locales) | The sample `tftpboot` folder from [usecallmanager.nz/tftp-provisioning.html](https://usecallmanager.nz/tftp-provisioning.html), or your own. |
| 4 | **A firmware load name** per model | The `.loads` file you downloaded, without the extension: e.g. `SIP42.9-4-2SR3-1S`. |

> **Licences.** The Asterisk patch is GPL-2.0 and the sample `tftpboot` files are CC BY 4.0 (attribution).
> Cisco's **firmware** is Cisco's: download it from Cisco and do not redistribute it. AiPBX ships none of
> these files — that is why the shared files are yours to copy in once (step 3).

## 1. Put the shared files where the phones look for them

**Phones → settings → *Also write the phones' files into the TFTP/FTP folder*** does two things: it keeps
every assigned phone's `SEP<MAC>.cnf.xml` current in the TFTP/FTP folder, and it makes AiPBX reference the
shared files below by name (it only references files that are actually there).

Copy the sample folder's contents into the same directory:

```sh
# The folder on PBX → Network services → TFTP/FTP (default /var/lib/aipbx/tftp)
sudo cp -a /path/to/tftpboot/. /var/lib/aipbx/tftp/
sudo chown -R www-data:www-data /var/lib/aipbx/tftp
```

The two files the per-phone configuration points at, when present, are `Softkeys.xml` (the soft-key
definitions) and `DialTemplate.xml` (dial-plan timeouts). Ringtones and wallpapers are picked by the phone
from the same folder; the firmware files go here too.

> **The folder is a secret.** `SEP<MAC>.cnf.xml` contains the extension's SIP password, and TFTP has no
> authentication at all. AiPBX's TFTP/FTP services answer only the *allowed networks* you set in
> **Network settings** — keep that list to your phone network, and never point a public interface at it.

## 2. Tell the phones where the server is

Cisco phones take their configuration server from DHCP — **option 150** (the Cisco convention) or option 66:

| Your DHCP server | What to set |
|------------------|-------------|
| AiPBX itself (**Network services → DHCP**) | Add the extra option `150,<this server's IP>` on the page. Option 150 is already on the hint list for the company's own DHCP server. |
| Your own DHCP server | Option 150 = the PBX's IP address; option 66 (`tftp-server-name`) as the fallback, which is what these phones use when 150 has no value. |

Then pick the model per phone: **Phones → +**, MAC address and model (`Cisco 7942G`, `Cisco 8861`, …). If
the phone already asked for its file, it is on the same page under **Waiting phones** or **Discovered on
the network** — one click either way. The file a phone asks for is `SEP<MAC>.cnf.xml`; every other file name
is refused, so one phone can never collect another's configuration.

Firmware loads are set in **Phones → settings**:

- ***Firmware load per model*** — one `model=load` line per model, e.g.
  `cisco-cm-7962g=SIP42.9-4-2SR3-1S`. This is the list the **automatic switch** uses (section 3).
- ***Firmware load for Cisco 7900/8800 series*** — a single fallback written to every Cisco
  CallManager-series phone that has no line of its own in the list above. **Leave it empty** and the element
  is left out of the file: the phone keeps the load it is already running, which is the safe choice when you
  have a mixed fleet of firmware versions. A wrong load name is the one way to take these phones off the
  network, which is why the automatic switch ignores this field and only acts on a model that names its own
  load.

## 3. Switching a phone that is still on SCCP

A 7900-series phone that came from a CallManager (or from a box) is usually running the **SCCP** firmware it
left the factory with, and it is useless to AiPBX until it runs Cisco's **SIP** load. AiPBX does that switch
as part of provisioning:

1. Fill in **Phones → settings → *Firmware load per model*** with the SIP load for each 7900 model you own
   (`cisco-cm-7941g`, `cisco-cm-7942g`, `cisco-cm-7945g`, `cisco-cm-7961g`, `cisco-cm-7962g`, `cisco-cm-7965g`,
   `cisco-cm-7975g`).
2. Copy that release's **whole** firmware package into the TFTP/FTP folder — the `.loads` file and every
   `.sbn` it names. Do not rename them.
3. Provision the phone as usual (the **+** form on **Phones**, the Approve button on **Waiting phones**, or a
   CSV import). The load is written into its `SEP<MAC>.cnf.xml` as `<loadInformation>`.
4. **Restart the phone once.** That is the one step no server can do: a phone sitting on SCCP only reads its
   configuration file at boot. Power cycle it, or bounce its PoE port.

On that boot the phone asks for its file, sees a load that differs from the one in flash, downloads it from
the TFTP server, programs it and reboots onto SIP. The row on **Phones** then shows *SIP firmware taken*, and
the phone registers.

What AiPBX checks, and what it will not do:

- **The file set is checked before the load is written** (`CiscoFirmware`): AiPBX reads the load's `.loads`
  manifest and verifies that every file it names is in the TFTP folder. A phone pointed at a load it cannot
  download does not fail politely — it sits at *Upgrading* — so a missing file is reported instead.
- **A missing file is not a reason to skip the load.** The phone's row reads *SIP firmware not on this
  server* with the missing names in the tooltip. Copy the rest of the package in; the file the phone gets is
  already right.
- **Only the 7900 series is switched.** The 8800 series (8841/8851/8861/8865) has no SCCP enterprise
  firmware to leave: its enterprise firmware is SIP already.
- **Enterprise ↔ Multiplatform (MPP) is not automated.** Converting an 8800 to MPP firmware is a licensed
  Cisco migration with its own image and licence, not a load name — do it by Cisco's procedure or not at all.
- **No NOTIFY is sent.** A phone on SCCP is not registered with Asterisk, so there is nothing to send it a
  `check-sync`; that is why the restart is yours. Once it runs SIP, **↻** re-provisions it as usual.

A fleet that was provisioned before you filled in the list is armed with one command:

```sh
sudo -u www-data php /opt/aipbx/web/bin/aipbx_cisco_firmware.php
```

It prints each model's load, whether its files are on this server, what each phone's switch state is, and
which phones to restart. `--dir=/path` checks another folder, for a server whose phones fetch their firmware
from somewhere else. A manifest that cannot be read is reported as `unreadable` rather than passed as fine.

## 4. Keys, BLF and the rest

The key layout on **Phones → Phone keys** (or ⊞ on *Extensions*) is written as Cisco line keys:

| Key type | Written as |
|----------|------------|
| **Line** | `featureID` 9 with `lineIndex` — the registering line(s). Button 1 is the line when no layout was drawn, so a phone always registers. |
| **BLF** | `featureID` 21 (BLF speed dial), watching the extension; needs a hint in the dialplan and the Asterisk patch for the lamp updates ([Busy lamps](blf.md)) |
| **Speed dial** | `featureID` 2 with `speedDialNumber` |
| **Call park** | `featureID` 2 to the parking slot (pressing it picks the parked call up) |
| **Do not disturb** | *not written*: the feature id is not among the documented ones, so the key is skipped rather than guessed |

Two limits worth knowing:

- **Expansion modules (KEMs) are not written.** The 8800 KEMs need a `<deviceType>` per part number
  (`CP-8800-Audio`, `CP-8800-Video`, `BEKEM`) and a wrong one is rejected by the phone. Until that can be
  tested on real hardware, the keys on a KEM stay unconfigured.
- **The phone's own display language is English (US).** The file sets the built-in
  `English_United_States` locale; other languages need the locale files from the `tftpboot` folder, which
  AiPBX does not reference.

Re-provisioning (**↻** on the phone's row) sends the Cisco `check-sync` NOTIFY: the phone fetches
`SEP<MAC>.cnf.xml` again and applies it. There is no **Reboot** button for these models — they have no
NOTIFY that reboots them, and they restart by themselves when the new configuration needs it.

## 5. What AiPBX writes, and what you do on the phone

The generated file sets the server and SIP port (UDP 5060, or TLS 5061 from the transport setting), the
extension with its SIP password as `name`/`authName`/`authPassword`, the display name, `remotePartyID` (so a
connected line update works), NTP, the time zone for the phone's own time zone list, the messages key
(`*97`) so the voicemail lamp works, G.722 on or off, and the key layout above.

The **web admin password** AiPBX generates per phone is *not* written to these models: Cisco only accepts
`adminPassword` from the file on the 8821, so a 79xx/88xx keeps its own. Change it on the phone itself
(Settings → Administration → Password) if the factory default is unacceptable.

SIP-TLS on these phones also needs a certificate the phone trusts (the ITL/LSC dance in the
`usecallmanager.nz` secure-provisioning chapter). The transport setting is written, but treat TLS as
untested here: UDP is the path that works out of the box.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| The phone reboots and never registers | Is it running a **SIP** load and not SCCP? Its own status screen says which. A 7900 still on SCCP needs the switch in section 3 (and the restart that comes with it); AiPBX does not run chan-sccp, so a phone left on SCCP never registers. |
| A 7900 sits at *Upgrading* | It is asking for a firmware file the server does not have: the row on **Phones** names what is missing, and `aipbx_cisco_firmware.php` lists the whole set. Copy the rest of that release's package into the TFTP folder — do not rename the files, and do not use another model's package (the loads are signed per model). |
| It has a name but no extension | The file was not fetched: DHCP option 150, or the TFTP folder's owner and the allowed networks. `PBX → Phone logs → TFTP/FTP` shows every fetch the PBX answered. |
| It fetched the file and stayed on the old settings | The `loadInformation` you set may be a load you do not have. Clear it and re-provision (↻). |
| The lamp of a BLF key never changes | A hint in the dialplan and the usecallmanager Asterisk patch — both are outside AiPBX's file. |
| It asks for `Softkeys.xml` / `DialTemplate.xml` and logs a 404 | Harmless (the phone uses its defaults), but copy the shared files in and re-provision to get the intended soft keys. |

Tested against: AiPBX 1.50.x, generated files verified structurally; not yet on physical 7900/8800 hardware
— see the [desk phones](phones.md) page for the rest of the provisioning system.
