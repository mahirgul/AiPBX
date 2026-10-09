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
- Disk: about 160 MB for the runtime (ONNX Runtime, numpy, the Turkish text normaliser), plus
  each model's files (EMA Lightning: 34 MB).
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
- **Numbers**: write amounts out for the best result. espeak-ng reads "$1,234.50" as
  "dollar one thousand two hundred thirty four point five zero".

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

Example on the server:

```bash
TOKEN=$(sudo cat /etc/aipbx/ai.token)
curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8790/v1/models
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"model":"ema-lightning","text":"Merhaba, hoş geldiniz.","sample_rate":8000}' \
     -o merhaba.wav http://127.0.0.1:8790/v1/tts
```

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
