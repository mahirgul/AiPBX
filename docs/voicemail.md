# Voicemail

Every SIP extension has a mailbox. Callers reach it when the extension does not answer, is busy or
unreachable, or always — chosen per extension (*Extensions → Call settings*, or *My phone*).

## Listening

- From the desk phone: **\*97** (own mailbox) or **\*98** (any mailbox, asks for its number); the
  voicemail lamp shows new messages.
- In the portal and the mobile apps (*My phone*): play, download and delete.
- By e-mail: the message as an attachment, from the template *Voicemail* (*E-mail → Templates*);
  on or off per extension.
- Push notification to the mobile apps.

## Limits and PIN rules

**PBX settings → Voicemail** (applied with *Apply*):

| Setting | Default | Range |
|---|---|---|
| Longest message | no limit (0) | 0–3600 |
| Shortest message kept | 0 s | 0–60 |
| Messages per mailbox | 100 | 10–9999 |
| Silence that ends a message | 10 s (0 = off) | 0–60 |
| Wrong PINs before hanging up | 3 | 1–10 |
| Shortest PIN | 4 digits | 4–10 |
| PIN may not be the extension number | on | |

A PIN typed on the extension form or in *My phone* must follow the rules. A mailbox saved without a
PIN gets a random one (6 digits or the minimum, whichever is longer); before 1.50.10 an empty PIN
became the extension number, which anyone could guess. The tab shows how many mailboxes still have
a PIN that breaks the rules. A PIN changed from a phone (\*97) must be at least as long as set here
(Asterisk's `minpassword`).

Messages are stored once, as WAV, in `/var/spool/asterisk/voicemail/default/<extension>/`; how long
they are kept is set under [Privacy](privacy.md).
