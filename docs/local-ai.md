# Local AI models

**AI → Local models** runs AI models **on the PBX itself**: text and audio never leave the server,
there is no API key and no cost per use. The first model is **EMA Lightning**, a Turkish
text-to-speech model (Apache-2.0). More models (EmbeddingGemma 2 for answering machine detection
and routing by speech) follow; see [Roadmap](roadmap.md), item 5.

Everything is **off** by default. A normal install or update copies only the small service code;
the Python runtime with ONNX Runtime (about 160 MB) is installed when the administrator asks for it.

## Requirements

- No GPU needed: the models run on the CPU.
- RAM: EMA Lightning uses about 250 MB; the service is limited to 2 GB.
- Disk: about 160 MB for the runtime (ONNX Runtime, numpy, the text normalisers), plus each
  model's files (EMA Lightning: 34 MB).
- Internet access while the runtime and a model are downloaded (PyPI for the runtime, GitHub
  for the model files). Once downloaded, nothing goes online any more.

Measured on a 2-core Intel i7-7700 (no GPU), EMA Lightning: about 11× faster than real time
(8× on one core), a 6.4 s IVR sentence ready in 0.55 s, model load 1.3 s. The page's
**speed test** measures the same on your server.

### Why ONNX Runtime

Versions 1.9.0–1.9.1 ran the model with PyTorch: about 1.1 GB of runtime, up to 1 GB of memory
and 3.6–4.5× real time on the same server. The model is the same; only the format changed. Its
ONNX export (made with `export/ema_to_onnx.py` of
[sewox/turkish-neural-tts](https://github.com/sewox/turkish-neural-tts), which checks every
graph against the PyTorch model) is published as the release
[`models-ema-lightning-1`](https://github.com/mahirgul/AiPBX/releases/tag/models-ema-lightning-1)
with its licence, a NOTICE with the source revision and SHA-256 sums. The service downloads it
from there and refuses files whose checksum differs. The text handling around the model is a
numpy port of the `ema-lightning` package (`ai/aipbx_ai/ema_engine.py`); with the same noise it
gives the same audio as the PyTorch engine (largest difference 4·10⁻⁵).

A server that had the PyTorch version: the update rebuilds the runtime without torch, and a
model that was installed is downloaded again in the new format on the next start.

## Models

| Model | Language | Engine | Licence | Our measurement (2 cores) |
|-------|----------|--------|---------|---------------------------|
| EMA Lightning | Turkish | EMA (ONNX) | Apache-2.0 | 11–12× real time, ~115–250 MB |
| Piper MLS | German (236 speakers) | Piper | CC BY 4.0 (name the source) | 13×, ~146 MB |
| Piper Thorsten | German, male | Piper | not cleared for commercial use* | 13×, ~122 MB |
| Piper Kerstin | German, female | Piper | not cleared for commercial use* | 16×, ~166 MB |
| Piper Cori | English (UK), female | Piper | public domain | 2× (high quality: announcements only) |
| Kokoro Heart | English (US), female | Kokoro | Apache-2.0 | 1.7×, ~460 MB (shared, see below) |
| Kokoro Michael | English (US), male | Kokoro | Apache-2.0 | 1.7×, shared |
| Kokoro Emma | English (UK), female | Kokoro | Apache-2.0 | 1.7×, shared |
| Kokoro George | English (UK), male | Kokoro | Apache-2.0 | 1.7×, shared |

\* Their recordings are CC0, but the models were fine-tuned from voices whose data allows research
use only (lessac) or no commercial use (ryan).

Piper voices come from [rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices) at a
pinned revision, each file checked by SHA-256. They need the `espeak-ng` program, which the runtime
installs. Several models can run at the same time; *Stop* frees their memory and keeps the files.
A stopped model stays stopped after a restart (`/var/lib/aipbx-ai/state.json`). Downloads stop at
the disk limit for models, 5 GB by default (`AIPBX_AI_DISK_LIMIT_MB` in the unit).

### Kokoro

[Kokoro-82M](https://huggingface.co/hexgrad/Kokoro-82M) (Apache-2.0, model and voices) is the
recommended English voice: clearly more natural than Piper. The four voices in the catalogue are
the best-graded English ones of its
[VOICES.md](https://huggingface.co/hexgrad/Kokoro-82M/blob/f3ff3571791e39611d31c381e3a41a3af07b4987/VOICES.md)
(Heart A, Emma B-, Michael C+, George C; the grades rate the training data). None of them was
trained with CC BY audio (only the Japanese and French voices were). Kokoro also speaks Spanish,
French, Italian, Brazilian Portuguese and Hindi (no German); the engine supports those voices,
the catalogue has only English ones so far.

- **Files**: the ONNX export [onnx-community/Kokoro-82M-v1.0-ONNX](https://huggingface.co/onnx-community/Kokoro-82M-v1.0-ONNX)
  at a pinned revision, each file checked by SHA-256. All voices use the same 311 MB model
  (`onnx/model.onnx`, fp32), which is stored once in `/var/lib/aipbx-ai/models/_shared/kokoro/`
  and deleted with the last Kokoro voice; each voice adds 0.5 MB. The page shows the model's size
  with one of the installed voices (the one whose id sorts first), so the total is right.
- **Memory**: the running voices share one model in memory, about 460–650 MB however many
  of them run.
- **Speed**: about 1.7× real time on 2 cores (1.15× on one), the first sentence after about
  1.1 s (1.5–2 s on one core): fine for announcements and prompts made in advance, slow for live
  calls.
- **Why fp32**: the export also has fp16, 8-bit and 4-bit variants. On a 2-core CPU none of the
  working ones is faster (all 1.7–1.8×); fp16 produces invalid audio (NaN) and the 8-bit/fp16 mix
  crashes ONNX Runtime. The quantized ones are smaller (92–305 MB, the 8-bit one uses 400 MB of
  memory instead of 650 MB) but sound different: their log-mel distance to the fp32 output is
  3.0–3.8 dB, as large as reading 5–10 % faster (2.9 / 3.7 dB) and about half the distance to
  another voice (7.1 dB). With no speed to gain, the reference quality wins.
- **Pronunciation**: Kokoro was trained on the phonemes of its own G2P,
  [misaki](https://github.com/hexgrad/misaki), which pulls in spaCy and its language models.
  The service uses the `espeak-ng` program instead (as Piper and kokoro-onnx do) and rewrites its
  phonemes into Kokoro's the way misaki's own espeak fallback does. That is a known, workable
  fallback, but English sounds a little less natural: about three words in four come out as in
  misaki's lexicon; the rest are mostly unstressed vowels (ə / ɪ / ʌ), function words ("to",
  "the") and syllabic endings. Spanish, French, Italian, Portuguese and Hindi use espeak-ng in
  Kokoro itself, so nothing is lost there.
- **Numbers**: amounts, times and dates are written out before espeak-ng sees them (see below).

### Numbers, dates and abbreviations (Piper and Kokoro)

espeak-ng reads written numbers badly in announcements: German "1.234,50 Euro" became "eintausend
zweihundert vierunddreißig Komma fünf null Euro", "14:30 Uhr" "vierzehn Uhr dreißig Uhr", and
"am 3. Oktober" ended the sentence after "drei"; English "$1,234.50" became "dollar one thousand …
point five zero". Before phonemising, the service now writes them out for **German** and
**English** voices (`aipbx_ai/textnorm.py`, cardinal and ordinal words from
[num2words](https://github.com/savoirfairelinux/num2words), LGPL-2.1, installed as a separate
package). The language is the Piper voice's `language.code` (or its espeak-ng voice), and for
Kokoro the voice prefix (`a` en-US, `b` en-GB); other languages are left as they are. EMA
Lightning does the same for Turkish with normalizer-tr.

| Written | German voice | English voice |
|---------|--------------|---------------|
| Amounts | "1.234,50 €" → "eintausend zweihundert vier und dreißig Euro fünfzig", "0,99 €" → "neun und neunzig Cent", "5,- €" → "fünf Euro" | "$1,234.50" → "one thousand two hundred thirty-four dollars and fifty cents", "$0.50" → "fifty cents" |
| Times | "14:30", "14:30 Uhr", "14.30 Uhr" → "vierzehn Uhr dreißig", "9-17 Uhr" → "neun bis siebzehn Uhr" | "14:30" → "fourteen thirty", "9:05" → "nine oh five", "10:00" → "ten o'clock", "2:30 pm" → "two thirty PM" |
| Dates | "am 3. Oktober" → "am dritten Oktober", "03.10.2026" → "dritter Oktober zweitausend sechs und zwanzig" | "October 3rd" → "October third", "3 October" → "the third of October", "10/03/2026": en-US October 3, en-GB 10 March |
| Ordinals | "im 1. Stock" → "im ersten Stock" (before a month or a short list of nouns only) | "21st" → "twenty-first" |
| Numbers | "2,5" → "zwei Komma fünf", "-3" → "minus drei", "50 %" → "fünfzig Prozent" | "2.5" → "two point five", "50%" → "fifty percent" |
| Phone numbers | "0212 555 12 34" → "null zwei eins zwei, fünf fünf fünf, eins zwei, drei vier" | "zero two one two, …" ("zero", not "oh") |
| Codes | "AB-1234" → "A B eins zwei drei vier" | letters spelled, digits one by one |
| Abbreviations | z. B., d. h., ca., Nr., bzw., usw., inkl., ggf., Tel., Str., Mio., Mrd., Dr., Mo.–Fr. | No. (before a number), approx., e.g., i.e., etc., Mr, Mrs, Dr, St. (Saint/Street only when clear), Mon–Fri |

Phone numbers are digit strings that start with 0 or + or have 7 or more digits, also when written
in groups ("0212 555 12 34", "+49 30 1234567", "(555) 123-4567"); the groups are read with a short
pause between them. German ordinals take their ending from the word before them: "-en" after am,
im, vom, zum, zur, dem, den, ab, bis, seit …, "-e" after der/die/das, otherwise the strong ending
by gender ("dritter Oktober"). German number words are spaced the way espeak-ng reads digits
("vier und dreißig"): written as one long word, espeak-ng mispronounces them. Anything a rule does
not recognise is left for espeak-ng, and words written out are never changed.

### Speech to text (Vosk)

| Model | Language | Size | Licence | Our measurement |
|-------|----------|------|---------|-----------------|
| Vosk small | Turkish | 36 MB | Apache-2.0 | 5× real time, ~120–180 MB; about 1 word in 4 wrong on 8 kHz telephone audio |
| Vosk small | German | 45 MB | Apache-2.0 | |
| Vosk small | English (US) | 40 MB | Apache-2.0 | |

The zip files come from alphacephei.com, pinned by SHA-256, and are unpacked into the model's folder
(paths leaving it are refused). `POST /v1/stt?model=<id>` takes a WAV file (8–48 kHz, up to 60 s);
the page's *Try: speech to text* panel records from the microphone (up to 15 s) or takes a WAV file.

### Adding voices from the Piper voice list

*Add a voice* lists every voice of [rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices)
(about 180 voices in 58 languages), filtered by language and quality. Adding one pins the list's
current revision, takes the model's SHA-256 from Hugging Face and checks the config against the
list's MD5. The licence line of the voice's model card is shown; voices under a non-commercial
licence, or fine-tuned from another voice, are marked as not cleared for commercial use. Added voices
are kept in `/var/lib/aipbx-ai/models/<id>/manifest.json` and come back after a restart; *Remove*
deletes them completely.

## Steps

1. **Install the runtime** (once): creates `/opt/aipbx-ai/venv` and starts the `aipbx-ai`
   service. Takes a few minutes; the page shows the progress.
2. **Install a model**: accept its licence; the model is downloaded and loaded (state
   *downloading* → *loading* → *ready*).
3. Use it — for example as a TTS provider (roadmap 5, step 1).

After a reboot or a service restart every installed model is loaded again automatically.

## Files and paths

| Path | What |
|------|------|
| `/opt/aipbx-ai/app` | The service (`ai/` of the repository), replaced on every install/update |
| `/opt/aipbx-ai/venv` | Python runtime (ONNX Runtime, numpy, model packages); only `aipbx-ai-setup install` creates it |
| `/var/lib/aipbx-ai/models/<id>` | Downloaded model files |
| `/var/lib/aipbx-ai/runtime.json` | State of the last runtime install/remove |
| `/var/log/aipbx-ai-setup.log` | Output of the runtime install (pip) |
| `/etc/aipbx/ai.token` | Shared secret of the portal and the service (root:www-data 0640) |
| `/usr/local/sbin/aipbx-ai-setup` | Installs / removes the runtime (root) |
| `aipbx-ai.service` | The service; logs: `journalctl -u aipbx-ai` |

## How it works

- `aipbx-ai` is a small Python HTTP service (standard library only) listening on
  **127.0.0.1:8790**; it cannot be reached from the network. It runs as the system user
  `aipbx-ai` (no login, home `/var/lib/aipbx-ai`) in a hardened unit: read-only system, write
  access only to `/var/lib/aipbx-ai`, no access to home directories, `MemoryMax=2G`,
  `Nice=5` and a lower CPU weight, so calls keep priority over AI work.
- Every request needs `Authorization: Bearer <token>` with the token of `/etc/aipbx/ai.token`
  (compared in constant time). systemd hands the token to the service (`LoadCredential`), so the
  `aipbx-ai` user itself cannot read the file. Requests that carry `X-Forwarded-For` or
  `Forwarded` are refused. The token is created once by install.sh and never rotated by an update.
- The portal (www-data) installs and removes the runtime through the root helper:
  `aipbx-priv ai runtime-install` (runs `aipbx-ai-setup install` in its own unit,
  `aipbx-ai-setup.service`, and returns at once), `ai runtime-remove`, `ai runtime-status`,
  `ai restart`.
- An update keeps the runtime. When a release needs other Python packages
  (`ai/requirements.txt` changed), the update refreshes the runtime in the background.
- One synthesis runs at a time per model; up to 4 more requests wait, further ones are refused
  (HTTP 503).

## API

JSON unless noted. Errors are HTTP 4xx/5xx with `{"error": "<message>"}`.

| Request | Answer |
|---------|--------|
| `GET /v1/health` | `{"ok":true,"version":"1","python":"3.14.4","onnxruntime":"1.30.0"\|null,"cpu":{"cores":2,"model":"..."},"ram":{"total_mb":N,"available_mb":N},"process":{"rss_mb":N,"cpu_percent":12.5}}` — `cpu_percent` is the service's use since the previous health call, 100 = one full core |
| `GET /v1/models` | `{"models":[{"id":"ema-lightning","title":"EMA Lightning","kind":"tts","languages":["tr"],"license":"Apache-2.0","license_url":"...","homepage":"...","download_mb":36,"state":"absent\|downloading\|installed\|loading\|ready\|error","progress":0-100,"disk_mb":N,"error":null\|"..."}]}` |
| `POST /v1/models/{id}/install` `{"accept_license":true}` | `202 {"state":"downloading"}` (or the current state when already downloading, loading or ready; `loading` when the files are there). 400 without `accept_license: true` |
| `POST /v1/models/{id}/remove` | `{"state":"absent"}`; 409 while downloading or loading |
| `POST /v1/models/{id}/benchmark` | `{"audio_seconds":9.52,"first_audio_ms":415,"seconds":2.108,"realtime_factor":4.52}` — a fixed Turkish text at 8000 Hz; 409 unless ready |
| `POST /v1/tts` `{"model":"ema-lightning","text":"...","speed":1.0,"sample_rate":8000}` | `200 audio/wav`, 16-bit mono PCM at the requested rate |

`/v1/tts` checks: `text` 1–5000 characters after trimming, `speed` 0.5–2.0 (default 1.0),
`sample_rate` 8000, 16000, 24000 or 48000 (default 8000). Errors: 400 (invalid value), 404
`unknown model`, 409 `model not ready`, 413 (body over 128 KB), 503 `too many requests waiting`,
500 `synthesis failed`. A long text takes time on a CPU (5000 characters: about a minute), so a
client should wait up to a few minutes.

`GET /v1/calls` lists the live AI calls and the last 50 finished ones (kept for 10 minutes), see
[Live calls](#live-calls-ai-applications).

Example on the server:

```bash
TOKEN=$(sudo cat /etc/aipbx/ai.token)
curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8790/v1/models
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"model":"ema-lightning","text":"Merhaba, hoş geldiniz.","sample_rate":8000}' \
     -o merhaba.wav http://127.0.0.1:8790/v1/tts
```

## Live calls (AI applications)

An AI application answers calls itself (roadmap 5, AI applications). The first type is
**announcement with values**: the caller hears a text, rendered from a template with values from the
dialplan and optionally from a lookup URL, then the call continues to the application's destination.

The portal writes one context per application:

```
[aipbx-ai-app-<id>]
exten => s,1,Answer()
 same => n,Set(AI_CALL=${CURL(http://127.0.0.1:8790/v1/calls/start,app=<id>&key=<dialplan key>&model=<model id>&speed=<0.5-2>&max=<n>&caller=${URIENCODE(${CALLERID(num)})}&did=${URIENCODE(${AI_DID})}&uniqueid=${UNIQUEID}&text=${URIENCODE(<template>)}&lookup=${URIENCODE(<url or empty>)}&fallback=${URIENCODE(<fallback text>)})})
 same => n,GotoIf($["${LEN(${AI_CALL})}" != "36"]?done)
 same => n,AudioSocket(${AI_CALL},127.0.0.1:8791)
 same => n(done),Goto(<destination>)
```

1. **`POST /v1/calls/start`** (form-urlencoded, as `CURL()` posts it) prepares the call: it checks the
   key, does the lookup, renders the text and synthesises it at 8 kHz. The answer is always
   `200 text/plain`: only the call's UUID (36 characters), or an **empty body** when the call is
   refused (the reason is in `journalctl -u aipbx-ai`): wrong key, unknown model, model not ready or
   not a text-to-speech model, the application already has `max` live calls, more than 100 live
   calls in all, invalid fields, the model's queue is full, or the synthesis failed. With an empty
   answer the dialplan skips the AI part and goes straight to the destination.
2. **`AudioSocket()`** connects to **127.0.0.1:8791** (`AIPBX_AI_AUDIOSOCKET_PORT`) and sends the
   UUID; the service plays the audio in 20 ms frames, paced in real time (at most 60 ms ahead), and
   200 ms after the last frame sends *terminate*: `AudioSocket()` returns and the dialplan
   continues. A prepared call that nobody connects to within 30 s expires.

**Key.** The dialplan cannot read `/etc/aipbx/ai.token`, so this one endpoint takes no bearer token.
`key` is the lowercase hex HMAC-SHA256 of the bytes `aipbx-dialplan` with the token (the file's
content, trimmed) as the key, compared in constant time:

```bash
printf %s aipbx-dialplan | openssl dgst -sha256 -hmac "$(sudo cat /etc/aipbx/ai.token)" -r | cut -d' ' -f1
```

Requests with `X-Forwarded-For` or `Forwarded` are refused here too; every other endpoint keeps
the bearer token.

**Fields.** `app` 1–1000000; `model` a ready text-to-speech model; `speed` 0.5–2 (default 1); `max`
1–50 live calls of the application (default 4); `text` 1–2000 characters (UTF-8, after URL
decoding); `fallback` up to 2000; `lookup` an http(s) URL up to 500 characters (or empty). `caller`
and `did` (up to 40 of `0-9 + * # A-Z a-z _ . -`) and `uniqueid` (up to 64) are informational: an
invalid value is dropped (logged), not a reason to refuse the call.

**Lookup.** When `lookup` is set, the service GETs it with `caller`, `did` and `app` added to its
query (other parameters are kept; no redirects, no proxy), 2 s at most, 64 KB at most. The answer
must be a JSON object; its top-level string and number values with names of `A-Z a-z 0-9 _`
(1–40 characters) fill the `{name}` placeholders of the text (nested objects, lists, booleans and
null are ignored; each value is cut at 200 characters). If the lookup fails (timeout, HTTP error,
not a JSON object) or a placeholder has no value, the `fallback` text is used when it is not empty
(its placeholders filled from whatever values there are); otherwise the placeholders without a
value are removed. Example: text `Merhaba ${CALLERID(name)}, borcunuz {amount} liradır.` with a
lookup answering `{"amount":"1.234,50"}`.

**Timing.** The dialplan waits while the call is prepared: lookup (≤ 2 s) plus synthesis. EMA
Lightning makes a 4.3 s sentence in about 0.35–0.45 s on 2 cores; a 10 s text takes about 1 s.
One synthesis runs at a time per model, so simultaneous calls of the same model wait for each other.

**Live view.** `GET /v1/calls` (bearer token):

```json
{"calls":[{"uuid":"9dc14e44-…","app":901,"model":"ema-lightning","state":"prepared|playing|done",
  "started":1791616715,"caller":"+905321234567","did":"02120000000","seconds":4.9,
  "audio_seconds":4.32,"chars":41,"prepare_ms":446,"result":null|"played"|"hangup"|"expired"|"error",
  "dtmf":"","ended":null|1791616720}],
 "per_app":{"901":1}}
```

Live calls come first (oldest first), then up to 50 finished calls of the last 10 minutes (newest
first). `seconds` is the time since the call was started (until its end), `per_app` counts the live
(prepared or playing) calls. Each call writes one journal line, e.g.
`call app=901 caller=+905321234567 chars=41 audio=4.3s prepared=446ms played=4.3s in 4.52s lookup=ok dtmf=- result=played`.

**AudioSocket protocol.** Messages are 1 byte type, 2 bytes big-endian length, payload: `0x00`
terminate, `0x01` UUID (16 bytes), `0x03` DTMF (one ASCII byte), `0x10` audio (16-bit
little-endian signed linear, 8 kHz mono), `0xff` error. The specification also lists `0x11`–`0x18`
for 12–192 kHz audio; Asterisk 22's `AudioSocket()` application sets the channel to 8 kHz and sends
only `0x10` (and treats anything other than `0x10`/`0x00` from the server as an error), so the
service sends only `0x10` and `0x00` and accepts all audio types. Audio and DTMF from the caller
are read into the call (DTMF digits are shown in the live view); the announcement does not
use them yet.

**Hang-ups.** When the caller hangs up during the announcement, `AudioSocket()` fails (Asterisk logs
a warning `Failed to receive frame from channel …`) and the channel hangs up: the destination is not
reached, only an `h` extension runs. The service ends the call with result `hangup`.

### Voice requests (`voice_requests`)

The caller says what they want ("could I get two more towels?"); the service recognises it with a
speech-to-text model, matches it to one of the application's **intents**, confirms it by voice and
hands the intent to the dialplan. Code: `calls.py` (the call), `voice_requests.py` (configs, VAD,
results), `intents.py` (matching).

**Config push** (bearer token). The portal pushes the application's config; the service keeps it in
`$AIPBX_AI_DATA/apps/<id>.json` (atomic write) and loads it on start.

| Request | Answer |
|---------|--------|
| `PUT /v1/apps/{id}` (id 1–1000000, JSON body ≤ 4 MB) | `200` the stored config with `"id"`; `400 {"error"}` when invalid |
| `GET /v1/apps` | `{"apps":[{"id":902,"type":"voice_requests",…}]}` |
| `GET /v1/apps/{id}` | the config, or 404 |
| `DELETE /v1/apps/{id}` | `{"deleted":true\|false}` |

```json
{"type":"voice_requests","tts_model":"ema-lightning","stt_model":"vosk-tr-small","speed":1.0,
 "greeting":"Merhaba, nasıl yardımcı olabilirim?","retry":"Anlayamadım, isteğinizi tekrar söyler misiniz?",
 "not_understood":"Sizi resepsiyona aktarıyorum.","listen_seconds":7,"retries":1,"threshold":0.5,
 "intents":[{"id":"towels","name":"Havlu","keywords":["havlu"],"examples":["İki havlu daha alabilir miyim?"],
             "reply":"Havlu talebiniz alındı, en kısa sürede getirilecek."}]}
```

Checks: `type` must be `voice_requests`; `tts_model`/`stt_model` known models of the right kind
(they may still be downloading — calls are refused until they are ready); `greeting` required;
`retry`, `not_understood` and each `reply` may be empty (then nothing is said); every text ≤ 1000
characters; `speed` 0.5–2 (default 1); `listen_seconds` 2–15 whole seconds (default 7); `retries`
0–2 (default 1); `threshold` 0–1 (default 0.5); 1–30 intents with unique ids `[a-z0-9_-]{1,40}`
(`none` is reserved), each with ≤ 30 keywords and ≤ 30 examples and **at least one keyword or
example**. Unknown fields are ignored. The prompts (greeting, retry, not_understood, replies) are
synthesised on first use and cached until the next `PUT`/`DELETE`; the first call of a config makes
the greeting and the rest in the background.

**Dialplan.** `POST /v1/calls/start` with `app`, `key`, `max`, `caller`, `did`, `uniqueid` and **no
`text` and no `model`** starts a voice_requests call when a config exists for the app (else: empty
answer). It is refused (empty answer) when there is no config, the TTS or STT model is not ready, the
app has `max` live calls (default 4), or the greeting cannot be made. Afterwards:

```
[aipbx-ai-app-<id>]
exten => s,1,Answer()
 same => n,Set(AI_CALL=${CURL(http://127.0.0.1:8790/v1/calls/start,app=<id>&key=<key>&max=<n>&caller=${URIENCODE(${CALLERID(num)})}&did=${URIENCODE(${AI_DID})}&uniqueid=${UNIQUEID})})
 same => n,GotoIf($["${LEN(${AI_CALL})}" != "36"]?done)
 same => n,AudioSocket(${AI_CALL},127.0.0.1:8791)
 same => n,Set(AI_INTENT=${CURL(http://127.0.0.1:8790/v1/calls/${AI_CALL}/result?key=<key>)})
 ...                       (AI_INTENT: the intent id, "none", or empty)
 same => n(done),Goto(<destination>)
```

**The call.** greeting → listen → (if not understood and retries left: retry prompt → listen) → the
intent's reply, or `not_understood` → the result is saved → *terminate*, so the result is there as
soon as `AudioSocket()` returns. There is no barge-in: what the caller says while a prompt plays is
ignored. Between prompts the service sends silence frames — Asterisk's `AudioSocket()` hangs up
after 2 s without a message from the server.

**Listening** (energy VAD on 20 ms frames, `voice_requests.Listener`): the first 300 ms set the noise
floor (mean RMS of the quieter half of the frames, 20–800); before speech the floor follows the
quiet frames (quickly down, slowly up). Speech level is RMS ≥ max(3 × floor, 250) (≈ +10 dB over the
noise, ≥ −42 dBFS); 3 frames in a row (60 ms) start the utterance (300 ms before it are kept); 800
ms below speech level end it (300 ms after the last loud frame are kept); `listen_seconds` after
the start of listening cut it. Nothing at speech level within 4 s (or `listen_seconds`, if shorter)
is silence. A DTMF digit ends listening (the digits are stored in `dtmf`; speech heard before it is
still transcribed). When Asterisk sends no frames for more than 200 ms (a channel without audio)
the missing time counts as silence. The utterance goes to the STT model as an 8 kHz WAV in a
thread of its own while the socket keeps being read and fed.

**Matching** (`intents.match(transcript, intents, threshold)`, pure Python):

- Text is lower-cased the Turkish way (İ→i, I→ı), diacritics are folded (ç c, ğ g, ı i, ö o, ş s,
  ü u, ä a, ß ss), punctuation removed; so keywords may be typed with or without Turkish letters.
- A keyword hits a word that starts with it ("temiz" → "temizliği"); weaker *stem hits* (×0.85): a
  final p/ç/t/k/g softened to b/c/d/ğ ("hesap" → "hesabımı", "yemek" → "yemeğine"), and keywords
  of ≥ 8 letters may differ in their last 4 ("açılmıyor" → "açılmayan"). Several words: a phrase.
- Specificity: a keyword's weight is divided by the number of intents having the same keyword stem;
  and when hits of two different intents are adjacent words, the first is a modifier and dropped
  (noun phrases are head-final: "temiz havlu" is towels, not cleaning).
- keyword score = min(1, 0.7 × best weight + 0.1 × the other hits' weights) (0.7 for one keyword,
  +0.1 per further keyword); similarity = best cosine of TF-IDF vectors of character 3/4-grams
  between the transcript and the intent's examples; **score = max(keyword score, 0.6 × similarity)**.
- The best intent wins unless its score < `threshold`, or both best and second are > 0 and differ by
  less than 0.1: then the attempt is not understood.

Similarity alone stays below 0.6 (paraphrases score about 0.2–0.4), so with the default threshold
0.5 keywords decide and examples only help with a threshold of about 0.3. On 24 Turkish hotel
requests as Vosk wrote them from telephone audio, keywords alone got 20 right (the other 4: the
request word was misrecognised or never said — "aldım getirebilir misiniz" for "havlu getirebilir
misiniz" — they end as `none`, never as a wrong intent); with examples and threshold 0.3: 23.

**Results** (kept 24 hours: memory and `$AIPBX_AI_DATA/calls/<uuid>.json`, the caller's last
utterance as `<uuid>.wav`; a cleanup runs every 5 minutes):

| Request | Auth | Answer |
|---------|------|--------|
| `GET /v1/calls/{uuid}/result?key=<dialplan key>` | dialplan key | `200 text/plain`: the intent id, `none` (not understood, silence, hang-up), or empty (unknown call, wrong key) |
| `GET /v1/calls/{uuid}` | bearer token or `?key=<dialplan key>` | the result JSON, 404 unknown |
| `GET /v1/calls/{uuid}/audio` | bearer token or `?key=` | `audio/wav` (8 kHz mono), 404 when there is none |

The `?key=` alternative is for the portal's script that the dialplan starts as the `asterisk` user
(which cannot read the token); a request with an `Authorization` header is checked as bearer only.
Requests with `X-Forwarded-For`/`Forwarded` are refused (`/result` answers empty).

```json
{"uuid":"a7fc3dcd-…","type":"voice_requests","app":902,"caller":"101","did":"","uniqueid":"1760…",
 "started":1791616715,"ended":1791616726,"intent":"towels","intent_name":"Havlu","score":0.7,
 "transcripts":["odaya iki havlu daha gönderir misiniz"],"dtmf":"",
 "result":"matched|not_understood|silence|hangup","audio":true,
 "attempts":[{"reason":"speech|timeout|silence|dtmf","transcript":"…","intent":"towels","score":0.7,
   "second":0.0,"scores":{"towels":0.7,…},"dtmf":"","listened_seconds":3.69,"speech_seconds":2.52,"stt_ms":434}],
 "timings":{"speech_end_to_reply_ms":1259}}
```

`GET /v1/calls` shows voice_requests calls with `"type":"voice_requests"`, `intent` and `transcript`
(the last non-empty transcript); each call also writes a journal line with result, intent and score.

**Measured** on the CI VM (2 cores, Asterisk 22, EMA Lightning + vosk-tr-small, the caller's
phrases synthesised by EMA Lightning and played into the call): "Odaya iki havlu daha gönderir
misiniz?" → `odaya iki havlu daha gönderir misiniz` → towels 0.7; "Odadaki klima çalışmıyor." →
fault 0.7; "Hesabımı kapatmak istiyorum, çıkış yapacağım." → checkout 0.785; "Yarın sabah yedide
beni uyandırır mısınız?" → `… uyandır musunuz` → wakeup 0.8; a silent caller → retry →
not_understood → `none`. From the end of speech to the start of the reply: 1.25–1.43 s (0.8 s of
it is the VAD's end-of-speech wait, 0.42–0.58 s the transcription).

## Removing

- One model: *Remove* on the page (`POST /v1/models/{id}/remove`) unloads it and deletes its files.
- Everything: *Remove runtime* on the page, or `sudo aipbx-ai-setup remove`. Stops and disables
  the service and deletes `/opt/aipbx-ai/venv` and every downloaded model (`/var/lib/aipbx-ai/models`, and the `hf` and
  `cache` folders of 1.9.0–1.9.1). The service code, the unit, the `aipbx-ai` user and the token stay, so the
  runtime can be installed again at any time.

## Troubleshooting

- **Runtime install failed**: see `/var/log/aipbx-ai-setup.log`; `sudo aipbx-ai-setup status`
  shows the step that failed. Installing again resumes.
- **Model in state *error***: the message is shown on the page; `journalctl -u aipbx-ai` has the
  details. *Install* again retries (the files are not downloaded twice).
- **Service does not start**: `systemctl status aipbx-ai`, `journalctl -u aipbx-ai`. It needs
  `/etc/aipbx/ai.token`; `sudo bash install.sh --upgrade` (or `aipbx-update`) recreates a
  missing token.
