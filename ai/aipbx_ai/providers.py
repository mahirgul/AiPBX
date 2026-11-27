"""The provider catalogue: what the AI service needs to know about each cloud provider.

providers.json is the one place for it. The portal reads the same file (web/src/services/ai/AiProviderManifest.php),
so the address, the default model and the "own address" rule cannot differ between the two. This module imports
nothing from the service: cloud_llm, cloud_stt, realtime and secret_refs read from it, never the other way round.
"""
import json
import os

_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "providers.json")
with open(_PATH, encoding="utf-8") as _f:
    CATALOGUE = json.load(_f)["providers"]


def _llm(name):
    return CATALOGUE[name].get("llm") or {}


# Language models: provider -> chat address (None: the administrator gives it) and the model used when an account names none.
LLM_ENDPOINTS = {name: _llm(name).get("endpoint") for name, p in CATALOGUE.items() if "llm" in p}
LLM_MODELS = {name: _llm(name)["model"] for name, p in CATALOGUE.items() if "llm" in p and _llm(name).get("model")}
# Providers that take response_format {"type": "json_object"}; for the others the prompt asks for JSON.
JSON_MODE = tuple(name for name, p in CATALOGUE.items() if _llm(name).get("json_mode"))
# Providers whose address the administrator may give, those whose address may be on the local network, those that need no key.
OWN_ADDRESS = tuple(name for name, p in CATALOGUE.items() if p.get("own_address"))
PRIVATE_OK = tuple(name for name, p in CATALOGUE.items() if p.get("private_ok"))
KEYLESS = tuple(name for name, p in CATALOGUE.items() if p.get("keyless"))


def stt_model(name, default=""):
    """The speech-to-text model used when an account names none."""
    return (CATALOGUE.get(name, {}).get("stt") or {}).get("model") or default


def realtime_model(name, default=""):
    """The Realtime model used when an account names none."""
    return (CATALOGUE.get(name, {}).get("realtime") or {}).get("model") or default
