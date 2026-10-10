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
instead (or, without one, the text without the missing values). Numbers, amounts and dates are read out
properly: Turkish "1.234,50 TL" or "1.234,50 lira" becomes "bin iki yüz otuz dört lira elli kuruş".

Example: text "Sayın {caller_name}, borcunuz {amount}. Son ödeme tarihi {date}.", lookup
`https://crm.example.com/aipbx/balance`, afterwards: the accounting queue.

Voice: a model of *AI → Local models*, or "the engine for the language" chosen on
*AI → Cloud services*. Speed 0.5–2. **Calls at the same time**: above this number, callers go
straight to the destination (a small server prepares one announcement per voice at a time).

## Voice requests

The caller hears a greeting, says what they want, and the application recognises the request,
answers by voice and acts on it — for example a hotel reception line: the guest calls from the room
phone and says "could I get two more towels?".

1. **Greeting** ("Good evening, reception here. How can I help you?") read by a voice model.
2. **Listening:** until the caller stops speaking (about 0.8 s of silence) or at most the set number
   of seconds. Pressing a key also ends listening.
3. **Speech to text** with a local model (Vosk or Whisper, see [Local AI models](local-ai.md)), the
   engine set for the language under *AI → Cloud services*, or a cloud provider directly (OpenAI,
   Groq, Deepgram, Azure, ElevenLabs, Google AI — those with a key saved there). The cloud key is
   kept by the AI service in a file only it can read; when the provider cannot be reached the call
   counts as "not understood" and goes to the application's destination.
4. **Matching** the text to the configured **requests**. Each request has:
   - **keywords** — word beginnings, the strongest signal ("temiz" also matches "temizliği");
     a keyword that several requests share counts less;
   - **example sentences** — similar wording counts as well;
   - the spoken **reply** ("Your request has been received");
   - an **e-mail address** that gets the request with what was said and the caller's recording;
   - where the call goes **afterwards** (empty: it ends after the reply).
5. When it is not sure (below the *certainty needed*), it asks again (*ask again* times), then says
   the *before passing on* text and sends the call to the application's destination — e.g. reception.

The room comes from the calling extension: a guest does not have to say it. Every call ends up in
**Recognised requests** on the page: time, caller, request (or "not understood"), what was said and
the recording; staff tick *Done* when it is handled. The e-mail uses the template **AI request**
(*E-mail → Templates*).

How well it works depends mostly on the keywords and the model: on 8 kHz telephone audio the small
Turkish Vosk model gets about one word in four wrong, Whisper small about one in thirteen (but it
needs ~650 MB of memory and about a second of both cores per request); and short requests with a clear keyword ("havlu", "klima
çalışmıyor") come through best. Give each request a few keywords and two or three example
sentences, and keep the "not understood" path going to a person.

Example keywords for a hotel (word beginnings; Turkish softening such as "hesap" → "hesabımı" is
understood; a keyword two requests share counts less, and in "temiz havlu" the noun wins):

| Request | Keywords |
|---------|----------|
| Towels | havlu |
| Cleaning | temiz, topla, çarşaf, süpür |
| Room service | yemek, sipariş, menü, kahvaltı, servis |
| Wake-up call | uyandır, uyan, alarm |
| Fault | çalışmıyor, bozuk, gelmiyor, yanmıyor, açılmıyor, arıza |
| Checkout | çıkış, hesap, fatura, ödeme |

Example sentences help when a caller uses none of the keywords, but only with a lower
**certainty needed** (about 0.3); at 0.5 the keywords decide. The recording and the transcript are
kept by the AI service for 24 hours (the request list keeps the text). Technical details:
[Local AI models → Voice requests](local-ai.md#voice-requests-voice_requests).


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
