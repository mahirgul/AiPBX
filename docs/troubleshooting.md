# Troubleshooting

## The Troubleshooting menu

The **Troubleshooting** menu in the portal (administrators only) gathers the tools for a problem with calls or phones.
Each is a page of its own; the health checks of the server stay under **System → Health checks**.

| Page | For |
|---|---|
| **SIP traffic** | `.pcap` captures of Asterisk's HEP SIP messages, calls grouped by Call-ID, and a ladder diagram |
| **Live SIP** | live stream of incoming/outgoing SIP messages from the current capture file |
| **Problem recording** | one try of the problem as a masked zip (1–60 minutes, selectable logs/state/SIP/RTP) to attach to a report |
| **Asterisk logs** | masked live-tail and search across `/var/log/asterisk` (`messages.log`, `security.log`, `queue_log`, etc.) |
| **Registrations** | which phone, browser and app of each extension is registered, and the state of the trunks |
| **Call quality** | packet loss and jitter of calls from RTCP ([Call quality](call-quality.md)) |
| **Phone logs** | the syslog of desk phones ([Desk phones](phones.md)) |
| **SIP capture settings** | enable/disable HEP SIP `.pcap` files, retention days, and total disk budget |

### SIP traffic

**Troubleshooting → SIP traffic**, **Live SIP**, and **SIP capture settings.** When SIP capture files are turned on under **SIP capture settings**, `aipbx-signaling` writes every SIP message Asterisk reports over HEP into `/var/lib/aipbx/sip-capture` as standard `.pcap` files (10 MB per file, kept for 1–10 days within the configured disk limit; Wireshark-compatible). From **SIP traffic** you can download `.pcap` files, inspect calls grouped by Call-ID, view each dialog as a ladder diagram with full headers/SDP, or watch traffic in real time under **Live SIP**.

### Problem recording

**Troubleshooting → Problem recording.** For a report about calls or phones. Choose the duration (**1 to 60 minutes**) and which data to include (Asterisk logs, error/system logs, state & config, SIP `.pcap`, and optionally RTP media `.pcap` — note that RTP captures carry raw speech audio and should only be enabled when debugging audio/DTMF issues), click **Start recording**, repeat the problem, then click **Stop and make the zip** and download it. Depending on the selected options, the zip holds:

| File | What |
|---|---|
| `asterisk.log` | Asterisk's log of that time at verbose level 4, with every SIP message (`pjsip set logger`) |
| `calls.tsv` | the call records (CDR) of that time |
| `queue_log.txt` | the queue events of that time |
| `state.txt` | phones, trunks, registrations, transports, queues, channels, codecs, version |
| `selftest.txt` | `aipbx-selftest` |
| `errors.txt` | warnings of Asterisk and the AiPBX services, the last portal errors (safe system logs are opt-in and off by default) |
| `config/` | the Asterisk files AiPBX generates (`/etc/asterisk/pbx`) |
| `sip.pcap` | the SIP messages of that time from the [SIP traffic](#sip-traffic) files, for Wireshark (only when enabled; 50 MB at most) |
| `rtp.pcap` | RTP media packets captured via `tcpdump` when RTP capture is explicitly enabled (sliced cleanly on packet boundaries) |

Before the zip is written (`sip.pcap` included), passwords, digest answers, `/provision/<token>` / `/meet/<token>` paths, `apbx_*` API tokens, `whsec_*` webhook secrets, `Authorization: Basic` headers, `PHPSESSID` cookies, and JSON secret fields are replaced by `***`, and every number of 7 digits or more loses its last 5 digits (`05321112233` → `053211xxxxx`); extensions, times, addresses and Asterisk ids stay. No stored call recordings, voicemail or faxes are included. The recording uses its own log channel and leaves the PBX's verbose level as it is. Zips are kept for 7 days in `/var/lib/aipbx/diag`; starting, stopping and downloading are in the audit log. The work is done by `aipbx-diag` (root, through `aipbx-priv`).

### Asterisk logs

**Troubleshooting → Asterisk logs** (administrators only) tails and searches `/var/log/asterisk` (`messages.log`, `security.log`, `queue_log`, `verbose`, `full`) directly from the portal with secrets (`Bearer`, `md5_cred`, `auth_pass`, `client_secret`, passwords) masked before search matching.

### Registrations

**Troubleshooting → Registrations** reads Asterisk when the page opens:

- **Extensions:** each registered desk phone, browser (web phone) and mobile app with its address, program
  (user agent), the round trip of its last check and when the registration runs out. Browsers and the apps come
  through the portal's WebSocket, so their address shows as `WebSocket`. An extension with nothing registered
  is one row, *Not registered*.
- **Trunks:** whether a registering trunk's registration is accepted, and whether the provider answers the
  PBX's checks (OPTIONS), with the round trip. An IP trunk has no registration.

The states: *Answering* (`Avail`), *Registered* (`NonQual`: registered, not checked, as for the apps),
*Not answering* (`Unavail`).

## Apply succeeds but nothing changes

- Check **Pending Changes**: a change that could not be written or loaded stays in the list; the error is shown on Apply and kept in the audit log.
- The portal must be able to write `/etc/asterisk/pbx/`. If Apply reports *"Read-only file system"*,
  the Apache override is missing — run `sudo bash /opt/aipbx/install.sh --upgrade`
  (see [Security → Apache sandbox](security.md#apache-sandbox)).
- Regenerate every configuration from the database: `cd /opt/aipbx/web && sudo php src/asterisk_sync.php`
  (reloads only, calls are not dropped).

## Portal actions fail with "sudo: I'm sorry www-data"

Ubuntu 26.04 uses `sudo-rs`, and the Apache unit hides `/etc/sudoers`. The installer's Apache override
fixes it; run `install.sh --upgrade` if the override is missing.

## A call is answered and immediately hung up

Usually an IVR/announcement sound is missing. Look for *"does not exist in any format"* in
`/var/log/asterisk/messages.log` and see [Sounds](sounds.md#where-sounds-live).

## Announcement or WAV upload fails

When uploading an announcement or hold-music file under **PBX → Sounds** fails, the cause is usually directory ownership/permissions on `/var/lib/asterisk/sounds/custom` (or `www-data` missing the `asterisk` group / Apache not restarted after adding it), a missing `sox` binary, or a file larger than `upload_max_filesize` / `post_max_size`.

Run this on the server to fix permissions and verify `www-data` can write and convert audio:

```bash
sudo usermod -aG asterisk www-data && sudo chown -R asterisk:asterisk /var/lib/asterisk/sounds/custom /var/lib/asterisk/moh && sudo chmod 2775 /var/lib/asterisk/sounds/custom && sudo find /var/lib/asterisk/sounds/custom -maxdepth 1 -type f -exec chmod 664 {} + && sudo find /var/lib/asterisk/moh -type d -exec chmod 2775 {} + && sudo find /var/lib/asterisk/moh -type f -exec chmod 664 {} + && sudo systemctl restart apache2 && sudo -u www-data test -w /var/lib/asterisk/sounds/custom && command -v sox >/dev/null && echo "OK"
```

If it still fails, start **Troubleshooting → Problem recording**, repeat the upload once, stop recording, and check `errors.txt` in the downloaded zip (portal PHP, web, file-write, and database errors are logged to Apache's error log and included there).

## Queue callbacks do not appear or stay pending

Use the [callback setup checklist](queue-callbacks.md#verify-the-setup) and
[symptom table](queue-callbacks.md#troubleshooting). Confirm that callbacks are enabled and applied,
the caller number is present, and the agent is assigned to the active queue. Both the periodic
invitation and a separate confirmation announcement must be selected.

A pending request is removed after an answered conversation with a queue agent ends, not after
an IVR answers or an agent's phone merely rings. Allow five seconds for the list to refresh.
Compare caller-number formats: `+` and `00` international prefixes match, but national and
international forms are not guessed from the last digits. Use consistent
[inbound number normalization](call-normalization.md) on the relevant trunks.

## A number is not routed ("No matching inbound route", congestion)

Find out what the trunk actually sends (next section), then check:

- the trunk's [DID trimming](trunks.md#inbound-did-normalization) — it also shortens transit numbers;
- that an inbound route exists in exactly that format, or an outbound route pattern matches it in the
  trunk's route group (`asterisk -rx "dialplan show <number>@from-trunk-<trunk>-route"`).

## Browser or app calls have no audio

Audio works on the server's LAN but not from a phone on mobile data, or calls through the relay are
silent: check the external IP under **PBX Settings**, the certificate (**Security → Certificates** —
TURNS fails silently with a self-signed one) and `relay-ip` in `/etc/turnserver.conf`. Full checklist:
[WebRTC, NAT and TURN](webrtc-nat.md#troubleshooting).

## One-way audio on remote SIP / desk phones (behind NAT)

When a remote desk phone has audio in only one direction (e.g., calling out to a cell phone is one-way, but an incoming call from a cell works, or vice versa):

1. **Disable SIP ALG on the remote router (most common cause):**
   Almost every home/office router has a "SIP ALG" (Application Layer Gateway) feature enabled by default. While intended to help SIP through NAT, SIP ALG frequently mangles the SDP media IP (`c=IN IP4`) or RTP ports with invalid values on outbound `INVITE` requests, causing one-way audio on outbound calls.
   - Access the router settings where the desk phone is located.
   - Look for **SIP ALG**, **SIP Passthrough**, or **SIP Transformation** in the router's Firewall / NAT settings and **turn it off**.
   - Reboot the router and restart the desk phone.

2. **Verify `direct_media=no` on both Extensions and Trunks:**
   If `direct_media` is enabled, Asterisk attempts to send a re-INVITE so the two endpoints stream RTP directly to each other without passing through Asterisk. Because the remote phone is behind NAT, it cannot stream RTP directly to an external carrier gateway.
   - **Extensions:** AiPBX templates default to `direct_media=no`. Verify under **Extensions → edit → Advanced SIP Options** that Direct Media is set to `No`.
   - **Trunks:** Go to **Trunks → edit trunk → Direct Media: No**, then click **Apply Changes**.

3. **Check PBX External IP & Media Address:**
   If the PBX runs in a datacenter or cloud (UpCloud, AWS, GCP, Hetzner, etc.):
   - Go to **PBX Settings → PJSIP & Net**.
   - Ensure **External IP / Hostname** and **External Media Address** are configured with the public IPv4 address of the PBX.
   - Ensure local subnets are listed in **Local Networks** so Asterisk knows which addresses are private.
   - Run the **NAT Diagnostics** panel on that page to verify what Asterisk advertises to outside endpoints.

4. **Verify Datacenter Firewall & RTP Port Range:**
   Asterisk carries audio over UDP ports defined in `/etc/asterisk/rtp.conf` (default: `10000–12999/udp` or `10000–20000/udp`).
   - Ensure the datacenter firewall, cloud security group, and local firewall (`ufw` or `nftables`) allow incoming UDP traffic on the entire RTP port range.
   - Ensure Asterisk has `strictrtp=yes` and `rtpkeepalive=5` (default in AiPBX) to keep NAT pinholes open.

5. **Capture and Inspect SIP/RTP Traffic:**
   ```bash
   asterisk -r
   pjsip set logger on
   ```
   Or capture a trace with `tcpdump` during a test call:
   ```bash
   sudo tcpdump -ni any -s0 -w /tmp/call_audio.pcap "port 5060 or portrange 10000-20000"
   ```
   Inspect in Wireshark: verify whether the SDP `c=IN IP4 <ip>` sent to the phone carries the public IP of the PBX, and whether RTP packets arrive from the phone's public IP and port.

## Incoming faxes fail (0 pages)

**Fax Inbox** shows the call as *FAILED* with no file (installations before 1.50.2 do not show it at
all), and `/var/log/asterisk/messages.log` has:

```
res_fax.c: channel 'PJSIP/<trunk>-...' refused to negotiate T.38
res_fax_spandsp.c: WARNING T.30 Non-ECM carrier not found
```

The trunk offers T.38 and the carrier refuses it; the fax then falls back to audio and fails there.
Switch T.38 off on that trunk (**Trunks → edit → T.38 UDPTL Fax Support: Inactive**, then **Apply**):
the fax goes as audio (G.711) from the start, which works with every carrier. New trunks have T.38
off since 1.50.2; turn it on only for a carrier that supports T.38.

Not every failed call is a lost fax: people often dial a fax number by mistake (a mobile caller,
a call of 10–20 seconds). The reason Asterisk gives is in the inbox under *FAILED*.

## Outgoing faxes fail or hang

- **"Permission denied" / nothing is sent on Ubuntu 26.04:** its AppArmor profile for Ghostscript
  blocks the fax directories, so the PDF cannot be converted. The installer allows them in
  `/etc/apparmor.d/local/gs` (1.50.4); run `sudo aipbx-update` or `sudo bash /opt/aipbx/install.sh --upgrade`.
- **A fax that cannot complete its handshake** is hung up two minutes after it started if not a
  single page has moved (the line used to stay busy for many minutes). A fax that is sending pages is
  never cut, however long it is. The reason is in **Fax → Outbound Fax** under *FAILED*.
- **Do not test by faxing your own number through the carrier.** Many carriers do not carry fax
  between the two legs of such a loop (neither as audio nor as T.38), so it fails although faxes to
  and from other numbers work. Test against a real fax machine elsewhere.
- **T.38 needs the UDPTL ports** (4100–4999/udp) open in the firewall; the installer opens them
  since 1.50.5.

## Capturing SIP

```bash
sudo timeout 120 tcpdump -ni any -s0 -w /root/sip.pcap "portrange 5060-5062"   # make the call meanwhile
sudo tcpdump -nr /root/sip.pcap -A | grep -E "^(INVITE|BYE|CANCEL|SIP/2.0) |^(From|To|Reason):"
```

The `INVITE` line shows the number a trunk sends, `From` the caller ID, and a `4xx/5xx` response or
`Reason` header why the other side rejected a call (e.g. *403 No Rates Found* = the carrier has no route
for that number). Open the file in Wireshark for RTP/audio analysis.

## Phones or calls get no answer now and then

If `messages.log` shows `The 'stasis/pool-control' task processor queue reached 500 scheduled tasks`,
Asterisk was overloaded at that moment and dropped the SIP requests that came in (no reply at all).
On releases before 1.76.0 the cause is the unused sample `/etc/asterisk/users.conf`: every *Apply*
left one more internal subscription behind until Asterisk restarted. Updating moves the file aside
(`users.conf.aipbx-unused`); on an older release, move it yourself and restart Asterisk when no call
is up. `asterisk -rx "core show taskprocessors" | grep -c manager:core` should then stay small after
several Applies.

## Phones stop registering after a wrong password

fail2ban bans the address after repeated failures. Check `fail2ban-client status asterisk`, unban with
`fail2ban-client set asterisk unbanip <ip>` and add internal networks to the ignore list on the
**fail2ban** page.

## The mobile app does not ring

A call to an extension rings its mobile app only while the app is registered (or, with push set
up under *System → Push*, after the push wakes it). Check the registration:

```
asterisk -rx "pjsip show contacts" | grep <ext>-mob-webrtc
grep '<ext>-mob-webrtc' /var/log/asterisk/security.log | tail
```

- No contact and no recent `SuccessfulAuth`: the app is not connected. Without push the call ends
  at once (CDR `NO ANSWER`, last application `Hangup`).
- The app refreshes its registration every 2 minutes. A `ChallengeSent` that is never followed by
  `SuccessfulAuth` means the app's SIP engine stopped answering; Android 1.0.65–1.0.69 could do
  this after a video call. 1.0.70 restarts the engine by itself within about 2 minutes; on an older
  version close the app (*Force stop*) and open it again.
- Xiaomi and some other phones also need *Autostart* allowed and battery saving set to *No
  restrictions* for the app.

## No notification for a new voicemail in the app

The notification needs the Android app 1.0.63 or newer (older versions ignore it) and, while the app
is not connected, push set up under **System → Push**. Every new message writes one line saying what
happened:

```bash
journalctl -t aipbx-voicemail -n 20
# new voicemail for 1001: app not connected; push to 1 device(s)
```

"push off" means push is not set up, "push to 0 device(s)" that the extension has no registered
phone (open the app once after signing in), and "failed: ..." carries Firebase's error.

## System Update fails with "Code was modified by hand"

This message comes from older updater versions. Since 1.60.19, modified files are backed up and
preserved unless the release changes the same path; then the release copy wins and the log names it.
Keep custom configuration outside the managed checkout regardless: use the portal, `/etc/ai-pbx.env`
and `*_custom.conf`. See [Update behavior](backup-and-rollback.md#2-updates-and-automatic-rollback-aipbx-update).

For the historical **1.34.0 – 1.34.2** issue, installations hit the error without anyone editing anything:
their installer made `web/bin/aipbx_ops.php` executable, and the updater of those
versions counts that as a change. Restore the file once, then update as usual:

```bash
sudo git -C /opt/aipbx checkout -- web/bin/aipbx_ops.php
sudo aipbx-update
```

The historical 1.50.2 fix ignores file-mode-only changes. If an older updater lists other files,
save and inspect their changes before restoring anything; do not discard local work blindly.

## Live call events, Connections & Webhooks (Odoo / CRM)

- **No pop-ups or call records arriving in Odoo / CRM:**
  1. Check that both background services are running:
     ```bash
     systemctl status aipbx-call-events aipbx-webhook-worker
     journalctl -u aipbx-call-events -u aipbx-webhook-worker -n 50 --no-pager
     ```
  2. Test live AMI event parsing without daemonizing:
     ```bash
     sudo systemctl stop aipbx-call-events
     sudo -u www-data php /opt/aipbx/web/bin/call_events.php --daemon --print
     ```
     Place one internal and one external call and verify `call.ringing`, `call.offered`, `call.answered`, `call.completed` appear, then start the service again (`sudo systemctl start aipbx-call-events`).
  3. Under **Integration → Connections**, click **Test** on the connection to verify HMAC-SHA256 webhook delivery (`webhook.test`). Private/loopback/CGNAT targets (`100.64.0.0/10`, `10.0.0.0/8`, `192.168.0.0/16`, `127.0.0.0/8`) are blocked by SSRF protection unless `ALLOW_PRIVATE_WEBHOOKS=1` is set in `/etc/ai-pbx.env`.
  4. If REST API calls (`POST /api/v1/calls/originate`, `/api/v1/contacts`, `/api/v1/recordings/{id}`) return `403`, verify the token's IP/CIDR allowlist and scopes under **Integration → Connections** or **REST API Keys**.

## Phonebook caller name (`CALLERID(name)`) or web caller card missing

- Verify local loopback lookup returns `NAME:<Display Name>` (not `NONE`):
  ```bash
  curl -s "http://127.0.0.1/api/phonebook_name.php?num=05321112233"
  ```
- If it returns `NONE`, check **Phonebook → Match digits** (`caller_match_digits`) and confirm the contact's number matches the last N digits.
- If the web phone's caller banner does not link to `/phonebook`, confirm the user's role has **Phonebook** (`view` / `edit`) permission under **System → Roles**.

## AI Assistant / Local AI / Gateway errors

- Check service state and logs:
  ```bash
  systemctl status aipbx-ai
  journalctl -u aipbx-ai -n 50 --no-pager
  sudo aipbx-selftest
  ```
- If local models fail or time out, verify RAM/disk and Ollama/runtime status under **AI → Local models**; for cloud providers, verify the active account key and test button under **AI → Cloud AI**.

## Useful commands

```bash
asterisk -rx "pjsip show contacts"         # registered phones and trunk reachability
asterisk -rx "pjsip show endpoint <name>"  # endpoint settings
asterisk -rx "core show channels"          # active calls
tail -f /var/log/asterisk/messages.log
systemctl status asterisk apache2 nginx mariadb coturn aipbx-chat aipbx-call-events aipbx-webhook-worker aipbx-ai
```
