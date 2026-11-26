"""Cloud speech to text for voice requests (stdlib only).

A voice-requests application listens through a cloud provider: its config has
"stt_model": "cloud:<provider>" and "stt_cloud": {"provider", "api_key",
"region", "lang", "model"}, sent by the portal
from AI -> Cloud services. The same requests as the portal's
CloudAiService::stt() (web/src/services/ai/CloudAiService.php).

The key is stored with the app config ($AIPBX_AI_DATA/apps/<id>.json, mode
0600, readable by the aipbx-ai user only) and never returned by the API.
"""
import base64
import ipaddress
import json
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request

from . import langs
from .cloud_llm import ALLOW_PRIVATE_URLS, DEFAULT_MODELS, ENDPOINTS, _BASE_URL, assert_public_url, check_model

PROVIDERS = ("openai", "groq", "deepgram", "azure", "elevenlabs", "google_ai", "openai_compat")
TIMEOUT = 15
_REGION = re.compile(r"^[a-z0-9]{3,30}$")


class CloudSttError(Exception):
    pass


def _is_local_http(url):
    host = (urllib.parse.urlsplit(url).hostname or "").strip(".").lower().split("%", 1)[0]
    if host == "localhost":
        return True
    try:
        ip = ipaddress.ip_address(host)
        return ip.is_private or ip.is_loopback
    except ValueError:
        return False


def _base_url(value):
    base_url = value or ""
    if not isinstance(base_url, str) or len(base_url) > 200 or not _BASE_URL.match(base_url.rstrip("/")):
        raise ValueError("stt_cloud.base_url is not valid")
    clean_base = base_url.rstrip("/")
    try:
        assert_public_url(clean_base, resolve=False, allow_private=True, allow_loopback=True)
    except ValueError:
        raise ValueError("stt_cloud.base_url is not valid") from None
    if urllib.parse.urlsplit(clean_base).scheme == "http" and not (ALLOW_PRIVATE_URLS or _is_local_http(clean_base)):
        raise ValueError("stt_cloud.base_url is not valid")
    return base_url


def validate(cfg):
    """Normalised stt_cloud settings, or ValueError."""
    if not isinstance(cfg, dict):
        raise ValueError("stt_cloud must be an object")
    if 'secret_ref' in cfg:
        from .secret_refs import validate_reference
        out = validate_reference(cfg, "stt")
        if cfg.get("model"):
            out["model"] = check_model(cfg["model"], "stt_cloud")
        if cfg.get("base_url") and cfg["provider"] == "openai_compat":
            out["base_url"] = _base_url(cfg["base_url"])
        return out
    provider = cfg.get("provider")
    if provider not in PROVIDERS:
        raise ValueError("stt_cloud.provider is not supported")
    key = cfg.get("api_key") or ""
    keyless = provider == "openai_compat"
    if not isinstance(key, str) or any(c in key for c in "\r\n") or len(key.strip()) > 400 \
            or (not keyless and len(key.strip()) < 8):
        raise ValueError("stt_cloud.api_key is missing")
    lang = langs.code(cfg.get("lang", "tr"), "stt_cloud.lang")
    region = cfg.get("region") or "westeurope"
    if provider == "azure" and not (isinstance(region, str) and _REGION.match(region)):
        raise ValueError("stt_cloud.region is not valid")
    out = {"provider": provider, "api_key": key.strip(), "lang": lang, "region": region,
           "locale": langs.locale(lang, cfg.get("locale")), "lang_name": langs.name(lang, cfg.get("lang_name"))}
    if cfg.get("model") is not None:
        out["model"] = check_model(cfg["model"], "stt_cloud")
    if provider == "openai_compat":
        out["base_url"] = _base_url(cfg.get("base_url"))
        out["model"] = out.get("model") or "whisper-1"
    return out


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise urllib.error.HTTPError(req.full_url, code, msg, headers, fp)


_OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect)


def _request(url, headers, body, allow_private=ALLOW_PRIVATE_URLS):
    try:
        assert_public_url(url, resolve=True, allow_private=allow_private, allow_loopback=allow_private)
        req = urllib.request.Request(url, data=body, headers=dict(headers, **{"User-Agent": "aipbx-ai"}), method="POST")
        with _OPENER.open(req, timeout=TIMEOUT) as resp:
            return json.loads(resp.read(2 * 1024 * 1024).decode("utf-8"))
    except urllib.error.HTTPError as e:
        e.close()
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
    from .secret_refs import resolve
    cfg = resolve(cfg)
    p, key, lang = cfg["provider"], cfg["api_key"], cfg["lang"]
    if p in ("openai", "groq", "openai_compat"):
        if p == "openai":
            url = "https://api.openai.com/v1/audio/transcriptions"
            model = cfg.get("model") or "gpt-4o-mini-transcribe"
        elif p == "groq":
            url = "https://api.groq.com/openai/v1/audio/transcriptions"
            model = cfg.get("model") or "whisper-large-v3-turbo"
        else:
            url = f"{cfg.get('base_url', '').rstrip('/')}/audio/transcriptions"
            model = cfg.get("model") or "whisper-1"
        body, ctype = _multipart({"model": model, "language": lang, "response_format": "json"}, wav)
        headers = {"Content-Type": ctype}
        if key:
            headers["Authorization"] = "Bearer " + key
        if request is _request:
            d = _request(url, headers, body, allow_private=(ALLOW_PRIVATE_URLS or p == "openai_compat"))
        else:
            d = request(url, headers, body)
        return str(d.get("text") or "").strip()
    if p == "deepgram":
        from .deepgram_stream import keyterms_for
        model = cfg.get("model") or "nova-3"
        query = urllib.parse.urlencode({"model": model, "smart_format": "true", "language": lang,
                                        "keyterm": keyterms_for(model, cfg.get("keyterms") or [])}, doseq=True)
        d = request(f"https://api.deepgram.com/v1/listen?{query}",
                    {"Authorization": "Token " + key, "Content-Type": "audio/wav"}, wav)
        try:
            return str(d["results"]["channels"][0]["alternatives"][0]["transcript"] or "").strip()
        except (KeyError, IndexError, TypeError):
            return ""
    if p == "azure":
        d = request(f"https://{cfg['region']}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1"
                    f"?format=simple&language={cfg.get('locale') or langs.locale(lang)}",
                    {"Ocp-Apim-Subscription-Key": key, "Content-Type": "audio/wav; codecs=audio/pcm; samplerate=16000"}, wav)
        return str(d.get("DisplayText") or "").strip()
    if p == "elevenlabs":
        body, ctype = _multipart({"model_id": cfg.get("model") or "scribe_v1", "language_code": lang}, wav)
        d = request("https://api.elevenlabs.io/v1/speech-to-text", {"xi-api-key": key, "Content-Type": ctype}, body)
        return str(d.get("text") or "").strip()
    if p == "google_ai":
        prompt = (f"Transcribe this telephone audio word for word in {cfg.get('lang_name') or langs.name(lang)}. "
                  "Output only the transcript, nothing else. If nothing is said, output nothing.")
        req = {"contents": [{"parts": [{"text": prompt},
                                       {"inline_data": {"mime_type": "audio/wav", "data": base64.b64encode(wav).decode()}}]}],
               "generationConfig": {"temperature": 0}}
        d = request(ENDPOINTS[p].format(model=cfg.get("model") or DEFAULT_MODELS[p]),
                    {"x-goog-api-key": key, "Content-Type": "application/json"}, json.dumps(req).encode())
        try:
            return str(d["candidates"][0]["content"]["parts"][0]["text"] or "").strip()
        except (KeyError, IndexError, TypeError):
            return ""
    raise CloudSttError("unsupported provider")
