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
import json
import logging
import math
import os
import re
import sys
import threading
import time
import uuid as uuid_mod
from array import array
from collections import OrderedDict
from pathlib import Path

from .models import UnknownModel

log = logging.getLogger("aipbx_ai.voice_requests")

APP_TYPE = "voice_requests"
APP_MAX = 1000000
MAX_INTENTS = 30
MAX_WORDS = 30               # keywords, and examples, per intent
MAX_TEXT = 1000
LISTEN_MIN, LISTEN_MAX, LISTEN_DEFAULT = 2, 15, 7
RETRIES_MAX, RETRIES_DEFAULT = 2, 1
THRESHOLD_DEFAULT = 0.5
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
END_FRAMES = 40              # 800 ms below it end the utterance
NO_SPEECH_SECONDS = 4.0      # nothing said by then: silence
PRE_ROLL_FRAMES = 15         # 300 ms kept before the detected start
TAIL_FRAMES = 15             # 300 ms kept after the last loud frame
SPEECH_FACTOR = 3.0          # speech: RMS at least 3x (about +10 dB) the floor ...
MIN_SPEECH_RMS = 250         # ... and at least this (about -42 dBFS)
MIN_FLOOR, MAX_FLOOR = 20.0, 800.0   # RMS; 800 is about -32 dBFS, louder line noise is capped
FLOOR_FALL = 0.3            # the floor falls quickly to quieter frames ...
FLOOR_ADAPT = 0.05           # ... and rises slowly to louder ones (before speech)

RESULT_KEEP_SECONDS = 24 * 3600
RESULT_MEMORY = 2000


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
    if not isinstance(value, str) or not _MODEL_ID.match(value):
        raise ConfigError(f"{name} is not a valid model id")
    if manager is not None:
        try:
            info = manager.describe(value)
        except UnknownModel:
            raise ConfigError(f"{name}: unknown model {value}") from None
        if info["kind"] != kind:
            raise ConfigError(f"{name}: {value} is not a {'text-to-speech' if kind == 'tts' else 'speech-to-text'} model")
    return value


def validate_config(data, manager=None):
    """The normalised config, or ConfigError. Unknown fields are ignored."""
    if not isinstance(data, dict):
        raise ConfigError("the config must be a JSON object")
    if data.get("type") != APP_TYPE:
        raise ConfigError(f"type must be {APP_TYPE}")
    config = {
        "type": APP_TYPE,
        "tts_model": _model(data, "tts_model", "tts", manager),
        "stt_model": _model(data, "stt_model", "stt", manager),
        "speed": _number(data, "speed", SPEED_MIN, SPEED_MAX, 1.0),
        "greeting": _text(data, "greeting", required=True),
        "retry": _text(data, "retry"),
        "not_understood": _text(data, "not_understood"),
        "listen_seconds": _number(data, "listen_seconds", LISTEN_MIN, LISTEN_MAX, LISTEN_DEFAULT, integer=True),
        "retries": _number(data, "retries", 0, RETRIES_MAX, RETRIES_DEFAULT, integer=True),
        "threshold": _number(data, "threshold", 0.0, 1.0, THRESHOLD_DEFAULT),
    }
    intents = data.get("intents")
    if not isinstance(intents, list) or not intents:
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
        if intent_id == "none":
            raise ConfigError(f"{where}id 'none' is reserved")
        if intent_id in seen:
            raise ConfigError(f"{where}id {intent_id} is used twice")
        seen.add(intent_id)
        where = f"intent {intent_id}: "
        item = {"id": intent_id, "name": _text(intent, "name", where=where),
                "keywords": _words(intent, "keywords", where), "examples": _words(intent, "examples", where),
                "reply": _text(intent, "reply", where=where)}
        if not item["keywords"] and not item["examples"]:
            raise ConfigError(f"{where}needs at least one keyword or example")
        out.append(item)
    config["intents"] = out
    return config


def _atomic_write(path, data):
    tmp = path.with_name(f".{path.name}.{os.getpid()}.{threading.get_ident()}.tmp")
    with open(tmp, "wb") as f:
        f.write(data)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)


class AppStore:
    """Pushed application configs and their prompt audio."""

    def __init__(self, manager, data_dir=None):
        self.manager = manager
        self.dir = Path(data_dir) / "apps" if data_dir else None
        self._mu = threading.Lock()
        self._apps = {}              # id -> config
        self._version = {}           # id -> int, bumped by every PUT/DELETE
        self._cache = {}             # (id, version, prompt key) -> PCM
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
                config = validate_config(json.loads(path.read_text(encoding="utf-8")), None)
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
        config = validate_config(data, self.manager)
        if self.dir is not None:
            self.dir.mkdir(parents=True, exist_ok=True)
            _atomic_write(self.dir / f"{app_id}.json",
                          json.dumps(config, ensure_ascii=False, indent=1).encode("utf-8"))
        with self._mu:
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

    def _drop_cache(self, app_id):
        for key in [k for k in self._cache if k[0] == app_id]:
            del self._cache[key]

    def get(self, app_id):
        """(config, version) or (None, None)."""
        with self._mu:
            return self._apps.get(app_id), self._version.get(app_id)

    def list(self):
        with self._mu:
            return [dict(config, id=app_id) for app_id, config in sorted(self._apps.items())]

    # ---- prompts --------------------------------------------------------

    @staticmethod
    def prompt_text(config, key):
        if key.startswith("reply:"):
            intent_id = key[6:]
            return next((i["reply"] for i in config["intents"] if i["id"] == intent_id), "")
        return config.get(key, "")

    @staticmethod
    def prompt_keys(config):
        return ["greeting", "retry", "not_understood"] + ["reply:" + i["id"] for i in config["intents"]]

    def cached(self, app_id, version, key):
        with self._mu:
            return self._cache.get((app_id, version, key))

    def prompt(self, app_id, version, config, key):
        """The prompt's 8 kHz PCM (b"" for an empty text); synthesises on a cache miss.

        Raises what ModelManager.synthesize raises."""
        pcm = self.cached(app_id, version, key)
        if pcm is not None:
            return pcm
        text = self.prompt_text(config, key)
        pcm = b""
        if text:
            pcm = bytes(self.manager.synthesize(config["tts_model"], text, config["speed"], SAMPLE_RATE))
            if len(pcm) % 2:
                pcm = pcm[:-1]
        with self._mu:
            if self._version.get(app_id) == version:
                self._cache[(app_id, version, key)] = pcm
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

    def __init__(self, listen_seconds):
        self.max_frames = int(round(listen_seconds / FRAME_SECONDS))
        self.no_speech_frames = int(round(min(NO_SPEECH_SECONDS, listen_seconds) / FRAME_SECONDS))
        self.frames = []             # every frame since listening started
        self.levels = []
        self.floor = None
        self.loud_run = 0
        self.start = None            # index of the first frame of the utterance (with pre-roll)
        self.last_loud = None        # index of the last frame at speech level
        self.last_loud_time = None   # monotonic arrival time of it
        self._partial = b""

    @property
    def threshold(self):
        return max(SPEECH_FACTOR * (self.floor or MIN_FLOOR), MIN_SPEECH_RMS)

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
        if self.floor is None:
            if index + 1 >= CALIBRATION_FRAMES:
                quiet = sorted(self.levels)[:max(1, len(self.levels) // 2)]
                self.floor = min(MAX_FLOOR, max(MIN_FLOOR, sum(quiet) / len(quiet)))
                # frames of the calibration window may already be speech
                for j, lv in enumerate(self.levels):
                    if self._level(lv, j, now):
                        return "speech"
                return self._limits(index)
            return None
        if self._level(level, index, now):
            return "speech"
        return self._limits(index)

    def _level(self, level, index, now):
        """Updates the speech state with one frame; True when the utterance ended."""
        loud = level >= self.threshold
        if loud:
            self.loud_run += 1
            if self.start is not None or self.loud_run >= START_FRAMES:
                self.last_loud = index
                self.last_loud_time = now if now is not None else time.monotonic()
            if self.start is None and self.loud_run >= START_FRAMES:
                self.start = max(0, index - START_FRAMES + 1 - PRE_ROLL_FRAMES)
        else:
            self.loud_run = 0
            if self.start is None:
                rate = FLOOR_FALL if level < self.floor else FLOOR_ADAPT
                self.floor = min(MAX_FLOOR, max(MIN_FLOOR, (1 - rate) * self.floor + rate * level))
        return self.start is not None and index - self.last_loud >= END_FRAMES

    def _limits(self, index):
        if index + 1 >= self.max_frames:
            return "timeout" if self.start is not None else "silence"
        if self.start is None and index + 1 >= self.no_speech_frames:
            return "silence"
        return None

    @property
    def heard(self):
        return self.start is not None

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
