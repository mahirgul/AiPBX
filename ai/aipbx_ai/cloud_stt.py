"""Cloud speech to text for voice requests (stdlib only).

A voice-requests application may use a cloud provider instead of a local
model: its config then has "stt_model": "cloud:<provider>" and
"stt_cloud": {"provider", "api_key", "region", "lang"}, sent by the portal
from AI -> Cloud services. The same requests as the portal's
CloudAiService::stt() (web/src/services/ai/CloudAiService.php).

The key is stored with the app config ($AIPBX_AI_DATA/apps/<id>.json, mode
0600, readable by the aipbx-ai user only) and never returned by the API.
"""
import base64
import json
import re
import secrets
import urllib.request

PROVIDERS = ("openai", "groq", "deepgram", "azure", "elevenlabs", "google_ai")
LANGS = {"tr": ("tr-TR", "Turkish"), "de": ("de-DE", "German"), "en": ("en-US", "English")}
TIMEOUT = 15
_REGION = re.compile(r"^[a-z0-9]{3,30}$")


class CloudSttError(Exception):
    pass


def validate(cfg):
    """Normalised stt_cloud settings, or ValueError."""
    if not isinstance(cfg, dict):
        raise ValueError("stt_cloud must be an object")
    provider = cfg.get("provider")
    if provider not in PROVIDERS:
        raise ValueError("stt_cloud.provider is not supported")
    key = cfg.get("api_key")
    if not isinstance(key, str) or not 8 <= len(key.strip()) <= 400 or any(c in key for c in "\r\n"):
        raise ValueError("stt_cloud.api_key is missing")
    lang = cfg.get("lang", "tr")
    if lang not in LANGS:
        raise ValueError("stt_cloud.lang must be tr, de or en")
    region = cfg.get("region") or "westeurope"
    if provider == "azure" and not (isinstance(region, str) and _REGION.match(region)):
        raise ValueError("stt_cloud.region is not valid")
    return {"provider": provider, "api_key": key.strip(), "lang": lang, "region": region}


def _request(url, headers, body):
    req = urllib.request.Request(url, data=body, headers=dict(headers, **{"User-Agent": "aipbx-ai"}), method="POST")
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            return json.loads(resp.read(2 * 1024 * 1024).decode("utf-8"))
    except urllib.error.HTTPError as e:
        raise CloudSttError(f"{e.code}") from None
    except (urllib.error.URLError, TimeoutError, ValueError, OSError) as e:
        raise CloudSttError(type(e).__name__) from None


def _multipart(fields, wav):
    boundary = "----aipbx" + secrets.token_hex(8)
    parts = []
    for name, value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="speech.wav"\r\n'
                 f'Content-Type: audio/wav\r\n\r\n'.encode() + wav + b"\r\n")
    parts.append(f"--{boundary}--\r\n".encode())
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


def transcribe(cfg, wav, request=_request):
    """Text of a WAV (16-bit, any of the usual rates) with the configured provider."""
    p, key, lang = cfg["provider"], cfg["api_key"], cfg["lang"]
    if p in ("openai", "groq"):
        url = ("https://api.openai.com/v1/audio/transcriptions" if p == "openai"
               else "https://api.groq.com/openai/v1/audio/transcriptions")
        model = "gpt-4o-mini-transcribe" if p == "openai" else "whisper-large-v3-turbo"
        body, ctype = _multipart({"model": model, "language": lang, "response_format": "json"}, wav)
        d = request(url, {"Authorization": "Bearer " + key, "Content-Type": ctype}, body)
        return str(d.get("text") or "").strip()
    if p == "deepgram":
        d = request(f"https://api.deepgram.com/v1/listen?model=nova-3&smart_format=true&language={lang}",
                    {"Authorization": "Token " + key, "Content-Type": "audio/wav"}, wav)
        try:
            return str(d["results"]["channels"][0]["alternatives"][0]["transcript"] or "").strip()
        except (KeyError, IndexError, TypeError):
            return ""
    if p == "azure":
        d = request(f"https://{cfg['region']}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1"
                    f"?format=simple&language={LANGS[lang][0]}",
                    {"Ocp-Apim-Subscription-Key": key, "Content-Type": "audio/wav; codecs=audio/pcm; samplerate=16000"}, wav)
        return str(d.get("DisplayText") or "").strip()
    if p == "elevenlabs":
        body, ctype = _multipart({"model_id": "scribe_v1", "language_code": lang}, wav)
        d = request("https://api.elevenlabs.io/v1/speech-to-text", {"xi-api-key": key, "Content-Type": ctype}, body)
        return str(d.get("text") or "").strip()
    if p == "google_ai":
        prompt = (f"Transcribe this telephone audio word for word in {LANGS[lang][1]}. "
                  "Output only the transcript, nothing else. If nothing is said, output nothing.")
        req = {"contents": [{"parts": [{"text": prompt},
                                       {"inline_data": {"mime_type": "audio/wav", "data": base64.b64encode(wav).decode()}}]}],
               "generationConfig": {"temperature": 0}}
        d = request("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent",
                    {"x-goog-api-key": key, "Content-Type": "application/json"}, json.dumps(req).encode())
        try:
            return str(d["candidates"][0]["content"]["parts"][0]["text"] or "").strip()
        except (KeyError, IndexError, TypeError):
            return ""
    raise CloudSttError("unsupported provider")
