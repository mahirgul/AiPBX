"""A short history of the AI calls for the portal's call log (AI -> Calls).

One JSON line per finished call in $AIPBX_AI_DATA/call-log.jsonl: when, which application, the result,
how long the audio took to prepare and to play, whether the 20 ms pacing slipped, what the caller said
and what went wrong. The newest KEEP calls are kept, nothing older than MAX_AGE_DAYS. Transcripts and
errors are cut short and never carry a key or a token.
"""
import json
import os
import re
import threading
import time

KEEP = 300
MAX_AGE_DAYS = 7
NOTE_CHARS = 200
_SECRET = re.compile(r"(?i)\b((?:api[_-]?key|key|token|secret|password|authorization)\s*[=:]\s*|bearer\s+)[A-Za-z0-9._~+/=-]{6,}")
_LONG = re.compile(r"[A-Za-z0-9_-]{32,}")


def redact(text, limit=NOTE_CHARS):
    """For error texts (they can carry a URL with ?key=… or a bearer token)."""
    text = _SECRET.sub(r"\1***", str(text))
    return _LONG.sub("***", text)[:limit]


_DIGITS = re.compile(r"(?:\d[ -]?){6,}")


# The settings that handled a call, for a support report: names and numbers only, never a key or a text.
SETTING_KEYS = ("language", "stt_lang", "stt_model", "tts_model", "understand", "speed", "listen_seconds", "retries",
                "silence_seconds", "line_profile", "barge_profile", "barge_in", "speech_detection", "end_of_turn",
                "end_on_goodbye", "mode", "max_turns", "conversation", "builtin_agent", "knowledge",
                "automatic_announcements")


def settings(config):
    """A call's settings as plain values; providers and models of cloud accounts by name, without credentials."""
    config = config or {}
    out = {k: config[k] for k in SETTING_KEYS if isinstance(config.get(k), (str, int, float, bool))}
    for key in ("realtime_cloud", "llm_cloud", "stt_cloud", "tts_cloud"):
        account = config.get(key)
        if isinstance(account, dict) and account.get("provider"):
            out[key] = "/".join(str(account[k]) for k in ("provider", "model") if account.get(k))
    return out


def clip(text, limit=120):
    """For what a caller said: a long unbroken string (a pasted key) and any run of 6 or more digits
    (card, ID or phone numbers) are hidden, as in the other transcripts under a strict privacy preset."""
    return _DIGITS.sub("***", _LONG.sub("***", str(text)))[:limit]


class CallLog:
    def __init__(self, data_dir, keep=KEEP, max_age_days=MAX_AGE_DAYS):
        self.path = os.path.join(str(data_dir), "call-log.jsonl") if data_dir else None
        self.keep = keep
        self.max_age = max_age_days * 86400
        self._mu = threading.Lock()
        self._writes = 0
        if self.path and os.path.exists(self.path):
            self._trim()        # a quiet install must not keep old calls for ever

    def add(self, record):
        if not self.path:
            return
        line = json.dumps(record, ensure_ascii=False, separators=(",", ":"))
        with self._mu:
            try:
                with open(self.path, "a", encoding="utf-8") as f:
                    f.write(line + "\n")
                self._writes += 1
                if self._writes % 25 == 0:
                    self._trim()
            except OSError:
                pass

    def _lines(self):
        try:
            with open(self.path, encoding="utf-8") as f:
                return f.read().splitlines()
        except OSError:
            return []

    def _trim(self):
        keep = self._fresh(self._lines())[-self.keep:]
        tmp = self.path + ".tmp"
        try:
            with open(tmp, "w", encoding="utf-8") as f:
                f.write("".join(x + "\n" for x in keep))
            os.replace(tmp, self.path)
        except OSError:
            pass

    def _fresh(self, lines):
        cutoff = time.time() - self.max_age
        out = []
        for x in lines:
            try:
                if json.loads(x).get("ended", 0) >= cutoff:
                    out.append(x)
            except ValueError:
                continue
        return out

    def recent(self, limit=100):
        with self._mu:
            lines = self._fresh(self._lines())
        return [json.loads(x) for x in reversed(lines[-max(1, min(int(limit), self.keep)):])]
