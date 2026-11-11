# SMS & MMS Messaging (Plugin)

The community `sms` plugin sends and receives messages on your provider-hosted numbers through
**Twilio, Vonage, Plivo, Telnyx, Bandwidth, Sinch, VoIP.ms or BulkVS**. Administrators configure it
at `/plugins/sms`; assigned users use **Apps → Messages** (`/apps/sms`) in the web portal.
This inbox is separate from the native mobile apps' PBX chat.

The plugin needs AiPBX **1.71+** for user pages; catalog installation needs **1.72+**. On current
AiPBX, enable the community catalog under **Integration → Plugin Catalog**, install SMS / MMS,
and enable it. See [Plugin development](plugin-development.md#from-the-community-catalog-aipbx-172)
for signed-catalog verification and ZIP installation.

## Set up a number

1. Open the SMS / MMS plugin page and enter the provider account credentials. Secret settings
   are encrypted in the database.
2. Add your number in international form, such as `+15551234567`, select its provider, and assign
   the users allowed to read and send from it. Administrators can access every configured number.
3. Copy the **exact webhook URL shown on the plugin page** into the provider's incoming-message
   configuration. Its format is `/api/plugins/sms/<provider>/<secret-key>`; the secret component is
   required. **Make a new secret key** invalidates the previous webhook URLs.
4. Send a test message to the number and reply from `/apps/sms`. Use a provider sandbox or a number
   you control; provider billing and delivery rules still apply.

## Provider verification and attachments

Every webhook needs the secret key in its URL. Additional provider verification is:

| Provider | Request proof | Attachment notes |
|----------|---------------|------------------|
| Twilio | `X-Twilio-Signature`, using the Auth Token | MMS supported |
| Vonage | Signed webhook JWT when the signature secret is configured | US MMS; one picture per message |
| Plivo | `X-Plivo-Signature-V2`, using the Auth Token | MMS supported |
| Telnyx | Ed25519 signature when the public key is configured | MMS supported |
| Bandwidth | Secret key in webhook URL | MMS supported |
| Sinch | Secret key in webhook URL | MMS depends on region/account; one picture per message |
| VoIP.ms | Secret key in callback URL | Up to three attachments |
| BulkVS | Secret key in webhook URL | Up to three attachments |

Use the provider-specific instructions shown on the plugin page. Do not replace the displayed URL
with `/webhook/<provider>` or publish its secret key in a support issue.

MMS media is stored in `plg_sms_media` in the database, not in the chat attachment Local/S3 store.
Outgoing media is exposed to the provider through a tokenised URL
`/api/plugins/sms/media/<id>/<token>`. The provider must be able to reach the portal's HTTPS domain.
The plugin accepts up to three outgoing files, 5 MB each; provider limits may be lower.

Incoming media downloads must use HTTPS on port 443 and resolve to public addresses. Redirects are
validated again, DNS addresses are pinned for the connection, and downloads are bounded to 5 MB.
An attachment failure is recorded without discarding the text. Automatic attachment retry is not
implemented.

## Using the inbox

Choose one of your assigned numbers and a conversation, or enter a destination number in international
form. Conversations and messages refresh by polling every five seconds; a reply being composed stays
in place. **Save name** gives the peer a name shared by the users of that number.

The composer allows up to 1600 characters. Since SMS plugin **1.2.2**, Send disables immediately and
shows **Sending…** until the request completes, blocking repeated submission from that form. A server
error renders a usable form with the error, and returning through browser history restores the button.
The control guards that form; it is not provider-wide idempotency across independent browser tabs.

Outgoing statuses are `sending`, `sent` or `failed`; received messages are `received`. **Sent** means
the provider accepted the send request, not that a carrier delivery receipt confirmed arrival on the
recipient's device. The plugin does not implement a separate delivered-status workflow or a live
SMS-segment counter.

When enabled, incoming messages notify the number's users by email at most once per conversation
in ten minutes. Users need an email address and working portal [mail settings](mail.md).

## Storage, retries and privacy

| Table | Content |
|-------|---------|
| `plg_sms_numbers` | Configured numbers and providers |
| `plg_sms_access` | Users allowed to use each number |
| `plg_sms_messages` | Incoming/outgoing text, status and provider message IDs |
| `plg_sms_media` | Attachment bytes, type, name and fetch token |
| `plg_sms_contacts` | Peer name shared within a configured number |

Incoming provider IDs are checked per receiving number/provider under an InnoDB row lock. Media
fetches and email run after the transaction. Independent destinations are retained; messages without
provider IDs cannot be reliably deduplicated and are stored separately.

Do not assume core chat/CDR retention settings purge these plugin tables. Define the operator's
retention/export/deletion process for SMS data and include its database storage in capacity planning.
Plugin disable preserves tables; uninstall removes declared plugin tables. See
[Plugin lifecycle](plugin-development.md).

Before production use, check your provider's sender registration, permitted traffic, consent and
opt-out requirements. The PBX passes the message to the provider; delivery, segmentation and billing
remain provider-dependent.

## Test evidence

The [plugin README](https://github.com/mahirgul/AiPBX-plugins/tree/main/plugins/community/sms)
lists provider-request/signature checks, media security checks, and real MySQL receipt/concurrency
tests. These do not send paid messages or certify a live provider account. Community users reported
sending/receiving with Twilio and VoIP.ms; test your own provider configuration before enabling staff.
