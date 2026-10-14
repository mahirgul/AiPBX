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
import json
import re
import urllib.request

# OpenAI-compatible chat completions, and Google's Gemini API.
ENDPOINTS = {
    "openai": "https://api.openai.com/v1/chat/completions",
    "groq": "https://api.groq.com/openai/v1/chat/completions",
    "openrouter": "https://openrouter.ai/api/v1/chat/completions",
    "google_ai": "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
}
# Small, fast and cheap models that follow a JSON instruction reliably.
DEFAULT_MODELS = {
    "openai": "gpt-4.1-mini",
    "groq": "llama-3.3-70b-versatile",
    "openrouter": "google/gemini-2.5-flash",
    "google_ai": "gemini-2.5-flash",
}
PROVIDERS = tuple(ENDPOINTS)
TIMEOUT = 6           # the caller is waiting on the line
_MODEL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$")


class CloudLlmError(Exception):
    pass


def validate(cfg):
    """Normalised llm_cloud settings, or ValueError."""
    if not isinstance(cfg, dict):
        raise ValueError("llm_cloud must be an object")
    provider = cfg.get("provider")
    if provider not in PROVIDERS:
        raise ValueError("llm_cloud.provider is not supported")
    key = cfg.get("api_key")
    if not isinstance(key, str) or not 8 <= len(key.strip()) <= 400 or any(c in key for c in "\r\n"):
        raise ValueError("llm_cloud.api_key is missing")
    model = cfg.get("model") or DEFAULT_MODELS[provider]
    if not isinstance(model, str) or not _MODEL.match(model):
        raise ValueError("llm_cloud.model is not valid")
    return {"provider": provider, "api_key": key.strip(), "model": model}


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


def _post(url, headers, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode("utf-8"),
                                 headers=dict(headers, **{"Content-Type": "application/json", "User-Agent": "aipbx-ai"}),
                                 method="POST")
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            return json.loads(resp.read(1024 * 1024).decode("utf-8"))
    except urllib.error.HTTPError as e:
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
    d = post(ENDPOINTS[provider], {"Authorization": "Bearer " + cfg["api_key"]},
             {"model": model, "temperature": 0, "response_format": {"type": "json_object"},
              "messages": [{"role": "user", "content": text}]})
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
