# Sounds & languages

## Where sounds live

| Path | Content |
|------|---------|
| `/var/lib/asterisk/sounds/custom/` | sounds uploaded on **Sounds** (IVR, announcements, queues) |
| `/var/lib/asterisk/sounds/tr/` | Turkish system prompts shipped in `sounds/tr/` of the repository |
| `/var/lib/asterisk/moh/` | music on hold |
| `/usr/share/asterisk/sounds/en/` | English prompts (Debian package) |

Debian's Asterisk searches prompts under `/usr/share/asterisk/sounds`. The installer links
`/usr/local/share/asterisk/sounds` (Debian's `custom` directory) to `/var/lib/asterisk/sounds/custom`
and `/usr/share/asterisk/sounds/tr` to `/var/lib/asterisk/sounds/tr`. Without these links IVRs,
announcements and Turkish prompts fail with *"File … does not exist in any format"* and the call is hung
up. The links live outside dpkg-owned files, so package upgrades keep them.

Sounds should be 8 kHz mono WAV (16-bit PCM). Uploaded files with the same name as a shipped file are
never overwritten by updates.

## Languages

A call uses the language set on its inbound route. When a prompt is missing in that
language Asterisk plays the English one, so a partly translated set gives mixed-language prompts.

## Turkish prompts

`sounds/tr/` is one consistent set in a single voice (Google `tr-TR-Wavenet-C`), generated with
AiPBX Cloud TTS: every Asterisk core prompt (same names as `asterisk-core-sounds-en`, including
voicemail, digits, letters, phonetic, conference, queue and directory prompts) plus AiPBX's own
`queue-agentlogin-success`. The texts are in `sounds/tr/README-tts.txt` (`name|text`); tones
(`beep`, `ascending-2tone`, …) come from the English set.

Installs and updates replace these prompts on the server (and remove an older `.gsm`/`.ulaw` copy of
the same name); other files in `/var/lib/asterisk/sounds/tr/` are left alone.

To change a text, edit `README-tts.txt` and regenerate the prompt (Google must be configured under
**AI → Cloud TTS**):

```bash
sudo -u www-data php web/bin/generate_tr_sounds.php --out=/tmp/tr vm-intro digits/7
```

Asterisk has no Turkish number/date grammar, so `SayNumber`, dates and voicemail message counts
follow English word order; the texts are worded to stay natural in that order
(e.g. `vm-youhave` = "Posta kutunuzda").
