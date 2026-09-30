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

## Checking delivery

```bash
tail -f /var/log/mail.log     # delivery attempts and relay responses
mailq                         # queued messages
postconf -h relayhost         # the relay postfix is using
```
