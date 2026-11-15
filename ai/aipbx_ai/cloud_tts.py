"""Cloud text to speech for live AI applications (stdlib + optional numpy).

Talks directly to the provider over HTTPS with the API key saved under
AI -> Cloud services, returning 8 kHz 16-bit mono little-endian signed PCM
ready for AudioSocket playback.
"""
import base64
import io
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import wave
import xml.sax.saxutils
from array import array

from .cloud_llm import assert_public_url

PROVIDERS = ("openai", "azure", "elevenlabs", "google")
TIMEOUT = 10
MAX_RESPONSE_BYTES = 16 * 1024 * 1024
ALLOW_PRIVATE_URLS = False

_REGION = re.compile(r"^[a-z0-9-]{2,32}$")
_MODEL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$")
_VOICE = re.compile(r"^[^\s\r\n]{1,120}$")
_BASE_URL = re.compile(r"^https?://[^\s/?#@]+(/[^\s?#]*)?$")

_LANG_CODES = {"tr": "tr-TR", "de": "de-DE", "en": "en-US"}
_AZURE_VOICES = {
    "tr": "tr-TR-EmelNeural",
    "de": "de-DE-KatjaNeural",
    "en": "en-US-JennyNeural",
}
_ELEVENLABS_DEFAULT_VOICE = "21m00Tcm4TlvDq8ikWAM"


class CloudTtsError(Exception):
    pass


def validate(cfg):
    """Normalised tts_cloud settings, or ValueError."""
    if not isinstance(cfg, dict):
        raise ValueError("tts_cloud must be an object")
    provider = cfg.get("provider")
    if provider not in PROVIDERS:
        raise ValueError("tts_cloud.provider is not supported")
    raw_key = cfg.get("api_key")
    if raw_key is None:
        raw_key = cfg.get("key")
    if not isinstance(raw_key, str) or any(c in raw_key for c in "\r\n") or not raw_key.strip() or len(raw_key.strip()) > 400:
        raise ValueError("tts_cloud.api_key is missing")
    out = {"provider": provider, "api_key": raw_key.strip()}

    lang = cfg.get("lang")
    if lang is not None and lang != "":
        if not isinstance(lang, str) or lang.strip().lower()[:2] not in _LANG_CODES:
            raise ValueError("tts_cloud.lang must be tr, de or en")
        out["lang"] = lang.strip().lower()[:2]

    region = cfg.get("region")
    if provider == "azure":
        if not isinstance(region, str) or not _REGION.match(region.strip()):
            raise ValueError("tts_cloud.region is invalid")
        out["region"] = region.strip()
    elif isinstance(region, str) and region.strip():
        if not _REGION.match(region.strip()):
            raise ValueError("tts_cloud.region is invalid")
        out["region"] = region.strip()

    model = cfg.get("model")
    if model is not None and str(model).strip() != "":
        if not isinstance(model, str) or not _MODEL.match(model.strip()):
            raise ValueError("tts_cloud.model is invalid")
        out["model"] = model.strip()

    voice = cfg.get("voice")
    if voice is not None and str(voice).strip() != "":
        if not isinstance(voice, str) or not _VOICE.match(voice.strip()):
            raise ValueError("tts_cloud.voice is invalid")
        out["voice"] = voice.strip()

    endpoint = cfg.get("endpoint")
    if endpoint is not None and str(endpoint).strip() != "":
        if not isinstance(endpoint, str) or len(endpoint.strip()) > 200 or not _BASE_URL.match(endpoint.strip().rstrip("/")):
            raise ValueError("tts_cloud.endpoint is invalid")
        clean_ep = endpoint.strip().rstrip("/")
        try:
            assert_public_url(clean_ep, resolve=False, allow_private=ALLOW_PRIVATE_URLS, allow_loopback=ALLOW_PRIVATE_URLS)
        except ValueError:
            raise ValueError("tts_cloud.endpoint is invalid") from None
        out["endpoint"] = clean_ep

    return out


def _http_post(url, headers, body, allow_private=ALLOW_PRIVATE_URLS):
    assert_public_url(url, resolve=True, allow_private=allow_private, allow_loopback=allow_private)
    req = urllib.request.Request(url, data=body, headers=dict(headers), method="POST")
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            return resp.read(MAX_RESPONSE_BYTES)
    except urllib.error.HTTPError as e:
        e.close()
        raise CloudTtsError(f"HTTP {e.code}") from None
    except (urllib.error.URLError, TimeoutError, ValueError, OSError) as e:
        raise CloudTtsError(type(e).__name__) from None


def _resample_8k(pcm: bytes, from_rate: int) -> bytes:
    """Converts 16-bit LE mono PCM from `from_rate` to 8000 Hz 16-bit LE mono PCM."""
    if len(pcm) % 2:
        pcm = pcm[:-1]
    if from_rate == 8000 or not pcm:
        return pcm
    from .engines import piper

    if piper.np is not None:
        np = piper.np
        x = np.frombuffer(pcm, dtype="<i2").astype(np.float32) / 32768.0
        y = piper.resample(x, from_rate, 8000)
        return (np.clip(y, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes()

    samples = array("h")
    samples.frombytes(pcm)
    if sys.byteorder != "little":
        samples.byteswap()
    n = len(samples)
    if from_rate > 8000 and from_rate % 8000 == 0:
        step = from_rate // 8000
        out = array("h", (sum(samples[i:i + step]) // len(samples[i:i + step]) for i in range(0, n, step)))
    else:
        n_out = max(1, (n * 8000) // from_rate)
        out = array("h", (samples[min(n - 1, (i * from_rate) // 8000)] for i in range(n_out)))
    if sys.byteorder != "little":
        out.byteswap()
    return out.tobytes()


def _decode_google_audio(raw_json: bytes) -> bytes:
    try:
        data = json.loads(raw_json.decode("utf-8"))
        b64 = data["audioContent"]
        raw = base64.b64decode(b64)
    except (UnicodeDecodeError, json.JSONDecodeError, KeyError, TypeError, ValueError):
        raise CloudTtsError("invalid response") from None

    if raw.startswith(b"RIFF"):
        try:
            with wave.open(io.BytesIO(raw), "rb") as w:
                rate = w.getframerate()
                channels = w.getnchannels()
                width = w.getsampwidth()
                frames = w.readframes(w.getnframes())
            if width != 2:
                raise CloudTtsError("unsupported WAV width")
            if channels > 1:
                samples = array("h")
                samples.frombytes(frames[:len(frames) - (len(frames) % (2 * channels))])
                if sys.byteorder != "little":
                    samples.byteswap()
                mono = array("h", (samples[i] for i in range(0, len(samples), channels)))
                if sys.byteorder != "little":
                    mono.byteswap()
                frames = mono.tobytes()
            return _resample_8k(frames, rate)
        except wave.Error:
            raise CloudTtsError("invalid WAV") from None
    return _resample_8k(raw, 8000)


def synthesize(cfg: dict, text: str, lang: str = "tr", speed: float = 1.0, request=None) -> bytes:
    """Synthesises `text` using a cloud TTS provider; returns 8 kHz 16-bit LE mono PCM."""
    if not text or not str(text).strip():
        return b""
    provider = cfg["provider"]
    key = (cfg.get("api_key") or cfg.get("key") or "").strip()
    lang_code = (lang or cfg.get("lang") or "tr").strip().lower()[:2]
    if lang_code not in _LANG_CODES:
        lang_code = "tr"
    speed_val = float(speed)
    post = request or _http_post

    if provider == "openai":
        body = {
            "model": cfg.get("model") or "tts-1",
            "voice": cfg.get("voice") or "alloy",
            "input": text,
            "response_format": "pcm",
        }
        if abs(speed_val - 1.0) > 1e-4:
            body["speed"] = max(0.25, min(4.0, round(speed_val, 2)))
        headers = {
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "User-Agent": "aipbx-ai",
        }
        raw = post("https://api.openai.com/v1/audio/speech", headers, json.dumps(body).encode("utf-8"))
        return _resample_8k(raw, 24000)

    if provider == "azure":
        region = cfg["region"]
        xml_lang = _LANG_CODES[lang_code]
        voice = cfg.get("voice") or _AZURE_VOICES[lang_code]
        escaped_text = xml.sax.saxutils.escape(text)
        escaped_voice = xml.sax.saxutils.escape(voice, {"'": "&apos;", '"': "&quot;"})
        inner = escaped_text
        if abs(speed_val - 1.0) > 1e-4:
            pct = max(-50, min(100, int(round((speed_val - 1.0) * 100))))
            inner = f"<prosody rate='{pct:+d}%'>{inner}</prosody>"
        ssml = f"<speak version='1.0' xml:lang='{xml_lang}'><voice name='{escaped_voice}'>{inner}</voice></speak>"
        headers = {
            "Ocp-Apim-Subscription-Key": key,
            "Content-Type": "application/ssml+xml",
            "X-Microsoft-OutputFormat": "raw-8khz-16bit-mono-pcm",
            "User-Agent": "aipbx-ai",
        }
        raw = post(f"https://{region}.tts.speech.microsoft.com/cognitiveservices/v1", headers, ssml.encode("utf-8"))
        return _resample_8k(raw, 8000)

    if provider == "elevenlabs":
        base = (cfg.get("endpoint") or "https://api.elevenlabs.io").rstrip("/")
        voice = cfg.get("voice") or _ELEVENLABS_DEFAULT_VOICE
        url = f"{base}/v1/text-to-speech/{urllib.parse.quote(voice, safe='')}?output_format=pcm_16000"
        body = {
            "text": text,
            "model_id": cfg.get("model") or "eleven_multilingual_v2",
        }
        headers = {
            "xi-api-key": key,
            "Content-Type": "application/json",
            "User-Agent": "aipbx-ai",
        }
        raw = post(url, headers, json.dumps(body).encode("utf-8"))
        return _resample_8k(raw, 16000)

    if provider == "google":
        voice = cfg.get("voice") or ""
        parts = voice.split("-") if voice else []
        g_lang = "-".join(parts[:2]) if len(parts) >= 2 else _LANG_CODES[lang_code]
        voice_obj = {"languageCode": g_lang}
        if voice:
            voice_obj["name"] = voice
        audio_cfg = {"audioEncoding": "LINEAR16", "sampleRateHertz": 8000}
        if abs(speed_val - 1.0) > 1e-4:
            audio_cfg["speakingRate"] = max(0.25, min(4.0, round(speed_val, 2)))
        body = {
            "input": {"text": text},
            "voice": voice_obj,
            "audioConfig": audio_cfg,
        }
        url = f"https://texttospeech.googleapis.com/v1/text:synthesize?key={urllib.parse.quote(key, safe='')}"
        headers = {"Content-Type": "application/json", "User-Agent": "aipbx-ai"}
        raw = post(url, headers, json.dumps(body).encode("utf-8"))
        return _decode_google_audio(raw)

    raise ValueError(f"unsupported provider: {provider}")
