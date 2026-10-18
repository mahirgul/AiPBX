# REST API v1 & Outbound Webhooks

AiPBX provides a stateless JSON REST API under `/api/v1/*` with OpenAPI 3.1 discovery (`/api/v1/openapi.json`), scoped Bearer API keys (`apbx_<token>`), CIDR IP allowlists, per-key rate limiting, and HMAC-SHA256 signed outbound webhooks.

---

## Authentication & API Keys

1. In the web portal, open **Integrations → API Keys** (`/api-keys`).
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
| `pbx:read` | Read extensions, trunks, DID/outbound routes, IVRs, queues, ring groups, conferences, time conditions, and web widgets |
| `pbx:write` | Create, update (`PATCH`/`PUT`), or delete PBX routing and extension resources |
| `calls:read` | Read active channels (`/api/v1/calls/active`) and live queue board (`/api/v1/queues/live`) |
| `calls:write` | Originate calls (`/api/v1/calls/originate`) and hangup channels (`/api/v1/calls/hangup`) |
| `cdr:read` | Query Call Detail Records (`/api/v1/cdr`) |
| `voicemail:read` | List voicemails (`/api/v1/voicemails`) |
| `users:read` / `users:write` | Manage portal users and inspect RBAC roles |
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
  - `POST /api/v1/calls/originate`
  - `POST /api/v1/calls/hangup`
  - `GET /api/v1/cdr`
  - `GET /api/v1/voicemails`
- **Webhooks & Plugins**:
  - `GET|POST /api/v1/webhooks`, `DELETE /api/v1/webhooks/{id}`
  - `GET /api/v1/plugins`
  - `POST /api/v1/plugins/{slug}/enable`, `POST /api/v1/plugins/{slug}/disable`
  - `GET|PATCH /api/v1/plugins/{slug}/settings`
  - `GET|PUT|DELETE /api/v1/plugins/{slug}/kv/{collection}/{key}`

---

## Outbound Webhooks (`X-AiPBX-Signature-256`)

Configure outbound webhooks in **Integrations → Webhooks** (`/webhooks`). Events are queued non-blockingly in `sys_webhook_queue` and dispatched by `aipbx-webhook-worker.service` (`bin/webhook_worker.php`) with exponential backoff and SSRF protection.

### Supported Events

- `call.started`, `call.answered`, `call.ended`, `call.missed`
- `voicemail.received`
- `fax.received`, `fax.sent`
- `queue.CallEntered`, `queue.AgentConnect`, `queue.Abandon`
- `config.synced`

### Verifying Webhook Signatures

Each webhook request includes:
- `X-AiPBX-Event`: Event name (e.g. `call.ended`)
- `X-AiPBX-Delivery`: Unique UUID for the event
- `X-AiPBX-Timestamp`: Unix timestamp
- `X-AiPBX-Signature-256`: `sha256=<hex>` computed using HMAC-SHA256 over `${timestamp}.${rawBody}` with your webhook secret.
