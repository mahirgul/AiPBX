# Connections (Odoo, CRM, n8n)

**Integration → Connections** connects one outside system to the PBX: the
[AiPBX Connector for Odoo](https://github.com/mahirgul/AiPBX-Odoo), a CRM, n8n or your own script.
Each connection gets its **own API key** and its **own signed webhook**. Removing the connection
removes exactly those two (and its undelivered events); other keys and webhooks are not touched.

## Creating one

1. **New connection**: a name, the kind and the address.
   - **Odoo**: the address of Odoo (`https://odoo.example.com`). The PBX sends the events to
     `https://odoo.example.com/aipbx/webhook/<connection id>`.
   - **Other**: the full address that receives the webhooks.
   - Optionally the IP addresses or networks the key may be used from.
2. The page shows the **connection pack** once. Paste it into the other system (in Odoo:
   Settings → AiPBX). It is not shown again and not stored readable on the PBX.

```json
{
  "aipbx_connection": 1,
  "pbx_url": "https://pbx.example.com",
  "instance_id": "…",
  "connection_id": "8cfaf237-…",
  "name": "Odoo",
  "api_token": "apbx_…",
  "webhook_secret": "whsec_…",
  "events": ["call.ringing", "call.offered", "call.answered", "call.completed", "recording.ready", "call.ai_completed"],
  "scopes": ["calls:read", "calls:write", "contacts:read", "contacts:write", "pbx:read"]
}
```

## What the key may do

| Scope | Used for |
|---|---|
| `calls:read` | call records (`/api/v1/cdr?after_id=`), recordings (`/api/v1/recordings/{id}`) |
| `calls:write` | click-to-call (`/api/v1/calls/originate` with `client_request_id`) |
| `contacts:read` / `contacts:write` | the [address book](phonebook.md) and the caller lookup |
| `pbx:read` | the extensions, to match users |

The webhook carries the [live call events](rest-api-v1.md#live-call-events), `recording.ready` and
`call.ai_completed` (the call analysis: summary, transcript, quality score),
signed with `X-AiPBX-Signature-256: sha256=HMAC-SHA256(secret, timestamp + "." + body)` and
`X-AiPBX-Timestamp`.

## Test, new key, remove

- **Send a test event**: a signed `webhook.test` to the other side now.
- **New key and secret**: the old ones stop at once; paste the new pack on the other side. The
  connection id stays the same, and the connection gets the events of the current version.
- **Remove**: the connection, its key, its webhook and its undelivered events.

The list shows the last delivery (HTTP status, error) and when and from where the key was last used.
Addresses in the local network are refused like every webhook, unless private webhooks are allowed.

Recipes for HubSpot, Zoho, Pipedrive, Bitrix24 and your own receiver: [CRM integrations](crm-integrations.md).
