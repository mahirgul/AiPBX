# AI gateway: synchronous chat contract (version 1)

The Assistant and the language-model test form call PHP `AiGateway`. Provider
requests execute in Python through `POST /v1/gateway/execute`. The initial
capability is `chat`; `transcribe`, `synthesize`, and `embed` are reserved operation
names and currently return `capability_unsupported` through this contract.
Existing speech and recording consumers move in their own phases.

## Request and authorization

The portal checks the current database user, active/device flags, feature plugin,
and Assistant ask permission (or administrator role for panel tests). A session
role string alone does not authorize execution. Existing model selections act as
transitional binding references: `local:<model-id>` and `cloud:<provider-id>`.
The gateway never retries on another provider when execution fails.

The portal creates a random 128-bit request reference in `ai_gateway_tickets`.
The record contains the authenticated user, token epoch, feature, selection,
configuration digest, payload digest, and a 90-second expiry. It contains neither
prompt nor plaintext credentials. Unsaved panel connection fields are encrypted
with `SecretBox` for the lifetime of the request; saved account settings remain
the source of truth. These records are temporary authorizations, not a job queue.
They are removed after the request, or pruned on subsequent requests after expiry.

```json
{
  "protocol_version": 1,
  "request_id": "32 lowercase hex characters",
  "operation": "chat",
  "input": "user prompt",
  "max_tokens": 300,
  "deadline_at": 1790000000
}
```

The loopback worker API requires its existing bearer token. The worker sends only
the request reference and SHA256 of the exact payload to the credential resolver's
Unix socket. The resolver checks expiry, payload digest, consumption state, current
user epoch/role, feature availability, cloud policy, quota and configuration
revision again. Credential/configuration changes invalidate queued authorizations.
Consumption is serialized with a database row lock. A consumed reference cannot
be replayed or used for different text.

The resolver runs as `www-data`, with group `aipbx-ai`, and its socket is 0660 under
a 0750 systemd runtime directory. The inference worker receives no database login
or database encryption key. Each resolution uses a fresh PHP process. Credentials
cross only this restricted local channel and are held in memory for execution.

## Response, limits and network behavior

Responses include protocol version, request id, status, selected model reference,
configuration revision, output, token usage and elapsed time. Failures expose a
bounded error code, never a provider response body or credential. Typical codes:
`permission_denied`, `policy_blocked`, `configuration_changed`, `model_not_ready`,
`model_unavailable`, `provider_auth_failed`, `deadline_exceeded`, `capacity_exceeded`,
`rate_limited`, `capability_unsupported`, `protocol_mismatch`, `empty_response`.
No automatic retry is performed. The synchronous caller may have already incurred
provider usage if a network request times out; a timeout does not guarantee zero cost.

The worker admits at most four concurrent gateway operations. Resolver work is
limited to eight simultaneous subprocesses. Chat input, token budget and response
size are bounded. HTTP requests use the remaining deadline, validate all resolved
addresses and connect to a validated address directly. TLS retains the original
hostname. Proxy environment variables and redirects are not used. Link-local,
metadata and reserved addresses are rejected; private/loopback destinations are
allowed only for the explicit local/custom endpoint adapters.

## Voice application credential references

Voice application configs now contain `secret_ref: app:<id>:<task>:<provider>` in
place of keys. Existing on-disk configs are converted when the service loads them;
subsequent persistent writes also strip credentials. Each cloud STT, TTS or LLM
invocation resolves its reference against the active database application and
current plugin/privacy settings. References cannot read unrelated providers or
unconfigured tasks. Application credentials are not cached across invocations.
This changes secret ownership; full live-audio binding and lifecycle migration
remains a later phase. Already-started provider calls are not retroactively undone.

## Installation and rollback

`aipbx-ai-run` uses the optional ML virtual environment when installed; otherwise
it starts the same worker with system Python and the standard library only. The
installer enables the lightweight worker and `aipbx-ai-resolver`. It downloads no
ML dependencies or models for cloud-only use. Removing the local ML runtime keeps
the lightweight gateway available. Asterisk has no dependency on AI readiness.

Upgrade requires the forward ticket-table migration, the PHP resolver and gateway,
Python modules, launcher and resolver unit together. Check `/v1/health` for
`gateway.protocol_version=1` and `operations=["chat"]`. Test both a local response
and a real cloud response with an account/model that is actually accessible.

Keep a pre-upgrade backup of application JSON files: older code cannot resolve
new secret references. A rollback restores the matching old application configs
and worker/PHP code together. The additive ticket table may remain unused. Do not
roll back the worker alone while leaving reference-only configs behind.
