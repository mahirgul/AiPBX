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

The Turkish set ships as one package per format,
`asterisk-core-sounds-tr-<format>-<version>.tar.xz`, attached to the GitHub release
[`sounds-tr-<version>`](https://github.com/mahirgul/AiPBX/releases). The repo root only holds
`asterisk-core-sounds-tr-<version>.SHA256SUMS`; `install.sh` downloads the packages listed there,
verifies them against it and keeps them next to it for later upgrades (set `AIPBX_SOUNDS_URL` to
download from a mirror). The packages are laid out like Asterisk's own `asterisk-core-sounds-*` packages so it can be used on any Asterisk
server: extract the formats you need into the `tr` sounds directory.

- One voice (Google `tr-TR-Wavenet-C`), generated with AiPBX Cloud TTS from 16 kHz masters: every
  Asterisk core prompt (same names as `asterisk-core-sounds-en`, including voicemail, digits,
  letters, phonetic, conference, queue and directory prompts) plus AiPBX's own prompts.
- Every prompt in six formats: `wav` (8 kHz), `ulaw`, `alaw`, `gsm` and the wideband (HD) `g722`
  and `sln16`. Asterisk picks the one matching the call's codec, so no transcoding is needed.
- Tones (`beep`, `ascending-2tone`, …) come from the English set; `silence/1..10` are generated.
- Licence: CC BY-SA 4.0 (tones: CC BY-SA 3.0, Digium).

Installs and updates extract the newest package into `/var/lib/asterisk/sounds/tr/`, replacing the
prompts it contains (and removing other-format copies of the same names); other files in that
directory are left alone.

To change a text, edit `sounds/core-sounds-tr.txt` (`name|text`), regenerate the masters (Google
must be configured under **AI → Cloud TTS**) and rebuild the package:

```bash
sudo php web/bin/generate_tr_sounds.php --out=/tmp/tr-master             # all prompts
sudo php web/bin/generate_tr_sounds.php --out=/tmp/tr-master vm-intro    # or only some
scripts/build_tr_sounds.sh /tmp/tr-master 1.0.1
gh release create sounds-tr-1.0.1 --latest=false --title "Turkish Asterisk sounds 1.0.1" \
    asterisk-core-sounds-tr-*-1.0.1.tar.xz asterisk-core-sounds-tr-1.0.1.SHA256SUMS
git add asterisk-core-sounds-tr-1.0.1.SHA256SUMS && git rm asterisk-core-sounds-tr-1.0.0.SHA256SUMS
```

Publish the release before committing the new SHA256SUMS: installs follow the committed file.

`build_tr_sounds.sh` needs every prompt's master in the directory; to rebuild with only a few
changed prompts, first extract the current package's `.sln16` files back to WAV masters
(`sox -t raw -r 16000 -e signed -b 16 -c 1 NAME.sln16 NAME.wav`).

Asterisk has no Turkish number/date grammar, so `SayNumber`, dates and voicemail message counts
follow English word order; the texts are worded to stay natural in that order
(e.g. `vm-youhave` = "Posta kutunuzda").
