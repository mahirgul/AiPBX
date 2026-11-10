# AI Voicemail Summaries & Transcripts

The community `ai-voicemail` plugin displays completed voicemail transcripts and summaries at
`/plugins/ai-voicemail`. AiPBX 1.72.12 includes the corrected event subscription and full transcript
payload. See the [plugin developer guide](plugin-development.md).

## Enable transcription

1. Enable voicemail transcription under **AI → Cloud services → Voicemail**, and configure a speech
   engine through [Cloud AI](cloud-ai.md) or [Local AI Models](local-ai.md).
2. Install **AI Voicemail Transcription & Executive Summary** from the community catalog under **Integration → Plugin Catalog**.
3. Open `/plugins/ai-voicemail` to view completed results.

The plugin consumes the core's `voicemail.transcribed` event. It does not transcribe audio itself.
Without a completed transcript or summary, it creates no summary entry and makes no LLM request.

## Event flow and storage

The core's `VoicemailAiService` transcribes the recording and optionally summarizes it, storing its
result in `voicemail_ai`. The detached voicemail notification script emits `voicemail.transcribed`
with the extension, caller, duration, summary preview and full transcript when available.

The plugin optionally sends that transcript to its configured LLM, then stores the transcript and
summary in the `summaries` collection of `sys_plugin_kv`. These entries appear in the plugin admin
page. The same completed event is skipped on redelivery. The core caps each KV collection at 500
items; this is not a configurable time-based retention policy.

The result is plain summary text. There are no structured urgency fields, synchronized transcript
timestamps or separate action-item records. If the LLM fails or returns nothing, the plugin preserves
the core summary, or uses the first 200 transcript characters when no summary is available.

## Optional LLM settings

| Setting | Behavior |
|---|---|
| API Base URL | OpenAI-compatible base URL, for example `https://api.openai.com/v1`. The plugin appends `/chat/completions`. Administrator-configured HTTP endpoints are also accepted for internal services. |
| API Key | A non-empty value enables the LLM request and is sent as a Bearer token. Empty means reuse the core result without an LLM call. For a local server without authentication, use a non-empty placeholder only if that server accepts it. |
| Summarization LLM Model | Model available on the configured server, for example `gpt-4o-mini`. |
| Summary Output Language | Turkish, English or the language of the message. Applies to the optional LLM request. |

The LLM request has a 12-second total timeout and a 5-second connection timeout; redirects are disabled.

## Notifications and privacy

The voicemail player, email and mobile notification integrations use the core voicemail AI result.
The plugin's optional custom summary is stored for its admin page; it does not replace the core result
or send a second email/push notification. See [voicemail](voicemail.md) and [mail settings](mail.md).

Core transcript redaction runs when enabled by the privacy settings. The plugin does not apply a
separate redaction filter or check a cloud AI lock before its optional HTTP request. Configure its
endpoint and credentials according to your data policy. Choosing a local LLM alone does not ensure
all processing is local: the core's speech and summary engines must also be configured accordingly.

## Moving from the bundled version

The next core upgrade moves an installed bundled copy to `/var/lib/aipbx/plugins/ai-voicemail` using an offline transition package. It preserves its enabled/disabled state, encrypted settings and stored summaries. Existing uploaded copies are preserved. New installations do not install it automatically. Later plugin updates come from the community catalog. The core transcription service remains in AiPBX.
