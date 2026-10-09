# E-mail

AiPBX sends e-mail through the local **postfix**: fax-to-e-mail, voicemail notifications, user
invitations, password resets and 2FA messages.

## Relay

Most networks do not let servers deliver mail directly. Set a relay on **Admin → E-Mail**:

| Field | Example |
|-------|---------|
| Relay host | `mail.example.com` or an internal relay IP |
| Port | `25` (no TLS), `587` (STARTTLS) or `465` (SSL) |
| Security | none / TLS / SSL |
| Authentication | username and password if the relay requires them |
| Apply to postfix | on |

Saving stores the settings and updates postfix through `aipbx-priv` (`relayhost`, TLS level, SASL).
Verify on the **Test** tab of the same page (*Send Test E-Mail*).

Sender addresses: the portal sender (invitations, password e-mails) and the fax sender are set on the
same page.

## Templates

**Admin → E-Mail → Templates** holds the text of every e-mail AiPBX sends: the account invitation,
the sign-in / password link, password reset, new voicemail, fax received, fax delivered, fax not
delivered and the test e-mail.

- All of them share one frame: the logo, name, subtitle and colour from **Brand settings**. The logo
  is sent inline with the message, so it shows without loading anything from the server (PNG, JPG or
  GIF; an SVG logo is left out of e-mails).
- **Language:** a portal user gets the e-mail in their own interface language. Recipients without an
  account (fax unit addresses) get the *language for recipients without an account* set on the same
  page; until it is set, the first administrator's language is used.
- A template you have not changed uses the built-in text of that language. Changing it stores your
  version for that one language; *Reset to built-in text* removes it again.
- The text may use paragraphs, bold, italic, links and lists. Other markup is removed when you save.
  Values in braces such as `{name}` or `{caller}` are filled in when the mail is sent; the buttons
  under the editor insert them. `{reset_button}`, `{account_details}` and `{mobile_section}` are
  ready-made parts (a button, the account box, the mobile app box).
- The preview and *Send me a test* use example values and the text as it is in the editor, saved
  or not.

Voicemail e-mails: Asterisk hands each notification to `bin/voicemail_mail.php` (`mailcmd`, written
to `/etc/asterisk/pbx/voicemail_general.conf`), which sends the *New voicemail* template with the
recording attached. If that fails, the message Asterisk built is delivered unchanged, so a
notification is not lost, also when the script stops early (database unreachable, PHP error).
`journalctl -t aipbx-voicemail` shows each notification: handed to sendmail, or why the original
was sent instead. No line there for a new voicemail means Asterisk sent nothing: check that the
user has an e-mail address and the e-mail switch on (*My Phone → Voicemail*), then *Apply*.

## Checking delivery

```bash
tail -f /var/log/mail.log     # delivery attempts and relay responses
mailq                         # queued messages
postconf -h relayhost         # the relay postfix is using
```
