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
