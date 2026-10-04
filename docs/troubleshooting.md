# Troubleshooting

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

## A number is not routed ("Eslesen gelen rota bulunamadi", congestion)

Find out what the trunk actually sends (next section), then check:

- the trunk's [DID trimming](trunks.md#inbound-did-normalization) — it also shortens transit numbers;
- that an inbound route exists in exactly that format, or an outbound route pattern matches it in the
  trunk's route group (`asterisk -rx "dialplan show <number>@from-trunk-<trunk>-route"`).

## Browser or app calls have no audio

Audio works on the server's LAN but not from a phone on mobile data, or calls through the relay are
silent: check the external IP under **PBX Settings**, the certificate (**Security → Certificates** —
TURNS fails silently with a self-signed one) and `relay-ip` in `/etc/turnserver.conf`. Full checklist:
[WebRTC, NAT and TURN](webrtc-nat.md#troubleshooting).

## Capturing SIP

```bash
sudo timeout 120 tcpdump -ni any -s0 -w /root/sip.pcap "portrange 5060-5062"   # make the call meanwhile
sudo tcpdump -nr /root/sip.pcap -A | grep -E "^(INVITE|BYE|CANCEL|SIP/2.0) |^(From|To|Reason):"
```

The `INVITE` line shows the number a trunk sends, `From` the caller ID, and a `4xx/5xx` response or
`Reason` header why the other side rejected a call (e.g. *403 No Rates Found* = the carrier has no route
for that number). Open the file in Wireshark for RTP/audio analysis.

## Phones stop registering after a wrong password

fail2ban bans the address after repeated failures. Check `fail2ban-client status asterisk`, unban with
`fail2ban-client set asterisk unbanip <ip>` and add internal networks to the ignore list on the
**fail2ban** page.

## Useful commands

```bash
asterisk -rx "pjsip show contacts"         # registered phones and trunk reachability
asterisk -rx "pjsip show endpoint <name>"  # endpoint settings
asterisk -rx "core show channels"          # active calls
tail -f /var/log/asterisk/messages.log
systemctl status asterisk apache2 nginx mariadb coturn aipbx-chat
```
