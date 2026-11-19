# REST API v1 & Outbound Webhooks

AiPBX provides a stateless JSON REST API under `/api/v1/*` with OpenAPI 3.1 discovery (`/api/v1/openapi.json`), scoped Bearer API keys (`apbx_<token>`), CIDR IP allowlists, per-key rate limiting, and HMAC-SHA256 signed outbound webhooks.

---

## Authentication & API Keys

1. In the web portal, open **Integration → REST API Keys** (`/api-keys`).
2. Create an API key, choose its **Scopes**, optional **Allowed IPs (CIDR)**, and **Rate Limit (requests/min)**.
3. Copy the generated `apbx_...` token (shown only once; stored as SHA-256 hash in `sys_api_tokens`).
4. Pass the token in the `Authorization` header on every request:

```
Authorization: Bearer apbx_your_token_here
Content-Type: application/json
```

### Available Scopes

| Scope | Description |
|---|---|
| `pbx:read` | Read extensions, trunks, DID/outbound routes, IVRs, ring groups, conferences, time conditions, and web widgets |
| `pbx:write` | Create, update (`PATCH`/`PUT`), or delete PBX routing and extension resources (queues need `queues:write`) |
| `queues:read` / `queues:write` | Queues, agents and the live queue board (`/api/v1/queues/live`); change queues and live agent state |
| `calls:read` | Active channels (`/api/v1/calls/active`), Call Detail Records (`/api/v1/cdr`) and voicemails of **any** extension (`/api/v1/voicemails`) |
| `calls:write` | Originate calls (`/api/v1/calls/originate`) and hang up channels (`/api/v1/calls/hangup`) |
| `contacts:read` / `contacts:write` | The address book (`/api/v1/contacts`) and the caller lookup (`/api/v1/contacts/lookup`); add, change and delete contacts |
| `fax:read` / `fax:write` | Fax inbox, sent faxes and fax units; send faxes |
| `sounds:read` / `sounds:write` | Sounds, announcements and music on hold |
| `devices:read` / `devices:write` | Desk phones and their keys |
| `users:read` / `users:write` | Manage portal users and inspect RBAC roles. `users:write` can create administrators and set passwords: give it only where full access is meant |
| `system:read` / `system:write` | Read health/info/audit log, trigger Asterisk sync (`/api/v1/system/apply-sync`), and manage webhooks/plugins |
| `*` | Full administrative access to all `/api/v1/*` endpoints |

---

## Standard JSON Envelope

Every `/api/v1/*` response uses a uniform envelope:

```json
{
  "success": true,
  "data": { ... },
  "error": null,
  "meta": {
    "request_id": "req_9f4b2c10e8a14d20"
  }
}
```

**Secrets are never returned:** password hashes, SIP passwords (except where an endpoint exists to
hand them out), PINs (voicemail, conference user and admin), API and provisioning tokens, two-factor
data, password-reset tokens and phones' admin passwords are left out of every record.

---

## Core Endpoints (`/api/v1/*`)

- **OpenAPI 3.1 Spec**: `GET /api/v1/openapi.json`
- **System**:
  - `GET /api/v1/system/info`
  - `GET /api/v1/system/health`
  - `GET /api/v1/system/pending-sync`
  - `POST /api/v1/system/apply-sync`
  - `GET /api/v1/system/audit-log`
- **PBX Provisioning**:
  - `GET|POST /api/v1/extensions`, `GET|PATCH|DELETE /api/v1/extensions/{id}`
  - `GET|POST /api/v1/trunks`, `GET|PATCH|DELETE /api/v1/trunks/{id}`
  - `GET|POST /api/v1/did-routes`, `GET|PATCH|DELETE /api/v1/did-routes/{id}`
  - `GET|POST /api/v1/outbound-routes`, `GET|PATCH|DELETE /api/v1/outbound-routes/{id}`
  - `GET|POST /api/v1/ivrs`, `GET|PATCH|DELETE /api/v1/ivrs/{id}`
  - `GET|POST /api/v1/queues`, `GET|PATCH|DELETE /api/v1/queues/{id}`, `GET /api/v1/queues/live`
  - `GET|POST /api/v1/ring-groups`, `GET|PATCH|DELETE /api/v1/ring-groups/{id}`
  - `GET|POST /api/v1/conferences`, `GET|PATCH|DELETE /api/v1/conferences/{id}`
  - `GET|POST /api/v1/time-conditions`, `GET|POST /api/v1/time-groups`
  - `GET|POST /api/v1/web-widgets`, `GET|DELETE /api/v1/web-widgets/{id}`
- **Live Calls & Records**:
  - `GET /api/v1/calls/active`
  - `POST /api/v1/calls/originate` — `{"from_extension": "19000", "to_number": "0532…",
    "client_request_id": "crm-click-123"}`. The same `client_request_id` again within 10 minutes
    (a retry after a timeout) returns the first answer with `"duplicate": true` and places no
    second call
  - `POST /api/v1/calls/hangup`
  - `GET /api/v1/cdr` — filters `date_from`, `date_to`, `src`, `dst`, `disposition`. Each row has
    `id`, `linkedid` (the same as in the call events) and `recording_url`. **Sync cursor:**
    `?after_id=N` returns the rows after id N, oldest first; pass `meta.next_after_id` next time
    and every record is read exactly once
  - `GET /api/v1/recordings/{id}` — the audio of a call record (`id` from `/cdr` or
    `recording.ready`), `?download=1` as an attachment. By id only; scope `calls:read`
  - `GET /api/v1/voicemails`
- **Address Book**:
  - `GET /api/v1/contacts?q=` — search names, companies, e-mails and numbers (any spelling); paged
  - `GET /api/v1/contacts/lookup?number=` — the contact of a caller number, or `null`
  - `POST /api/v1/contacts`, `GET|PATCH|DELETE /api/v1/contacts/{id}`
  - Body: `display_name` (or `first_name`/`last_name`/`company`), `email`, `numbers: [{"label": "mobile", "number": "+90 532 111 22 33"}]`
    (labels `mobile`, `work`, `work2`, `home`, `fax`, `other`; up to 12). With `external_id` a
    `POST` updates the contact stored with that id instead of adding a second one, so a CRM can
    send its contacts again and again. Contacts a plugin brings from another directory are read-only.
- **Webhooks & Plugins**:
  - `GET|POST /api/v1/webhooks`, `DELETE /api/v1/webhooks/{id}`
  - `GET /api/v1/plugins`
  - `POST /api/v1/plugins/{slug}/enable`, `POST /api/v1/plugins/{slug}/disable`
  - `GET|PATCH /api/v1/plugins/{slug}/settings`
  - `GET|PUT|DELETE /api/v1/plugins/{slug}/kv/{collection}/{key}`

---

## Outbound Webhooks (`X-AiPBX-Signature-256`)

Configure outbound webhooks in **Integration → Webhooks** (`/webhooks`). Events are queued non-blockingly in `sys_webhook_queue` and dispatched by `aipbx-webhook-worker.service` (`bin/webhook_worker.php`) with exponential backoff and SSRF protection.

### Supported Events

The event catalog is `WebhookService::SUPPORTED_EVENTS`:

- `call.started`, `call.ended`, `call.missed`
- `voicemail.created`, `voicemail.received`, `voicemail.transcribed`
- `fax.received`, `fax.sent`, `fax.failed`
- `queue.member_logged_in`, `queue.member_logged_out`, `queue.member_paused`, `queue.caller_abandoned`
- `config.pending_created`, `config.sync_applied`, `config.sync_failed`
- `security.login_failed`

`*` subscribes to all events. `webhook.test` is a signed connection-test delivery.

- Live, per call: `call.ringing`, `call.offered`, `call.answered`, `call.completed`
- `call.ai_completed`: the call analysis is ready. `data`: `cdr_id`, `linkedid`, `caller`,
  `agent_extension`, `summary`, `transcript` (privacy redaction applied, 20,000 characters at most),
  `qa_score`, `qa_sentiment`, `qa_resolved`, `qa_notes`
- `recording.ready`: a recording reached its final form (MP3, and in the bucket when recordings go
  there). `data`: `recording_id` (use it with `/api/v1/recordings/{id}`), `uniqueid`, `linkedid`,
  `recording_url`, `format`

`*` subscribes to all events. `webhook.test` is a signed connection-test delivery. Every delivery
carries `event_id`, `event`, `schema_version` (1), `instance_id` (one id per PBX), `occurred_at`,
`pbx_version` and `data`.

#### Live call events

The `aipbx-call-events` service follows Asterisk's manager interface (127.0.0.1, events only) and
treats everything with the same `linkedid` as one call: the caller, the IVR, the queue, the agents.

| Event | When |
|---|---|
| `call.ringing` | An outside caller reached the PBX, or an extension dialled (inbound: at once, before IVR or queue) |
| `call.offered` | An extension rings for the call (queue, ring group or direct). Once per extension, however many of its phones ring — the screen-pop moment |
| `call.answered` | A person answered: an extension, or the outside party of an outbound call. An IVR or a queue answering the line is not this |
| `call.completed` | The last channel of the call hung up. Exactly one per call |

`data` of all four:

```json
{
  "linkedid": "1795034739.6", "direction": "inbound", "did": "902125550000", "trunk": "netgsm",
  "queue": "sales", "from_extension": "", "agent_extension": "19000",
  "caller": {"number": "+905321112233", "name": "",
             "contact": {"id": 7, "display_name": "Kemal", "company": "", "external_id": "crm-7"}},
  "callee": null, "started_at": "2026-11-18T21:40:00+01:00"
}
```

- `direction`: `inbound`, `outbound` or `internal`. For outbound calls `callee` holds the dialled
  number and its address book `contact`.
- `contact` is the [address book](phonebook.md) entry of the number (any spelling), or `null`; a
  CRM that sent the contact with an `external_id` gets it back here.
- `call.offered` and `call.answered` name the extension in `agent_extension`.
- `call.completed` adds `answered_at`, `ended_at`, `wait_seconds`, `talk_seconds` and `outcome`
  (`answered`, `abandoned` — the caller gave up in a queue — or `missed`).

`call.started`, `call.ended` and `call.missed` stay as they were: `call.started` comes from API
originate (`source: api_originate`); `call.ended` and `call.missed` are sent per completed CDR row
by the worker, so a missed leg does not prove the whole call was unanswered (use
`call.completed`). Their `recording` field is the raw CDR userfield, not a public recording URL.

### Verifying Webhook Signatures

Each webhook request includes:
- `X-AiPBX-Event`: Event name (e.g. `call.ended`)
- `X-AiPBX-Delivery`: Unique UUID for the event
- `X-AiPBX-Timestamp`: Unix timestamp
- `X-AiPBX-Signature-256`: `sha256=<hex>` computed using HMAC-SHA256 over `${timestamp}.${rawBody}` with your webhook secret.
