# CRM integrations

Every CRM connects the same way: one [connection](connections.md) per CRM (its own API key and
signed webhook), the [live call events](rest-api-v1.md#live-call-events) and the REST API.

| CRM | How |
|---|---|
| **Odoo 17 / 18 / 19** | The AiPBX Connector app from the Odoo App Store ([AiPBX Connector](https://apps.odoo.com/apps/modules/19.0/aipbx_connector), [AiPBX Connector - CRM](https://apps.odoo.com/apps/modules/19.0/aipbx_connector_crm); [source](https://github.com/mahirgul/AiPBX-Odoo)): paste the connection pack, done. With the CRM app installed, calls also land on opportunities (AiPBX Connector - CRM installs by itself). |
| **ERPNext / Frappe** (self-hosted or Frappe Cloud) | The AiPBX Connector app ([source](https://github.com/mahirgul/AiPBX-Frappe)): **Connections → Add**, kind **ERPNext / Frappe**, paste the pack in ERPNext's **AiPBX Settings**. ERPNext's own caller pop-up, every call in Call Log with its recording and AI summary, click-to-call from every phone field, a call-back ToDo for missed calls, Contacts and Leads in the PBX address book. |
| HubSpot, Zoho CRM, Pipedrive, Bitrix24, Salesforce, … | A connection of kind **Other** pointing at a small receiver, usually an [n8n](https://n8n.io) (or Make / Zapier) workflow, that turns the events into the CRM's call records. |
| Your own program | A connection of kind **Other** pointing at your receiver; see [the receiver below](#a-receiver-in-20-lines). |

## What each event is for

| Event | Typical use in a CRM |
|---|---|
| `call.ringing` | look the caller up (`data.caller.contact` already holds the PBX address book entry) |
| `call.offered` | show the caller to the user of `data.agent_extension` (screen pop) |
| `call.answered` | mark the call as connected |
| `call.completed` | write **one** call record: `direction`, `outcome`, `wait_seconds`, `talk_seconds`, `agent_extension` (after a transfer the last to answer; the first is `first_agent_extension`), `caller.number` / `callee.number` |
| `recording.ready` | attach a link to the recording; fetch it server side with `GET /api/v1/recordings/{recording_id}` (never hand the API key to a browser) |
| `call.ai_completed` | add the summary (and the quality score) to the call record |

All events of one call carry the same `linkedid`: use it as the key, so a repeated delivery
updates the record instead of making a second one.

## n8n recipe (HubSpot, Zoho, Pipedrive, Bitrix24)

1. In n8n, add a **Webhook** node (method POST, "Raw body" on). Copy its production URL.
2. On the PBX: **Integration → Connections → New connection**, kind **Other**, that URL. Keep the
   connection pack: `webhook_secret` for step 3, `api_token` for step 5 (store both as n8n
   credentials, not in the workflow).
3. A **Code** node checks the signature (HMAC-SHA256 of `timestamp + "." + raw body`) and drops
   anything older than five minutes:

   ```js
   const crypto = require('crypto');
   const h = $json.headers, raw = $json.body;               // raw body as received
   const mac = crypto.createHmac('sha256', $env.AIPBX_WEBHOOK_SECRET)
     .update(h['x-aipbx-timestamp'] + '.' + raw).digest('hex');
   const ok = ('sha256=' + mac) === h['x-aipbx-signature-256']
     && Math.abs(Date.now() / 1000 - Number(h['x-aipbx-timestamp'])) < 300;
   if (!ok) throw new Error('bad signature');
   return [{ json: JSON.parse(raw) }];
   ```
4. A **Switch** node on `event`; for `call.completed` use the CRM's own n8n node:

   | CRM | n8n node and object | Map |
   |---|---|---|
   | HubSpot | HubSpot → Engagement (type Call) | `callee/caller.number` → to/from number, `talk_seconds` × 1000 → duration (ms), `outcome` → status, `linkedid` → a custom property for updates |
   | Zoho CRM | Zoho CRM → Calls (create) | `direction` → Call Type, `started_at` → Call Start Time, `talk_seconds` → Call Duration |
   | Pipedrive | Pipedrive → Activity (type call) | subject `"Call " + number`, `done = true`, note = `call.ai_completed` summary |
   | Bitrix24 | HTTP Request → `telephony.externalcall.register` / `.finish` | `CALL_ID` = `linkedid`, `USER_PHONE_INNER` = `agent_extension`, `DURATION` = `talk_seconds` |

   To find the CRM contact, search it by `caller.number` (inbound) or `callee.number` (outbound).
5. Click-to-call from the CRM: a button or link that calls an n8n webhook, which calls
   `POST https://<pbx>/api/v1/calls/originate` with `Authorization: Bearer <api_token>`,
   `from_extension` (the user's extension) and `to_number`, and a `client_request_id` so a
   double click places one call.
6. Contacts to the PBX (desk phones show names): a scheduled workflow that sends changed CRM
   contacts to `POST /api/v1/contacts` with `external_id = "<crm>:<contact id>"`; the same id
   updates the same contact.

These are recipes: the field names of each CRM's n8n node follow that CRM's API and change with it.

## A receiver in 20 lines

```python
import hashlib, hmac, json, time
from flask import Flask, request, abort

SECRET = b"whsec_..."   # webhook_secret from the connection pack
app = Flask(__name__)

@app.post("/aipbx")
def aipbx():
    ts, sig, raw = request.headers.get("X-AiPBX-Timestamp", ""), request.headers.get("X-AiPBX-Signature-256", ""), request.get_data()
    mac = "sha256=" + hmac.new(SECRET, ts.encode() + b"." + raw, hashlib.sha256).hexdigest()
    if not ts.isdigit() or abs(time.time() - int(ts)) > 300 or not hmac.compare_digest(mac, sig):
        abort(401)
    event = json.loads(raw)
    # store event["event_id"] once; key the call by event["data"]["linkedid"]
    print(event["event"], event["data"].get("linkedid"), event["data"].get("outcome"))
    return "ok"
```

Answer 2xx only after the event is stored; the PBX retries otherwise.
