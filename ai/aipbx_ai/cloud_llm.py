"""Understanding a caller with a cloud language model (stdlib only).

A voice-requests or routing-by-speech application may let a cloud model
decide which request the caller meant: its config then has
"understand": "cloud" and "llm_cloud": {"provider", "api_key", "model"},
sent by the portal from AI -> Cloud services.

Why: the small local models choose well between the configured requests,
but they cannot tell that a caller wants something else entirely ("I'd like
to order a pizza" lands on the closest department). A language model reads
the list and may answer "none". Large models are not run on the PBX itself:
most PBX servers cannot carry them, so they are used from the cloud.

The model gets the requests (id, name, keywords, examples) and what the
caller said, and must answer with JSON {"intent": "<id>" | "none",
"confidence": 0..1}. Temperature 0. When the provider does not answer in
time, answers something else, or names an unknown id, ``classify`` raises
CloudLlmError and the caller falls back to the local matching.

The key is stored with the app config ($AIPBX_AI_DATA/apps/<id>.json, mode
0600) like the cloud speech-to-text key, and never returned by the API.
"""
import ipaddress
import json
import re
import socket
import urllib.error
import urllib.parse
import urllib.request

# OpenAI-compatible chat completions, and Google's Gemini API. NVIDIA NIM and
# Qwen (Alibaba Cloud Model Studio) speak the OpenAI API too; "openai_compat"
# is any other such service at an address the administrator gives (DeepSeek,
# Mistral, Together, an Ollama or vLLM server ...).
ENDPOINTS = {
    "openai": "https://api.openai.com/v1/chat/completions",
    "groq": "https://api.groq.com/openai/v1/chat/completions",
    "openrouter": "https://openrouter.ai/api/v1/chat/completions",
    "google_ai": "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
    "nvidia": "https://integrate.api.nvidia.com/v1/chat/completions",
    "qwen": "https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions",
    "openai_compat": None,          # base_url + /chat/completions
}
# Small, fast and cheap models that follow a JSON instruction reliably.
DEFAULT_MODELS = {
    "openai": "gpt-4.1-mini",
    "groq": "llama-3.3-70b-versatile",
    "openrouter": "google/gemini-2.5-flash",
    "google_ai": "gemini-2.5-flash",
    "nvidia": "meta/llama-3.3-70b-instruct",
    "qwen": "qwen-plus",
}
# Providers known to accept response_format {"type": "json_object"}; for the
# others the prompt asks for JSON and the answer is searched for it.
JSON_MODE = ("openai", "groq", "openrouter", "qwen")
# Providers whose address may be given (Qwen: international or mainland China).
OWN_ADDRESS = ("qwen", "openai_compat")
PROVIDERS = tuple(ENDPOINTS)
_BASE_URL = re.compile(r"^https?://[^\s/?#@]+(/[^\s?#]*)?$")
TIMEOUT = 6           # the caller is waiting on the line
_MODEL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$")
ALLOW_PRIVATE_URLS = False


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


_OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect)


def _is_forbidden_ip(ip, allow_private=False, allow_loopback=False):
    if getattr(ip, "ipv4_mapped", None) is not None:
        ip = ip.ipv4_mapped
    if not allow_loopback and ip.is_loopback:
        return True
    if ip.is_link_local or ip.is_multicast or ip.is_reserved or ip.is_unspecified:
        return True
    if not allow_private and (ip.is_private or not ip.is_global):
        return True
    return False


def assert_public_url(url, *, resolve=True, allow_private=False, allow_loopback=False):
    """Validates an http(s) URL and rejects loopback, link-local, or reserved hosts."""
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise ValueError("URL must be an http(s) URL")
    if parts.username is not None or parts.password is not None:
        raise ValueError("URL must not contain credentials")
    host = parts.hostname.strip(".").lower().split("%", 1)[0]
    if not allow_loopback and (not host or host in ("localhost", "localhost.localdomain") or host.endswith((".localhost", ".local", ".internal"))):
        raise ValueError("private or local address is not allowed")
    try:
        ip = ipaddress.ip_address(host)
    except ValueError:
        ip = None
    if ip is not None:
        if _is_forbidden_ip(ip, allow_private=allow_private, allow_loopback=allow_loopback):
            raise ValueError("private or local address is not allowed")
        return url
    if resolve:
        port = parts.port or (443 if parts.scheme == "https" else 80)
        try:
            infos = socket.getaddrinfo(parts.hostname, port, type=socket.SOCK_STREAM)
        except OSError as e:
            raise ValueError("hostname could not be resolved") from e
        if not infos:
            raise ValueError("hostname could not be resolved")
        for _, _, _, _, sockaddr in infos:
            resolved_ip = ipaddress.ip_address(sockaddr[0].split("%", 1)[0])
            if _is_forbidden_ip(resolved_ip, allow_private=allow_private, allow_loopback=allow_loopback):
                raise ValueError("private or local address is not allowed")
    return url


class CloudLlmError(Exception):
    pass


def validate(cfg):
    """Normalised llm_cloud settings, or ValueError."""
    if not isinstance(cfg, dict):
        raise ValueError("llm_cloud must be an object")
    provider = cfg.get("provider")
    if provider not in PROVIDERS:
        raise ValueError("llm_cloud.provider is not supported")
    key = cfg.get("api_key") or ""
    keyless = provider == "openai_compat"          # e.g. an Ollama server on the LAN
    if not isinstance(key, str) or any(c in key for c in "\r\n") or len(key.strip()) > 400 \
            or (not keyless and len(key.strip()) < 8):
        raise ValueError("llm_cloud.api_key is missing")
    model = cfg.get("model") or DEFAULT_MODELS.get(provider)
    if not isinstance(model, str) or not _MODEL.match(model):
        raise ValueError("llm_cloud.model is not valid")
    out = {"provider": provider, "api_key": key.strip(), "model": model}
    base = cfg.get("base_url")
    if provider in OWN_ADDRESS and base:
        if not isinstance(base, str) or len(base) > 200 or not _BASE_URL.match(base.rstrip("/")):
            raise ValueError("llm_cloud.base_url is not valid")
        try:
            assert_public_url(base.rstrip("/"), resolve=True, allow_private=(ALLOW_PRIVATE_URLS or provider == "openai_compat"))
        except ValueError:
            raise ValueError("llm_cloud.base_url is not valid") from None
        out["base_url"] = base.rstrip("/")
    elif provider == "openai_compat":
        raise ValueError("llm_cloud.base_url is missing")
    return out


def prompt(transcript, intents):
    """The instruction: the requests and what the caller said."""
    lines = []
    for intent in intents:
        parts = [f'id "{intent["id"]}"', f'name "{intent.get("name") or intent["id"]}"']
        if intent.get("keywords"):
            parts.append("keywords: " + ", ".join(intent["keywords"]))
        if intent.get("examples"):
            parts.append("examples: " + " | ".join(intent["examples"]))
        lines.append("- " + "; ".join(parts))
    return (
        "You route callers of a telephone line. A speech recogniser wrote down what the caller said; "
        "it may contain recognition errors, so judge by meaning, not by exact words.\n"
        "Requests:\n" + "\n".join(lines) + "\n\n"
        f"The caller said: \"{transcript}\"\n\n"
        "Which request does the caller mean? Answer \"none\" when it is none of them, or when the words "
        "are too unclear to decide. Reply with JSON only: "
        "{\"intent\": \"<id or none>\", \"confidence\": <0 to 1>}"
    )


def _post(url, headers, body, allow_private=ALLOW_PRIVATE_URLS):
    try:
        assert_public_url(url, resolve=True, allow_private=allow_private)
        req = urllib.request.Request(url, data=json.dumps(body).encode("utf-8"),
                                     headers=dict(headers, **{"Content-Type": "application/json", "User-Agent": "aipbx-ai"}),
                                     method="POST")
        with _OPENER.open(req, timeout=TIMEOUT) as resp:
            return json.loads(resp.read(1024 * 1024).decode("utf-8"))
    except urllib.error.HTTPError as e:
        e.close()
        raise CloudLlmError(f"{e.code}") from None
    except (urllib.error.URLError, TimeoutError, ValueError, OSError) as e:
        raise CloudLlmError(type(e).__name__) from None


def _answer_text(cfg, text, post):
    provider, model = cfg["provider"], cfg["model"]
    if provider == "google_ai":
        d = post(ENDPOINTS[provider].format(model=model), {"x-goog-api-key": cfg["api_key"]},
                 {"contents": [{"parts": [{"text": text}]}],
                  "generationConfig": {"temperature": 0, "responseMimeType": "application/json"}})
        try:
            return d["candidates"][0]["content"]["parts"][0]["text"]
        except (KeyError, IndexError, TypeError):
            raise CloudLlmError("no answer") from None
    url = cfg["base_url"] + "/chat/completions" if cfg.get("base_url") else ENDPOINTS[provider]
    body = {"model": model, "temperature": 0, "messages": [{"role": "user", "content": text}]}
    if provider in JSON_MODE:
        body["response_format"] = {"type": "json_object"}
    req_headers = {"Authorization": "Bearer " + cfg["api_key"]} if cfg["api_key"] else {}
    if post is _post:
        d = _post(url, req_headers, body, allow_private=(ALLOW_PRIVATE_URLS or provider == "openai_compat"))
    else:
        d = post(url, req_headers, body)
    try:
        return d["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError):
        raise CloudLlmError("no answer") from None


def classify(cfg, transcript, intents, post=_post):
    """{"intent": id or None, "confidence": 0..1}; CloudLlmError when unusable."""
    raw = _answer_text(cfg, prompt(transcript, intents), post)
    match = re.search(r"\{.*\}", raw or "", re.S)
    try:
        data = json.loads(match.group(0)) if match else None
    except ValueError:
        data = None
    if not isinstance(data, dict):
        raise CloudLlmError("not JSON")
    intent = data.get("intent")
    try:
        confidence = max(0.0, min(1.0, float(data.get("confidence", 1.0))))
    except (TypeError, ValueError):
        confidence = 1.0
    if intent in (None, "none", ""):
        return {"intent": None, "confidence": confidence}
    if intent not in {i["id"] for i in intents}:
        raise CloudLlmError("unknown intent")
    return {"intent": intent, "confidence": round(confidence, 3)}
