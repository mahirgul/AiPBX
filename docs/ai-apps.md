# AI applications

**AI → Applications** (admin only) are AI features that take calls. Each application has an
**internal number** (dial it from an extension) and can be chosen as a destination wherever
destinations are offered — IVR keys, inbound routes, time conditions — as **AI application**.
After it has done its job the call goes on to the application's own destination.

If the local AI service is not running, the voice is not ready, or the application already has
as many calls as its limit, the call goes straight to the destination: it is never lost.
Changes take effect with **Apply**, like other PBX settings.

## Announcement with values

A text read to the caller by a voice model of this server
([Local AI models](local-ai.md)), with values in it:

| Value | Comes from |
|-------|-----------|
| `{caller_number}` | the caller's number |
| `{caller_name}` | the caller's name (from the trunk or the phone book) |
| `{did}` | the number that was called |
| any other `{name}` | the **lookup address** |

The **lookup address** is called by the server with `?caller=…&did=…&app=…` added and must answer
within 2 seconds with a JSON object, for example `{"amount": "1.234,50", "date": "15 Ekim"}`.
When it does not answer in time, is not JSON, or a value is missing, the **fallback text** is read
instead (or, without one, the text without the missing values). Return values the way they should
be spoken; numbers, amounts and dates are read out properly (Turkish: write amounts as "1.234 lira
50 kuruş" or in words, since "1.234,50" is read as a decimal number).

Example: text "Sayın {caller_name}, borcunuz {amount}. Son ödeme tarihi {date}.", lookup
`https://crm.example.com/aipbx/balance`, afterwards: the accounting queue.

Voice: a model of *AI → Local models*, or "the engine for the language" chosen on
*AI → Cloud services*. Speed 0.5–2. **Calls at the same time**: above this number, callers go
straight to the destination (a small server prepares one announcement per voice at a time).

## How it works

The generated context `[aipbx-ai-app-<id>]` (`/etc/asterisk/pbx/extensions_ai_apps.conf`):

1. `Answer()`, then `CURL()` posts the call to the local AI service (`POST /v1/calls/start` on
   127.0.0.1:8790, authenticated with a key derived from `/etc/aipbx/ai.token`). The text is
   URL-encoded when the dialplan is written, so nothing the administrator types reaches the
   dialplan parser; Asterisk inserts the call values with `URIENCODE`. Timeout 5 s.
2. The service does the lookup, fills the text and makes the speech (about 0.5 s for a sentence on
   2 cores), and answers with a call ID — or with nothing, and the call goes on.
3. `AudioSocket()` connects the call's audio to the service (127.0.0.1:8791), which plays the
   speech in real time and then closes; the dialplan continues to the destination.

`GET /v1/calls` of the service lists live and recent calls (prepared, playing, played, hangup).
If the caller hangs up during the announcement, the destination is not reached.

Measured on the CI VM: a 97-character text with a lookup was ready in 0.47–0.7 s and played as
6.1 s of speech; the recorded call, transcribed with Vosk, read "ahmet yılmaz borcunuz bin iki yüz
otuz dört lira elli kuruş son ödeme tarihi on beş ekim".
