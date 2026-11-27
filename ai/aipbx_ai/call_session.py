"""One call as the service sees it: its state, what was heard, timings and notes (the call log's line).
"""
import threading
import time
import uuid
from .calllog import clip, redact, settings
from .call_media import AUDIO_RATES, KIND_AUDIO, SAMPLE_RATE


MAX_TURN_LOG = 12            # conversation turns whose timings the call log keeps


PREPARED, PLAYING, DONE = "prepared", "playing", "done"


ANNOUNCEMENT = "announcement"


class CallSession:
    def __init__(self, app, model, caller, did, uniqueid, kind=ANNOUNCEMENT):
        self.uuid = str(uuid.uuid4())
        self.context = ""              # a conversation: the summary handed over by the assistant before
        self.verified_customer = ""    # trusted dialplan value, never determined from the transcript
        self.kind = kind
        self.app = app
        self.model = model
        self.caller = caller
        self.caller_name = ""
        self.lang = ""                 # the call's language code (channel language when the PBX has it)
        self.lang_name = ""
        self.lang_locale = ""
        self.greeting = ""             # the chosen greeting template
        self.did = did
        self.uniqueid = uniqueid
        self.linkedid = ""
        self.state = PREPARED
        self.created = time.monotonic()
        self.started = time.time()
        self.connected = None          # monotonic time of the AudioSocket connection
        self.ended = None              # wall time
        self.ended_mono = None
        self.chars = 0
        self.pcm = b""
        self.audio_seconds = 0.0
        self.sent_bytes = 0
        self.result = ""
        self.lookup = ""               # "", "ok", "failed: ..."
        self.prepare_ms = 0            # /v1/calls/start: lookup + synthesis
        # From the caller (later application types listen to it).
        self.dtmf = []
        self._last_dtmf = ("", 0.0)
        self.inbound_bytes = 0
        self.inbound_frames = 0
        self.inbound_rate = 0
        self.dtmf_event = threading.Event()
        # voice_requests
        self.config = None             # the application config at start
        self.version = None
        self.listen_q = None           # a queue while listening: ("audio"|"dtmf", time, data)
        self.intent = None
        self.transcript = None
        self.max_call_seconds = None
        self.heard = []                # what speech recognition returned, in order
        self.notes = []                # what went wrong, for the call log: [seconds, text]
        self.late_frames = 0           # 20 ms frames sent more than 20 ms late
        self.max_late_ms = 0
        self.resyncs = 0               # the pacing fell so far behind that it started over
        self.turns = []                # conversation: per turn {stt_ms, llm_ms, lookup_ms, tts_ms, first_audio_ms, end_silence_ms}

    @property
    def closed(self):
        """The call is over (the sweeper or the dialplan ended it)."""
        return self.state == DONE

    def note(self, text):
        if len(self.notes) < 25:
            self.notes.append([round(time.monotonic() - self.created, 1), redact(text)])

    def turn(self, timings):
        """One conversation turn's timings (milliseconds) for the call log; the first MAX_TURN_LOG turns."""
        if len(self.turns) < MAX_TURN_LOG:
            self.turns.append({k: int(v) for k, v in timings.items() if v is not None})

    def hear(self, text):
        if text and len(self.heard) < 8:
            self.heard.append(text)

    def late(self, behind):
        """A frame left `behind` seconds after its time."""
        if behind > 0.02:
            self.late_frames += 1
            self.max_late_ms = max(self.max_late_ms, int(behind * 1000))

    def record(self):
        """The call log line."""
        played = self.sent_bytes / 2 / SAMPLE_RATE
        tr = self.heard or ([self.transcript] if isinstance(self.transcript, str) and self.transcript else [])
        return {"uuid": self.uuid, "app": self.app, "type": self.kind, "model": self.model, "caller": self.caller,
                "did": self.did, "started": int(self.started), "ended": int(self.ended or time.time()),
                "seconds": self.seconds(), "result": self.result, "prepare_ms": self.prepare_ms, "chars": self.chars,
                "audio_seconds": round(self.audio_seconds, 1), "played_seconds": round(played, 1),
                "late_frames": self.late_frames, "max_late_ms": self.max_late_ms, "resyncs": self.resyncs,
                "inbound_frames": self.inbound_frames, "intent": self.intent,
                "transcripts": [clip(t) for t in tr[:8]], "notes": self.notes, "settings": settings(self.config),
                "language": self.lang, "turns": self.turns}

    def audio_in(self, kind, payload):
        self.inbound_bytes += len(payload)
        self.inbound_frames += 1
        self.inbound_rate = AUDIO_RATES[kind]
        q = self.listen_q
        if q is not None and kind == KIND_AUDIO:
            q.put(("audio", time.monotonic(), payload))

    def digit_in(self, digit):
        now = time.monotonic()
        last_d, last_t = self._last_dtmf
        if digit == last_d and (now - last_t) < 0.08:
            return
        self._last_dtmf = (digit, now)
        self.dtmf.append(digit)
        self.dtmf_event.set()
        q = self.listen_q
        if q is not None:
            q.put(("dtmf", now, digit))

    def seconds(self):
        end = self.ended_mono if self.ended_mono is not None else time.monotonic()
        return round(end - self.created, 1)

    def describe(self):
        return {"uuid": self.uuid, "type": self.kind, "app": self.app, "model": self.model, "state": self.state,
                "started": int(self.started), "caller": self.caller, "did": self.did,
                "seconds": self.seconds(), "audio_seconds": round(self.audio_seconds, 2),
                "chars": self.chars, "prepare_ms": self.prepare_ms, "result": self.result or None, "dtmf": "".join(self.dtmf),
                "ended": int(self.ended) if self.ended else None,
                "intent": self.intent, "transcript": self.transcript}
