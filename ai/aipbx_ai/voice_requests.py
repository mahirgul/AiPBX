"""AI application type "voice_requests": the caller says what they want.

Example: a hotel guest calls from the room phone and says "could I get two
more towels?"; the service recognises the request, confirms it by voice and
the dialplan continues (the portal e-mails the staff, then goes on to the
application's destination).

This module holds the parts that do not touch the socket:

* ``validate_config`` and ``AppStore``: the application configs the portal
  pushes (PUT /v1/apps/<id>), kept in $AIPBX_AI_DATA/apps/<id>.json so they
  survive restarts, plus a cache of their synthesised prompts (8 kHz PCM,
  made on first use, dropped when the config changes).
* ``Listener``: an energy voice-activity detector on 20 ms frames that cuts
  the caller's utterance out of the inbound audio.
* ``ResultStore``: the result of each call in memory and in
  $AIPBX_AI_DATA/calls/<uuid>.json, the caller's last utterance in
  $AIPBX_AI_DATA/calls/<uuid>.wav; both are deleted after 24 hours.

The call itself (greeting -> listen -> retry -> reply -> terminate) runs in
calls.AudioSocketServer; matching is intents.match.
"""
import hashlib
import json
import logging
import math
import os
import random
import re
import sys
import threading
import time
import uuid as uuid_mod
from array import array
from collections import OrderedDict
from pathlib import Path

from . import cloud_llm, cloud_stt, cloud_tts, conversation, langs
from .models import READY, UnknownModel

log = logging.getLogger("aipbx_ai.voice_requests")

APP_TYPE = "voice_requests"
APP_MAX = 1000000
MAX_INTENTS = 30
MAX_WORDS = 30               # keywords, and examples, per intent
MAX_TEXT = 1000
LISTEN_MIN, LISTEN_MAX, LISTEN_DEFAULT = 2, 15, 7
RETRIES_MAX, RETRIES_DEFAULT = 2, 1
SILENCE_MIN, SILENCE_MAX = 3, 10   # silence_seconds: nothing said by then is silence
THRESHOLD_DEFAULT = 0.5
REALTIME_VAD_THRESHOLD, REALTIME_VAD_SILENCE_MS = 0.5, 500   # the provider's turn detection, as before
SPEED_MIN, SPEED_MAX = 0.5, 2.0

_INTENT_ID = re.compile(r"^[a-z0-9_-]{1,40}$")
_MODEL_ID = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
_CONTROL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")

SAMPLE_RATE = 8000
FRAME_BYTES = 320            # 20 ms of 8 kHz 16-bit mono
FRAME_SECONDS = 0.02

# Voice activity detection (see Listener).
CALIBRATION_FRAMES = 15      # 300 ms: the first noise floor
START_FRAMES = 3             # 60 ms above the speech level start an utterance
BARGE_FRAMES = 12            # 240 ms: a caller talking over a prompt; a cough or a "mm-hm" does not stop it
END_FRAMES = 40              # 800 ms below it end the utterance
# With the optional Smart Turn model: first look after 400 ms of silence, again every 400 ms, and end the
# turn when it says "complete" or after 1.6 s anyway; Silero VAD: a frame is a voice at this probability.
TURN_CHECK_FRAMES, TURN_RECHECK_FRAMES, TURN_CAP_FRAMES = 20, 20, 80
TURN_THRESHOLD = SILERO_THRESHOLD = 0.5
NO_SPEECH_SECONDS = 4.0      # nothing said by then: silence
PRE_ROLL_FRAMES = 15         # 300 ms kept before the detected start
TAIL_FRAMES = 15             # 300 ms kept after the last loud frame
SPEECH_FACTOR = 3.0          # speech: RMS at least 3x (about +10 dB) the floor ...
MIN_SPEECH_RMS = 250         # ... and at least this (about -42 dBFS)
# line_profile: (speech factor over the floor, lowest speech RMS). "noisy" ignores more line noise,
# "faint" hears quieter callers; barge-in keeps the normal level on a faint profile (noise would stop prompts).
PROFILES = {"normal": (SPEECH_FACTOR, MIN_SPEECH_RMS), "noisy": (4.5, 400), "faint": (2.2, 150)}
FAINT_FACTOR, MIN_FAINT_RMS = 1.8, 60.0   # below speech level but clearly above the floor ...
FAINT_FRAMES = 10            # ... for 200 ms in all: a voice too quiet to use
MIN_FLOOR, MAX_FLOOR = 20.0, 800.0   # RMS; 800 is about -32 dBFS, louder line noise is capped
FLOOR_FALL = 0.3            # the floor falls quickly to quieter frames ...
FLOOR_ADAPT = 0.05           # ... and rises slowly to louder ones (before speech)

RESULT_KEEP_SECONDS = 24 * 3600
RESULT_MEMORY = 2000
PHRASE_CACHE_MEMORY = 512
SILENCE_TRIM_RMS = 120.0
_GREETING_VAR_RE = re.compile(r"\{caller_name\}|\$name\b|\{caller_number\}|\{did\}")


class ConfigError(ValueError):
    """The pushed config is invalid; the message goes back to the portal."""


# ---- config -------------------------------------------------------------

def _text(data, name, required=False, where=""):
    value = data.get(name, "")
    if value is None:
        value = ""
    if not isinstance(value, str):
        raise ConfigError(f"{where}{name} must be a string")
    value = _CONTROL.sub(" ", value).strip()
    if len(value) > MAX_TEXT:
        raise ConfigError(f"{where}{name} is longer than {MAX_TEXT} characters")
    if required and not value:
        raise ConfigError(f"{where}{name} is empty")
    return value


def _number(data, name, lo, hi, default, integer=False):
    if name not in data or data[name] is None:
        return default
    value = data[name]
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
        raise ConfigError(f"{name} must be a number")
    if integer and value != int(value):
        raise ConfigError(f"{name} must be a whole number")
    if not lo <= value <= hi:
        raise ConfigError(f"{name} must be from {lo} to {hi}")
    return int(value) if integer else float(value)


def _words(intent, name, where):
    value = intent.get(name, [])
    if value is None:
        value = []
    if not isinstance(value, list):
        raise ConfigError(f"{where}{name} must be a list")
    if len(value) > MAX_WORDS:
        raise ConfigError(f"{where}at most {MAX_WORDS} {name}")
    out = []
    for item in value:
        if not isinstance(item, str):
            raise ConfigError(f"{where}{name} must be strings")
        item = _CONTROL.sub(" ", item).strip()
        if len(item) > MAX_TEXT:
            raise ConfigError(f"{where}one of the {name} is longer than {MAX_TEXT} characters")
        if item and item not in out:
            out.append(item)
    return out


def _model(data, name, kind, manager):
    value = data.get(name)
    # Speech to text is a cloud provider ("cloud:<provider>"); text to speech may be one too.
    if kind == "stt":
        if not isinstance(value, str) or not value.startswith("cloud:"):
            raise ConfigError(f"{name}: speech to text uses a cloud provider (cloud:<provider>)")
        if value[6:] not in cloud_stt.PROVIDERS:
            raise ConfigError(f"{name}: unknown cloud provider")
        return value
    if kind == "tts" and isinstance(value, str) and value.startswith("cloud:"):
        if value[6:] not in cloud_tts.PROVIDERS:
            raise ConfigError(f"{name}: unknown cloud provider")
        return value
    if not isinstance(value, str) or not _MODEL_ID.match(value):
        raise ConfigError(f"{name} is not a valid model id")
    if manager is not None:
        try:
            info = manager.describe(value)
        except UnknownModel:
            raise ConfigError(f"{name}: unknown model {value}") from None
        if info["kind"] != kind:
            raise ConfigError(f"{name}: {value} is not a text-to-speech model")
    return value


UNDERSTAND = ("keywords", "cloud")
MODES = ("realtime", "cascade")


def validate_config(data, manager=None):
    """The normalised config, or ConfigError. Unknown fields are ignored."""
    if not isinstance(data, dict):
        raise ConfigError("the config must be a JSON object")
    if data.get("type") != APP_TYPE:
        raise ConfigError(f"type must be {APP_TYPE}")
    builtin = data.get("builtin_agent") is True
    mode = data.get("mode") if builtin else None
    if builtin and mode not in MODES:
        raise ConfigError("a built-in voice agent needs a mode: realtime or cascade")
    realtime_only = mode == "realtime"      # the provider hears and speaks: no speech or language models here
    config = {
        "type": APP_TYPE,
        "tts_model": "" if realtime_only else _model(data, "tts_model", "tts", manager),
        "stt_model": "" if realtime_only else _model(data, "stt_model", "stt", manager),
        "speed": _number(data, "speed", SPEED_MIN, SPEED_MAX, 1.0),
        "greeting": _text(data, "greeting", required=True),
        "retry": _text(data, "retry"),
        "not_understood": _text(data, "not_understood"),
        # What the agent says when the caller says goodbye (end_on_goodbye), and whether a goodbye ends the call.
        "goodbye": _text(data, "goodbye"),
        "end_on_goodbye": data.get("end_on_goodbye") is True,
        # Optional detectors, installed from the Local AI page: "silero" tells a voice from noise, "smart" asks
        # the Smart Turn model whether the caller is done. Without the model the standard detection runs.
        "speech_detection": "silero" if data.get("speech_detection") == "silero" else "standard",
        "end_of_turn": "smart" if data.get("end_of_turn") == "smart" else "fixed",
        "listen_seconds": _number(data, "listen_seconds", LISTEN_MIN, LISTEN_MAX, LISTEN_DEFAULT, integer=True),
        "retries": _number(data, "retries", 0, RETRIES_MAX, RETRIES_DEFAULT, integer=True),
        "silence_seconds": _number(data, "silence_seconds", SILENCE_MIN, SILENCE_MAX, int(NO_SPEECH_SECONDS), integer=True),
        "line_profile": data.get("line_profile") if data.get("line_profile") in PROFILES else "normal",
        # How easily the caller's voice stops a prompt (barge-in): noisy = harder, faint = easier.
        "barge_profile": data.get("barge_profile") if data.get("barge_profile") in PROFILES else None,
        "threshold": _number(data, "threshold", 0.0, 1.0, THRESHOLD_DEFAULT),
        # The caller may talk over the greeting and the "say it again" prompt:
        # the prompt stops and what they say is the request. Off by default, as
        # a line with echo would stop the prompt by itself.
        "barge_in": data.get("barge_in") is True,
        # How the request is recognised: "keywords" = keywords and letters only;
        # "cloud" = a cloud language model (llm_cloud), falling back to keywords when it does not answer.
        "understand": "cloud" if data.get("understand") == "cloud" and not realtime_only else "keywords",
    }
    if builtin:
        config["builtin_agent"] = True
        config["mode"] = mode
        if data.get("conversation") is not True:
            raise ConfigError("a built-in voice agent needs conversation")
        if mode == "realtime" and not isinstance(data.get("realtime_cloud"), dict):
            raise ConfigError("realtime mode needs realtime_cloud")
        if mode == "cascade" and data.get("understand") != "cloud":
            raise ConfigError("cascade mode needs a cloud language model (understand: cloud)")
        config["automatic_announcements"] = data.get("automatic_announcements") is True
        config["announcement_tone"] = _text(data, "announcement_tone")
        config["greetings_by_lang"] = _greetings_by_lang(data.get("greetings_by_lang"))
        own = _greeting_items(data.get("greetings", []), "greetings")
        config["greeting_orders"] = [o for o, _ in own]
        for key, limit in (("greetings", 250), ("waiting_phrases", 120)):
            phrases = [t for _, t in own] if key == "greetings" else data.get(key, [])
            if not isinstance(phrases, list) or len(phrases) > 5:
                raise ConfigError(f"{key}: at most five phrases")
            config[key] = [_text({"phrase": phrase}, "phrase", required=True) for phrase in phrases]
            if any(len(phrase) > limit for phrase in config[key]):
                raise ConfigError(f"{key}: at most {limit} characters per phrase")
    config["languages"] = _languages(data.get("languages"))
    if config["understand"] == "cloud":
        try:
            config["llm_cloud"] = cloud_llm.validate(data.get("llm_cloud"))
        except ValueError as e:
            raise ConfigError(str(e)) from None
    if config["stt_model"]:
        try:
            config["stt_cloud"] = cloud_stt.validate(data.get("stt_cloud"))
        except ValueError as e:
            raise ConfigError(str(e)) from None
        if config["stt_cloud"]["provider"] != config["stt_model"][6:]:
            raise ConfigError("stt_cloud.provider does not match stt_model")
    if config["tts_model"].startswith("cloud:"):
        try:
            config["tts_cloud"] = cloud_tts.validate(data.get("tts_cloud"))
        except ValueError as e:
            raise ConfigError(str(e)) from None
        if config["tts_cloud"]["provider"] != config["tts_model"][6:]:
            raise ConfigError("tts_cloud.provider does not match tts_model")
    if data.get("language") not in (None, ""):
        try:
            config["language"] = langs.code(data["language"], "language")
        except ValueError as e:
            raise ConfigError(str(e)) from None
        config["language_name"] = langs.name(config["language"], data.get("language_name"))
        config["language_locale"] = langs.locale(config["language"], data.get("language_locale"))
    fb_val = data.get("tts_fallback")
    if not realtime_only and isinstance(fb_val, str) and fb_val.strip():
        if fb_val.strip().startswith("cloud:"):
            raise ConfigError("tts_fallback must be a local model")
        config["tts_fallback"] = _model({"tts_fallback": fb_val.strip()}, "tts_fallback", "tts", manager)
    intents = data.get("intents")
    if not isinstance(intents, list) or (not intents and not builtin):
        raise ConfigError("intents must be a non-empty list")
    if len(intents) > MAX_INTENTS:
        raise ConfigError(f"at most {MAX_INTENTS} intents")
    seen, out = set(), []
    for n, intent in enumerate(intents, 1):
        where = f"intent {n}: "
        if not isinstance(intent, dict):
            raise ConfigError(f"{where}must be an object")
        intent_id = intent.get("id")
        if not isinstance(intent_id, str) or not _INTENT_ID.match(intent_id):
            raise ConfigError(f"{where}id must be 1-40 characters a-z 0-9 _ -")
        if intent_id in ("none", "_end"):
            raise ConfigError(f"{where}id '{intent_id}' is reserved")
        if intent_id in seen:
            raise ConfigError(f"{where}id {intent_id} is used twice")
        seen.add(intent_id)
        where = f"intent {intent_id}: "
        item = {"id": intent_id, "name": _text(intent, "name", where=where),
                "keywords": _words(intent, "keywords", where), "examples": _words(intent, "examples", where),
                "reply": _text(intent, "reply", where=where)}
        if builtin:
            route_id = intent.get("route_id")
            if not isinstance(route_id, str) or not re.fullmatch(r"[a-f0-9]{16}", route_id):
                raise ConfigError(f"{where}invalid dialplan route")
            item["route_id"] = route_id
        if not builtin and not item["keywords"] and not item["examples"]:
            raise ConfigError(f"{where}needs at least one keyword or example")
        out.append(item)
    config["intents"] = out
    rc = data.get("realtime_cloud")
    has_realtime = isinstance(rc, dict) and bool(rc.get("api_key") or rc.get("ws_url") or rc.get("base_url")) \
        and (not builtin or mode == "realtime")
    if realtime_only and not has_realtime:
        raise ConfigError("realtime mode needs realtime_cloud with an api_key, ws_url or base_url")
    # Conversation: several turns with the language model (conversation.py).
    if data.get("conversation") is True:
        if not config.get("llm_cloud") and not has_realtime:
            raise ConfigError("a conversation needs a language model")
        try:
            config.update(conversation.validate(
                data, lambda url: cloud_llm.assert_public_url(url, resolve=False)))
        except conversation.ConversationError as e:
            raise ConfigError(str(e)) from None
        config["conversation"] = True
    if has_realtime:
        config["realtime_cloud"] = _realtime(rc, config)
    pbx_tools = data.get("pbx_tools")
    if isinstance(pbx_tools, list) and pbx_tools:
        config["pbx_tools"] = [
            t for t in pbx_tools
            if isinstance(t, dict) and isinstance(t.get("name"), str) and t["name"]
        ]
        if isinstance(data.get("pbx_tools_key"), str) and data["pbx_tools_key"].strip():
            config["pbx_tools_key"] = data["pbx_tools_key"].strip()
    return config


def _greeting_items(phrases, label):
    """[(order, text)]: each greeting is a text (order 0) or {"order": 0..99, "text": ...}; at most five,
    each at most 250 characters."""
    if not isinstance(phrases, list) or len(phrases) > 5:
        raise ConfigError(f"{label}: at most five greetings")
    out = []
    for item in phrases:
        order = 0
        if isinstance(item, dict):
            order = item.get("order", 0)
            if type(order) is not int or not 0 <= order <= 99:
                raise ConfigError(f"{label}: order must be a whole number from 0 to 99")
            item = item.get("text")
        text = _text({"phrase": item}, "phrase", required=True)
        if len(text) > 250:
            raise ConfigError(f"{label}: at most 250 characters per greeting")
        out.append((order, text))
    return out


def _greetings_by_lang(value):
    """{"tr": [{"order": 0, "text": "Merhaba {caller_name}"}, ...], ...}: up to five greetings per language."""
    if value in (None, ""):
        return {}
    if not isinstance(value, dict):
        raise ConfigError("greetings_by_lang must be an object")
    out = {}
    for code, phrases in value.items():
        try:
            code = langs.code(code, "greetings_by_lang language")
        except ValueError as e:
            raise ConfigError(str(e)) from None
        items = _greeting_items(phrases, f"greetings_by_lang {code}")
        if items:
            out[code] = [{"order": o, "text": t} for o, t in items]
    return out


def _languages(value):
    """{"tr": {"name": "Turkish", "locale": "tr-TR"}, ...}: the AI languages of the PBX."""
    if not isinstance(value, dict):
        return {}
    out = {}
    for code, info in value.items():
        try:
            code = langs.code(code, "languages")
        except ValueError as e:
            raise ConfigError(str(e)) from None
        info = info if isinstance(info, dict) else {}
        out[code] = {"name": langs.name(code, info.get("name")), "locale": langs.locale(code, info.get("locale"))}
    return out


def call_language(config, channel_language):
    """(code, name, locale) of a call: the channel's language when the PBX has it among its AI languages,
    else the application's own."""
    code = langs.channel(channel_language)
    known = config.get("languages") or {}
    if code not in known:
        code = config.get("language") or ""
    info = known.get(code) or {}
    return (code, info.get("name") or config.get("language_name") or (langs.name(code) if code else ""),
            info.get("locale") or config.get("language_locale") or (langs.locale(code) if code else ""))


def choose_greeting(config, lang, caller_name="", rng=random):
    """The greeting template of a call. Candidates: the greetings of the call's language, else the application's
    own; without {caller_name} when the name is unknown (if one remains); of those the lowest order number,
    one of them at random."""
    pool = [(g["order"], g["text"]) for g in (config.get("greetings_by_lang") or {}).get(lang) or []]
    if not pool:
        own = config.get("greetings") or []
        orders = config.get("greeting_orders") or []
        pool = [(orders[i] if i < len(orders) else 0, t) for i, t in enumerate(own)] or [(0, config["greeting"])]
    if not caller_name:
        plain = [g for g in pool if not re.search(r"\{caller_name\}|\$name\b", g[1])]
        pool = plain or pool
    lowest = min(o for o, _ in pool)
    return rng.choice([t for o, t in pool if o == lowest])


def _realtime(rc, config):
    """The realtime_cloud block: provider, model, voice, language, endpoint, and the tuning keys."""
    out = {
        "provider": str(rc.get("provider") or "openai"),
        "api_key": str(rc.get("api_key") or ""),
        "model": str(rc.get("model") or ""),
        "voice": str(rc.get("voice") or "alloy"),
        "lang": str(rc.get("lang") or config.get("language") or "tr"),
    }
    if out["model"]:
        try:
            cloud_llm.check_model(out["model"], "realtime_cloud")
        except ValueError as e:
            raise ConfigError(str(e)) from None
    if rc.get("ws_url"):
        out["ws_url"] = str(rc["ws_url"])
    if rc.get("base_url"):
        out["base_url"] = str(rc["base_url"])
    if out["provider"] == "openai_compat" and rc.get("protocol"):
        if rc["protocol"] not in ("openai_ga", "openai_beta"):
            raise ConfigError("invalid Realtime protocol")
        out["protocol"] = rc["protocol"]
    vad = rc.get("vad") if isinstance(rc.get("vad"), dict) else {}
    try:
        out["vad"] = {
            "threshold": _number(vad, "threshold", 0.1, 0.99, REALTIME_VAD_THRESHOLD),
            "silence_ms": _number(vad, "silence_ms", 200, 3000, REALTIME_VAD_SILENCE_MS, integer=True),
        }
        out["temperature"] = None if rc.get("temperature") is None else _number(rc, "temperature", 0.2, 1.2, None)
    except ConfigError as e:
        raise ConfigError(f"realtime_cloud: {e}") from None
    noise = rc.get("noise_reduction", "off")
    if noise not in ("off", "near_field", "far_field"):
        raise ConfigError("realtime_cloud: noise_reduction must be off, near_field or far_field")
    out["noise_reduction"] = noise
    return out


def pick_local_fallback(manager, kind, lang="tr", preferred=None):
    """Best ready local model of `kind` for fallback when a cloud provider fails."""
    if manager is None:
        return None
    if isinstance(preferred, str) and preferred and not preferred.startswith("cloud:"):
        try:
            info = manager.describe(preferred)
            if info.get("kind") == kind and info.get("state") == READY:
                return preferred
        except UnknownModel:
            pass
    lang_prefix = (lang or "tr")[:2].lower()
    ready = [m for m in manager.describe_all() if m.get("kind") == kind and m.get("state") == READY]
    if not ready:
        return None
    default_id = "piper-tr-dfki"
    for m in ready:
        if m["id"] == default_id:
            return m["id"]
    for m in ready:
        if any(str(l)[:2].lower() == lang_prefix for l in (m.get("languages") or ())):
            return m["id"]
    return ready[0]["id"]


def public(config):
    """A config as the API shows it: a cloud provider's key is never returned."""
    out = dict(config)
    for name in ("stt_cloud", "tts_cloud", "llm_cloud", "realtime_cloud"):
        if name in out:
            out[name] = {k: v for k, v in out[name].items() if k not in ("api_key", "key")}
            out[name]["api_key_set"] = True
    out.pop("pbx_tools_key", None)
    return out


def _atomic_write(path, data):
    tmp = path.with_name(f".{path.name}.{os.getpid()}.{threading.get_ident()}.tmp")
    # 0600: an app config may hold a cloud provider's key.
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    except BaseException:
        tmp.unlink(missing_ok=True)
        raise


def trim_edge_silence(pcm, trim_start=True, trim_end=True, threshold=SILENCE_TRIM_RMS):
    """Trims leading/trailing dead-silence frames while keeping 1 frame (20 ms) of padding."""
    whole = len(pcm) - (len(pcm) % FRAME_BYTES)
    if whole < FRAME_BYTES * 3:
        return pcm
    frames = [pcm[i:i + FRAME_BYTES] for i in range(0, whole, FRAME_BYTES)]
    start, end = 0, len(frames)
    if trim_start:
        while start < end and frame_rms(frames[start]) < threshold:
            start += 1
        start = max(0, start - 1)
    if trim_end:
        while end > start and frame_rms(frames[end - 1]) < threshold:
            end -= 1
        end = min(len(frames), end + 1)
    if start >= end:
        return pcm
    return b"".join(frames[start:end])


def stitch_pcm(parts, segments=None):
    """Concatenates multiple 8 kHz 16-bit PCM segments, trimming join edges and inserting natural pauses."""
    valid = [(p, (segments[i] if segments and i < len(segments) else "")) for i, p in enumerate(parts) if p]
    if not valid:
        return b""
    if len(valid) == 1:
        return valid[0][0]
    out = []
    for i, (pcm, seg_text) in enumerate(valid):
        trimmed = trim_edge_silence(pcm, trim_start=(i > 0), trim_end=(i < len(valid) - 1))
        out.append(trimmed)
        if i < len(valid) - 1:
            pause_frames = 4 if seg_text.rstrip().endswith((".", "!", "?", ",", ";", ":")) else 2
            out.append(b"\x00" * (FRAME_BYTES * pause_frames))
    return b"".join(out)


class AppStore:
    """Pushed application configs and their prompt audio."""

    def __init__(self, manager, data_dir=None):
        self.manager = manager
        self.dir = Path(data_dir) / "apps" if data_dir else None
        self.tts_dir = Path(data_dir) / "tts_cache" if data_dir else None
        self._mu = threading.Lock()
        self._apps = {}              # id -> config
        self._version = {}           # id -> int, bumped by every PUT/DELETE
        self._cache = {}             # (id, version, prompt key) -> PCM
        self._phrase_cache = OrderedDict()   # sha256 -> PCM bytes (LRU)
        self._warming = set()        # (id, version)
        self._counter = 0
        if self.dir is not None:
            self.load()

    def load(self):
        try:
            files = sorted(self.dir.glob("*.json"))
        except OSError:
            return
        for path in files:
            if not path.stem.isdigit() or not 1 <= int(path.stem) <= APP_MAX:
                continue
            try:
                from .secret_refs import reference_config
                stored = json.loads(path.read_text(encoding="utf-8"))
                safe = reference_config(int(path.stem), stored)
                config = validate_config(safe, None)
                if safe != stored:
                    _atomic_write(path, json.dumps(config, ensure_ascii=False).encode("utf-8"))
            except (OSError, ValueError) as e:
                log.warning("app config %s ignored: %s", path.name, e)
                continue
            with self._mu:
                self._counter += 1
                self._apps[int(path.stem)] = config
                self._version[int(path.stem)] = self._counter
        if self._apps:
            log.info("loaded %d voice_requests app config(s)", len(self._apps))

    def put(self, app_id, data):
        if self.dir is not None:
            from .secret_refs import reference_config
            data = reference_config(app_id, data)
        config = validate_config(data, self.manager)
        encoded = json.dumps(config, ensure_ascii=False, indent=1).encode("utf-8")
        with self._mu:
            if self.dir is not None:
                self.dir.mkdir(parents=True, exist_ok=True)
                _atomic_write(self.dir / f"{app_id}.json", encoded)
            self._counter += 1
            self._apps[app_id] = config
            self._version[app_id] = self._counter
            self._drop_cache(app_id)
        return config

    def delete(self, app_id):
        with self._mu:
            existed = self._apps.pop(app_id, None) is not None
            self._version.pop(app_id, None)
            self._drop_cache(app_id)
            if self.dir is not None:
                try:
                    (self.dir / f"{app_id}.json").unlink()
                    existed = True
                except FileNotFoundError:
                    pass
        return existed

    def clear_tts_cache(self):
        """Forgets every synthesised prompt and phrase (memory and disk); they are made again on first use.
        For a wrongly made voice, and after the settings of a voice changed."""
        with self._mu:
            self._cache.clear()
            self._phrase_cache.clear()
        removed = size = 0
        if self.tts_dir is not None:
            for f in self.tts_dir.glob("*.pcm"):
                try:
                    size += f.stat().st_size
                    f.unlink()
                    removed += 1
                except OSError:
                    pass
        return {"removed": removed, "bytes": size}

    def _drop_cache(self, app_id):
        for key in [k for k in self._cache if k[0] == app_id]:
            del self._cache[key]

    def get(self, app_id):
        """(config, version) or (None, None)."""
        with self._mu:
            return self._apps.get(app_id), self._version.get(app_id)

    def list(self):
        with self._mu:
            return [dict(public(config), id=app_id) for app_id, config in sorted(self._apps.items())]

    # ---- prompts --------------------------------------------------------

    @staticmethod
    def prompt_text(config, key):
        if key.startswith("waiting:"):
            return config["waiting_phrases"][int(key[8:])]
        if key.startswith("reply:"):
            intent_id = key[6:]
            return next((i["reply"] for i in config["intents"] if i["id"] == intent_id), "")
        return config.get(key, "")

    @staticmethod
    def prompt_keys(config):
        waiting = [f"waiting:{i}" for i in range(len(config.get("waiting_phrases", [])))]
        return ["greeting", *waiting, "retry", "not_understood"] + ["reply:" + i["id"] for i in config["intents"]]

    @staticmethod
    def greeting_text(config, caller_name="", caller_number="", did=""):
        values = {"{caller_name}": caller_name, "$name": caller_name, "{caller_number}": caller_number, "{did}": did}

        def fill(match):
            value = values[match[2]]
            return match[1] + value if value else ""

        return re.sub(r"( *)(\{caller_name\}|\$name\b|\{caller_number\}|\{did\})", fill, config["greeting"]).strip()

    def cached(self, app_id, version, key):
        with self._mu:
            return self._cache.get((app_id, version, key))

    def prompt(self, app_id, version, config, key):
        """The prompt's 8 kHz PCM (b"" for an empty text); synthesises on a cache miss.

        Raises what ModelManager.synthesize raises."""
        pcm = self.cached(app_id, version, key)
        if pcm is not None:
            return pcm
        text = self.greeting_text(config) if key == "greeting" else self.prompt_text(config, key)
        pcm = self.speech(app_id, config, text)
        with self._mu:
            if self._version.get(app_id) == version:
                self._cache[(app_id, version, key)] = pcm
        return pcm

    @staticmethod
    def _phrase_key(config, text):
        norm = " ".join(text.split())
        model = str(config.get("tts_model") or "")
        speed = f"{float(config.get('speed', 1.0)):.2f}"
        lang = str(config.get("call_language") or config.get("language") or "")
        if model.startswith("cloud:"):
            tc = config.get("tts_cloud") or {}
            voice_id = f"{tc.get('provider', '')}:{tc.get('model', '')}:{tc.get('voice', '')}:{tc.get('lang', '')}"
        else:
            voice_id = model
        raw = f"{model}|{voice_id}|{speed}|{lang}|{norm}"
        return hashlib.sha256(raw.encode("utf-8")).hexdigest()

    def _phrase_get(self, key):
        with self._mu:
            pcm = self._phrase_cache.get(key)
            if pcm is not None:
                self._phrase_cache.move_to_end(key)
                return pcm
        if self.tts_dir is not None:
            path = self.tts_dir / f"{key}.pcm"
            try:
                pcm = path.read_bytes()
                if pcm and len(pcm) % 2 == 0:
                    with self._mu:
                        self._phrase_cache[key] = pcm
                        self._phrase_cache.move_to_end(key)
                        while len(self._phrase_cache) > PHRASE_CACHE_MEMORY:
                            self._phrase_cache.popitem(last=False)
                    return pcm
            except OSError:
                pass
        return None

    def _phrase_put(self, key, pcm):
        if not pcm:
            return
        with self._mu:
            self._phrase_cache[key] = pcm
            self._phrase_cache.move_to_end(key)
            while len(self._phrase_cache) > PHRASE_CACHE_MEMORY:
                self._phrase_cache.popitem(last=False)
        if self.tts_dir is not None:
            try:
                self.tts_dir.mkdir(parents=True, exist_ok=True)
                _atomic_write(self.tts_dir / f"{key}.pcm", pcm)
            except OSError:
                pass

    @classmethod
    def split_greeting_segments(cls, template, caller_name="", caller_number="", did=""):
        """Splits a greeting template with dynamic variables into static and dynamic segments for caching.

        * When no dynamic variable in `template` has a value, returns a single-element list [full_text].
        * When dynamic variables are filled:
          - Splits at clause/sentence punctuation boundaries (.!?,;: followed by space).
          - If a clause containing a dynamic variable has more than 2 non-variable words (no comma around the name),
            splits that clause around the variable into [prefix, var_value, suffix].
          - Merges consecutive static clauses so static parts stay one cached segment.
        """
        values = {"{caller_name}": caller_name, "$name": caller_name, "{caller_number}": caller_number, "{did}": did}
        active_vars = {k for k, v in values.items() if v}
        has_active = any(m.group(0) in active_vars for m in _GREETING_VAR_RE.finditer(template))
        if not has_active:
            full = cls.greeting_text({"greeting": template}, caller_name, caller_number, did)
            return [full] if full else []

        raw_clauses = [c.strip() for c in re.split(r"(?<=[.!?,;:])\s+", template.strip()) if c.strip()]
        tagged = []  # list of (is_dynamic: bool, rendered_text: str)
        for clause in raw_clauses:
            matches = list(_GREETING_VAR_RE.finditer(clause))
            if not any(m.group(0) in active_vars for m in matches):
                rendered = cls.greeting_text({"greeting": clause}, caller_name, caller_number, did)
                if rendered:
                    tagged.append((False, rendered))
                continue
            without_vars = _GREETING_VAR_RE.sub("", clause).strip(" ,;.!?-")
            non_var_words = [w for w in without_vars.split() if w]
            if len(non_var_words) > 2 and len(matches) == 1 and matches[0].group(0) in active_vars:
                m = matches[0]
                before = cls.greeting_text({"greeting": clause[:m.start()]}, caller_name, caller_number, did)
                var_val = values[m.group(0)].strip()
                after = cls.greeting_text({"greeting": clause[m.end():]}, caller_name, caller_number, did)
                if before:
                    tagged.append((False, before))
                if var_val:
                    tagged.append((True, var_val))
                if after:
                    tagged.append((False, after))
            else:
                rendered = cls.greeting_text({"greeting": clause}, caller_name, caller_number, did)
                if rendered:
                    tagged.append((True, rendered))

        merged = []
        for is_dyn, text in tagged:
            if not is_dyn and merged and not merged[-1][0]:
                merged[-1] = (False, merged[-1][1] + " " + text)
            else:
                merged.append((is_dyn, text))
        return [t for _, t in merged if t]

    def greeting_speech(self, app_id, version, config, template, caller_name="", caller_number="", did="", lang=""):
        """Synthesises a greeting (single cached prompt when static, or stitched cached segments when dynamic).
        The cache is per language: `lang` is the call's language."""
        own = not lang or lang == config.get("language")
        if not own:
            config = {**config, "call_language": lang}
        segments = self.split_greeting_segments(template, caller_name, caller_number, did)
        if not segments:
            return b""
        if len(segments) == 1:
            if own and segments[0] == self.greeting_text(config):
                return self.prompt(app_id, version, config, "greeting")
            return self.speech(app_id, config, segments[0])
        parts = [self.speech(app_id, config, seg) for seg in segments]
        return stitch_pcm(parts, segments)

    def speech(self, app_id, config, text):
        """Any text in the application's voice as 8 kHz PCM (b"" for an empty text), cached by content+voice:
        the prompts, greeting segments, and the answers of a conversation."""
        pcm = b""
        text = (text or "").strip()
        if text:
            key = self._phrase_key(config, text)
            cached_pcm = self._phrase_get(key)
            if cached_pcm is not None:
                return cached_pcm
            if config["tts_model"].startswith("cloud:"):
                lang = config.get("language") or config.get("tts_cloud", {}).get("lang") or "tr"
                try:
                    pcm = bytes(cloud_tts.synthesize(config["tts_cloud"], text, lang=lang, speed=config["speed"]))
                except Exception as e:  # noqa: BLE001
                    fb = pick_local_fallback(self.manager, "tts", lang=lang, preferred=config.get("tts_fallback"))
                    if not fb:
                        raise
                    log.warning("app %d: cloud tts (%s) failed (%s), falling back to %s", app_id, config["tts_model"], type(e).__name__, fb)
                    pcm = bytes(self.manager.synthesize(fb, text, config["speed"], SAMPLE_RATE))
            else:
                pcm = bytes(self.manager.synthesize(config["tts_model"], text, config["speed"], SAMPLE_RATE))
            if len(pcm) % 2:
                pcm = pcm[:-1]
            if pcm:
                self._phrase_put(key, pcm)
        return pcm

    def warm(self, app_id, version, config):
        """Synthesises the prompts not cached yet, one after the other, in the background."""
        with self._mu:
            if (app_id, version) in self._warming:
                return
            self._warming.add((app_id, version))

        def run():
            try:
                for key in self.prompt_keys(config):
                    if self.get(app_id)[1] != version:
                        return
                    try:
                        self.prompt(app_id, version, config, key)
                    except Exception as e:  # noqa: BLE001 - made again on first use
                        log.info("app %d: prompt %s not prepared (%s)", app_id, key, type(e).__name__)
            finally:
                with self._mu:
                    self._warming.discard((app_id, version))

        threading.Thread(target=run, name=f"prompts-{app_id}", daemon=True).start()


# ---- voice activity -----------------------------------------------------

def frame_rms(frame):
    samples = array("h")
    samples.frombytes(frame[:len(frame) - len(frame) % 2])
    if sys.byteorder == "big":
        samples.byteswap()
    if not samples:
        return 0.0
    return math.sqrt(sum(s * s for s in samples) / len(samples))


class Listener:
    """Energy VAD over 20 ms frames of 8 kHz 16-bit audio.

    * The first 300 ms set the noise floor (mean of the quieter half of the
      frames, so a caller who talks at once does not hide their own speech),
      at most 800 RMS; until speech starts the floor follows the frames below
      speech level (quickly down, slowly up).
    * Speech level: RMS >= max(3 x floor, 250). Three frames in a row
      (60 ms) at speech level start the utterance; 300 ms before it are kept.
    * The utterance ends after 800 ms below speech level, or when
      listen_seconds have passed since listening started.
    * Nothing at speech level within 4 s (or listen_seconds, if shorter):
      silence.

    feed() returns None while listening, else the reason it stopped:
    "speech", "timeout" (speech cut at listen_seconds) or "silence".
    """

    def __init__(self, listen_seconds, silence_seconds=NO_SPEECH_SECONDS, profile="normal", start_frames=START_FRAMES,
                 vad=None, turn=None):
        self.start_frames = start_frames     # frames in a row at speech level that start the utterance
        self.vad = vad                       # onnx_models.SileroStream: a frame needs a voice, not only energy
        self.turn = turn                     # onnx_models.SmartTurnBackend: decides when the turn is over
        self.voices = []                     # per frame: did the VAD hear a voice
        self.turn_notes = []                 # what the detectors decided, for the call log
        self._next_check = TURN_CHECK_FRAMES
        self.max_frames = int(round(listen_seconds / FRAME_SECONDS))
        self.no_speech_frames = int(round(min(silence_seconds, listen_seconds) / FRAME_SECONDS))
        self.speech_factor, self.min_speech_rms = PROFILES.get(profile, PROFILES["normal"])
        self.frames = []             # every frame since listening started
        self.levels = []
        self.floor = self.base_floor = None
        self.loud_run = 0
        self.start = None            # index of the first frame of the utterance (with pre-roll)
        self.last_loud = None        # index of the last frame at speech level
        self.last_loud_time = None   # monotonic arrival time of it
        self._partial = b""

    @property
    def threshold(self):
        return max(self.speech_factor * (self.floor or MIN_FLOOR), self.min_speech_rms)

    def feed(self, data, now=None):
        """Feeds inbound audio (any length); returns None or the stop reason."""
        data = self._partial + data
        whole = len(data) - len(data) % FRAME_BYTES
        self._partial = data[whole:]
        for i in range(0, whole, FRAME_BYTES):
            reason = self._frame(data[i:i + FRAME_BYTES], now)
            if reason:
                return reason
        return None

    def _frame(self, frame, now):
        index = len(self.frames)
        self.frames.append(frame)
        level = frame_rms(frame)
        self.levels.append(level)
        voice = self._voice(frame)
        self.voices.append(voice)
        if self.floor is None:
            if index + 1 >= CALIBRATION_FRAMES:
                quiet = sorted(self.levels)[:max(1, len(self.levels) // 2)]
                self.floor = min(MAX_FLOOR, max(MIN_FLOOR, sum(quiet) / len(quiet)))
                self.base_floor = self.floor   # faint(): the floor before a faint voice pulls it up
                # frames of the calibration window may already be speech
                for j, lv in enumerate(self.levels):
                    if self._level(lv, j, now, self.voices[j]):
                        return "speech"
                return self._limits(index)
            return None
        if self._level(level, index, now, voice):
            return "speech"
        return self._limits(index)

    def _voice(self, frame):
        if self.vad is None:
            return True
        try:
            return self.vad.feed(frame) >= SILERO_THRESHOLD
        except Exception as e:  # noqa: BLE001 - a failing detector never costs a call: back to the energy level alone
            self.vad = None
            self.turn_notes.append(f"voice detector failed ({type(e).__name__}): the energy level alone decides")
            return True

    def _level(self, level, index, now, voice=True):
        """Updates the speech state with one frame; True when the utterance ended."""
        loud = level >= self.threshold and voice
        if loud:
            self._next_check = TURN_CHECK_FRAMES
            self.loud_run += 1
            if self.start is not None or self.loud_run >= self.start_frames:
                self.last_loud = index
                self.last_loud_time = now if now is not None else time.monotonic()
            if self.start is None and self.loud_run >= self.start_frames:
                self.start = max(0, index - self.start_frames + 1 - PRE_ROLL_FRAMES)
        else:
            self.loud_run = 0
            if self.start is None:
                rate = FLOOR_FALL if level < self.floor else FLOOR_ADAPT
                self.floor = min(MAX_FLOOR, max(MIN_FLOOR, (1 - rate) * self.floor + rate * level))
        return self.start is not None and self._ended(index)

    def _ended(self, index):
        """The silence after the last loud frame ends the turn: 800 ms, or the Smart Turn model's say."""
        quiet = index - self.last_loud
        if self.turn is None:
            return quiet >= END_FRAMES
        if quiet < self._next_check:
            return False
        ms = quiet * 20
        if quiet >= TURN_CAP_FRAMES:
            self.turn_notes.append(f"end of turn: {ms} ms of silence and no sign that the caller is done: ended")
            return True
        try:
            probability = self.turn.complete(b"".join(self.frames[self.start:index + 1]))
        except Exception as e:  # noqa: BLE001 - back to the fixed silence
            self.turn = None
            self.turn_notes.append(f"end-of-turn model failed ({type(e).__name__}): the fixed silence decides")
            return quiet >= END_FRAMES
        if probability >= TURN_THRESHOLD:
            self.turn_notes.append(f"end of turn: complete (p={probability:.2f}) after {ms} ms of silence")
            return True
        self._next_check = quiet + TURN_RECHECK_FRAMES
        return False

    def _limits(self, index):
        if index + 1 >= self.max_frames:
            return "timeout" if self.start is not None else "silence"
        if self.start is None and index + 1 >= self.no_speech_frames:
            return "silence"
        return None

    @property
    def heard(self):
        return self.start is not None

    def faint(self):
        """No speech, but sound clearly above the floor for a while: a voice too quiet to use."""
        if self.start is not None or self.floor is None:
            return False
        level = max(FAINT_FACTOR * min(self.floor, self.base_floor), MIN_FAINT_RMS)
        return sum(lv >= level for lv in self.levels) >= FAINT_FRAMES

    def utterance(self):
        """The caller's speech as PCM (b"" when nothing was said)."""
        if self.start is None:
            return b""
        end = min(len(self.frames), self.last_loud + 1 + TAIL_FRAMES)
        return b"".join(self.frames[self.start:end])

    def seconds(self):
        return round(len(self.frames) * FRAME_SECONDS, 2)


# ---- results ------------------------------------------------------------

def valid_uuid(value):
    try:
        return str(uuid_mod.UUID(value)) == value
    except (ValueError, TypeError, AttributeError):
        return False


class ResultStore:
    """Results of voice_requests calls: memory + <data>/calls/<uuid>.json (+ .wav), 24 h."""

    def __init__(self, data_dir=None, keep_seconds=RESULT_KEEP_SECONDS):
        self.dir = Path(data_dir) / "calls" if data_dir else None
        self.keep = keep_seconds
        self._mu = threading.Lock()
        self._mem = OrderedDict()    # uuid -> (saved wall time, result)

    def save(self, result, pcm=b""):
        """Stores the result (and the utterance as an 8 kHz WAV); sets result["audio"]."""
        from .wav import wav_bytes

        call_uuid = result["uuid"]
        result["audio"] = False
        if self.dir is not None:
            try:
                self.dir.mkdir(parents=True, exist_ok=True)
                if pcm:
                    _atomic_write(self.dir / f"{call_uuid}.wav", wav_bytes(pcm, SAMPLE_RATE))
                    result["audio"] = True
                _atomic_write(self.dir / f"{call_uuid}.json",
                              json.dumps(result, ensure_ascii=False).encode("utf-8"))
            except OSError as e:
                log.warning("call %s: result not saved (%s)", call_uuid, e)
        with self._mu:
            self._mem[call_uuid] = (time.time(), result)
            while len(self._mem) > RESULT_MEMORY:
                self._mem.popitem(last=False)

    def get(self, call_uuid):
        if not valid_uuid(call_uuid):
            return None
        with self._mu:
            item = self._mem.get(call_uuid)
        if item is not None:
            return item[1] if time.time() - item[0] <= self.keep else None
        if self.dir is None:
            return None
        path = self.dir / f"{call_uuid}.json"
        try:
            if time.time() - path.stat().st_mtime > self.keep:
                return None
            data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return None
        return data if isinstance(data, dict) and data.get("uuid") == call_uuid else None

    def audio_path(self, call_uuid):
        if self.dir is None or not valid_uuid(call_uuid) or self.get(call_uuid) is None:
            return None
        path = self.dir / f"{call_uuid}.wav"
        return path if path.is_file() else None

    def cleanup(self, now=None):
        """Deletes results older than 24 h (memory and files); returns the files removed."""
        now = time.time() if now is None else now
        with self._mu:
            for key in [k for k, (t, _) in self._mem.items() if now - t > self.keep]:
                del self._mem[key]
        removed = 0
        if self.dir is None:
            return removed
        try:
            paths = list(self.dir.iterdir())
        except OSError:
            return removed
        for path in paths:
            if path.suffix not in (".json", ".wav", ".tmp"):
                continue
            try:
                if now - path.stat().st_mtime > self.keep:
                    path.unlink()
                    removed += 1
            except OSError:
                pass
        return removed
