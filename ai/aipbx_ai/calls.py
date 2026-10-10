"""Live calls of AI applications (roadmap 5, AI applications).

The first application type is "announcement with values": a caller dials
the application's number, Asterisk answers and asks this service to prepare
the call, then connects the call's audio with AudioSocket(); the service
speaks the prepared text in real time and hangs up the socket, and the
dialplan continues to the application's destination.

The portal writes this dialplan for every application (the contract):

    [aipbx-ai-app-<id>]
    exten => s,1,Answer()
     same => n,Set(AI_CALL=${CURL(http://127.0.0.1:8790/v1/calls/start,app=<id>&key=<key>&...)})
     same => n,GotoIf($["${LEN(${AI_CALL})}" != "36"]?done)
     same => n,AudioSocket(${AI_CALL},127.0.0.1:8791)
     same => n(done),Goto(<destination>)

1. ``POST /v1/calls/start`` (application/x-www-form-urlencoded, as CURL()
   sends it) checks the dialplan key, renders the text (optionally filled
   from a JSON lookup), synthesises it at 8 kHz and answers ``200 text/plain``
   with only the call's UUID, or with an empty body when the call is refused
   (the reason goes to the journal). It never answers an error status: an
   empty AI_CALL simply skips the AI part of the call.
2. Asterisk connects to the AudioSocket server (127.0.0.1:8791), sends the
   UUID, and the service plays the audio in 20 ms frames paced in real time,
   then sends "terminate" (0x00) so AudioSocket() returns 0 and the dialplan
   continues. Audio and DTMF from the caller are parsed and kept in the
   session (later application types will listen).

The dialplan cannot read /etc/aipbx/ai.token, so /v1/calls/start does not
take the bearer token; ``key`` is the lowercase hex HMAC-SHA256 of
b"aipbx-dialplan" under the token (dialplan_key()), which the portal
computes from the same file.

AudioSocket protocol (https://docs.asterisk.org/Configuration/Channel-Drivers/AudioSocket/):
every message is 1 byte type, 2 bytes big-endian payload length, payload.
0x00 terminate, 0x01 UUID (16 raw bytes), 0x03 DTMF (1 ASCII byte), 0x10
audio (16-bit LE signed linear, 8 kHz mono; 0x11..0x18 are the same at 12,
16, 24, 32, 44.1, 48, 96 and 192 kHz), 0xff error. Asterisk 22's
AudioSocket() application forces the channel to slin (8 kHz), sends only
0x01, 0x03 and 0x10, and treats any message from us other than 0x10 or
0x00 as an error that hangs the channel up, so we send only those two.
It reads each header with a single read(), so every message goes out in
one sendall().
"""
import hashlib
import hmac
import json
import logging
import math
import re
import socket
import struct
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from collections import deque

from .models import NotReady, ServiceBusy, UnknownModel, WrongKind

log = logging.getLogger("aipbx_ai.calls")

DIALPLAN_KEY_MSG = b"aipbx-dialplan"

SAMPLE_RATE = 8000
FRAME_BYTES = 320            # 20 ms of 8 kHz 16-bit mono
FRAME_SECONDS = 0.02
LEAD_SECONDS = 0.06          # how far ahead of real time we may send
TAIL_SECONDS = 0.2           # after the last frame, before "terminate"

MAX_TEXT = 2000
MAX_FILLED = 4000            # rendered text (template plus lookup values)
MAX_VALUE = 200              # one lookup value
MAX_URL = 500
MAX_ID_FIELD = 40
MAX_UNIQUEID = 64
APP_MAX = 1000000
MAX_PER_APP_DEFAULT, MAX_PER_APP_LIMIT = 4, 50
MAX_LIVE = 100               # live calls of all applications together
SPEED_MIN, SPEED_MAX = 0.5, 2.0

LOOKUP_TIMEOUT = 2.0
LOOKUP_MAX_BYTES = 64 * 1024

SESSION_TTL = 30.0           # a prepared call nobody connects to expires
FINISHED_KEEP = 50
FINISHED_SECONDS = 600

MAX_CONNECTIONS = 100        # simultaneous AudioSocket connections
UUID_WAIT = 5.0              # seconds to wait for the UUID message

KIND_HANGUP, KIND_UUID, KIND_DTMF, KIND_AUDIO, KIND_ERROR = 0x00, 0x01, 0x03, 0x10, 0xFF
AUDIO_RATES = {0x10: 8000, 0x11: 12000, 0x12: 16000, 0x13: 24000, 0x14: 32000,
               0x15: 44100, 0x16: 48000, 0x17: 96000, 0x18: 192000}

PREPARED, PLAYING, DONE = "prepared", "playing", "done"

_PLACEHOLDER = re.compile(r"\{([A-Za-z0-9_]{1,40})\}")
_KEY = re.compile(r"^[A-Za-z0-9_]{1,40}$")
_ID_FIELD = re.compile(r"^[0-9+*#A-Za-z_.-]*$")
_UNIQUEID = re.compile(r"^[0-9A-Za-z_.-]*$")
_CONTROL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")
_FIELDS = ("app", "key", "model", "speed", "max", "caller", "did", "uniqueid", "text", "lookup", "fallback")


def dialplan_key(token):
    """The key the dialplan sends: hex HMAC-SHA256(token, b"aipbx-dialplan")."""
    token = token.encode() if isinstance(token, str) else bytes(token)
    return hmac.new(token, DIALPLAN_KEY_MSG, hashlib.sha256).hexdigest()


class Refused(Exception):
    """The call is not started; the message goes to the journal."""


# ---- text ---------------------------------------------------------------

def render(template, values):
    """Fills {name} placeholders; returns (text, missing) with missing ones removed."""
    missing = []

    def fill(m):
        name = m.group(1)
        if name in values:
            return values[name]
        missing.append(name)
        return ""

    text = _PLACEHOLDER.sub(fill, template)
    text = re.sub(r"[ \t]+([,.;:!?])", r"\1", text)   # "borcunuz , ..." after a removed value
    text = re.sub(r"\s+", " ", text).strip()
    return text, missing


def clean_values(data):
    """Flat {name: value} of a lookup answer: strings and numbers only."""
    values = {}
    for key, value in data.items():
        if not isinstance(key, str) or not _KEY.match(key):
            continue
        if isinstance(value, bool) or value is None:
            continue
        if isinstance(value, int):
            text = str(value)
        elif isinstance(value, float):
            if not math.isfinite(value):
                continue
            text = repr(value) if value != int(value) else str(int(value))
        elif isinstance(value, str):
            text = _CONTROL.sub(" ", value).strip()
        else:
            continue          # nested objects and lists are ignored
        values[key] = text[:MAX_VALUE]
    return values


def lookup_url(url, caller, did, app):
    parts = urllib.parse.urlsplit(url)
    query = [(k, v) for k, v in urllib.parse.parse_qsl(parts.query, keep_blank_values=True)
             if k not in ("caller", "did", "app")]
    query += [("caller", caller), ("did", did), ("app", str(app))]
    return urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path or "/",
                                    urllib.parse.urlencode(query), ""))


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


_OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect)


def fetch_values(url, timeout=LOOKUP_TIMEOUT):
    """GETs the lookup URL; returns the values or raises ValueError (hard time limit)."""
    result = {}

    def run():
        try:
            req = urllib.request.Request(url, headers={"Accept": "application/json",
                                                       "User-Agent": "aipbx-ai"})
            with _OPENER.open(req, timeout=timeout) as resp:
                if resp.status != 200:
                    raise ValueError(f"HTTP {resp.status}")
                raw = resp.read(LOOKUP_MAX_BYTES + 1)
            if len(raw) > LOOKUP_MAX_BYTES:
                raise ValueError("answer larger than 64 KB")
            data = json.loads(raw.decode("utf-8"))
            if not isinstance(data, dict):
                raise ValueError("answer is not a JSON object")
            result["values"] = clean_values(data)
        except urllib.error.HTTPError as e:
            result["error"] = f"HTTP {e.code}"
            e.close()
        except (UnicodeDecodeError, json.JSONDecodeError):
            result["error"] = "answer is not JSON"
        except Exception as e:  # noqa: BLE001 - any failure means "use the fallback"
            result["error"] = f"{type(e).__name__}: {e}"[:200]

    t = threading.Thread(target=run, name="call-lookup", daemon=True)
    t.start()
    t.join(timeout)
    if t.is_alive():
        raise ValueError("timed out")
    if "error" in result:
        raise ValueError(result["error"])
    return result.get("values", {})


# ---- form fields ----------------------------------------------------------

def parse_form(raw):
    """The urlencoded body as {name: value}; Refused on anything odd."""
    try:
        body = raw.decode("ascii")
    except UnicodeDecodeError:
        raise Refused("body is not URL-encoded") from None
    try:
        pairs = urllib.parse.parse_qsl(body, keep_blank_values=True, strict_parsing=False,
                                       encoding="utf-8", errors="strict", max_num_fields=30)
    except (ValueError, UnicodeDecodeError):
        raise Refused("invalid form body") from None
    form = {}
    for k, v in pairs:
        if k in form:
            raise Refused(f"field {k} given twice")
        form[k] = v
    return form


def _int_field(form, name, lo, hi, default=None):
    raw = form.get(name, "").strip()
    if raw == "" and default is not None:
        return default
    if not raw.isdigit() or len(raw) > 7:
        raise Refused(f"invalid {name}")
    value = int(raw)
    if not lo <= value <= hi:
        raise Refused(f"{name} out of range")
    return value


def _speed(form):
    raw = form.get("speed", "").strip()
    if raw == "":
        return 1.0
    if not re.match(r"^[0-9]{1,2}(\.[0-9]{1,4})?$", raw):
        raise Refused("invalid speed")
    value = float(raw)
    if not SPEED_MIN <= value <= SPEED_MAX:
        raise Refused("speed out of range")
    return value


def _id_field(form, name, pattern, limit):
    """Caller number, DID, uniqueid: an odd value is dropped, not a reason to refuse."""
    value = form.get(name, "").strip()
    if len(value) > limit or not pattern.match(value):
        log.info("call: ignoring invalid %s", name)
        return ""
    return value


def _text(form, name, required):
    value = form.get(name, "")
    value = _CONTROL.sub(" ", value).strip()
    if required and not value:
        raise Refused(f"{name} is empty")
    if len(value) > MAX_TEXT:
        raise Refused(f"{name} longer than {MAX_TEXT} characters")
    return value


def _lookup(form):
    url = form.get("lookup", "").strip()
    if not url:
        return ""
    if len(url) > MAX_URL or re.search(r"[\s\x00-\x1f\x7f]", url):
        raise Refused("invalid lookup URL")
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise Refused("lookup must be an http(s) URL")
    return url


# ---- sessions -------------------------------------------------------------

class CallSession:
    def __init__(self, app, model, caller, did, uniqueid):
        self.uuid = str(uuid.uuid4())
        self.app = app
        self.model = model
        self.caller = caller
        self.did = did
        self.uniqueid = uniqueid
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
        self.inbound_bytes = 0
        self.inbound_frames = 0
        self.inbound_rate = 0
        self.dtmf_event = threading.Event()

    def audio_in(self, kind, payload):
        self.inbound_bytes += len(payload)
        self.inbound_frames += 1
        self.inbound_rate = AUDIO_RATES[kind]

    def digit_in(self, digit):
        self.dtmf.append(digit)
        self.dtmf_event.set()

    def seconds(self):
        end = self.ended_mono if self.ended_mono is not None else time.monotonic()
        return round(end - self.created, 1)

    def describe(self):
        return {"uuid": self.uuid, "app": self.app, "model": self.model, "state": self.state,
                "started": int(self.started), "caller": self.caller, "did": self.did,
                "seconds": self.seconds(), "audio_seconds": round(self.audio_seconds, 2),
                "chars": self.chars, "prepare_ms": self.prepare_ms, "result": self.result or None, "dtmf": "".join(self.dtmf),
                "ended": int(self.ended) if self.ended else None}


class CallRegistry:
    """Prepared and live calls, the last finished ones, and /v1/calls/start."""

    def __init__(self, manager, token, ttl=SESSION_TTL, max_live=MAX_LIVE):
        self.manager = manager
        self.key = dialplan_key(token).encode()
        self.ttl = ttl
        self.max_live = max_live
        self._mu = threading.Lock()
        self._live = {}                # uuid -> CallSession (prepared or playing)
        self._finished = deque(maxlen=FINISHED_KEEP)
        self._stop = threading.Event()
        self._sweeper = threading.Thread(target=self._sweep_loop, name="call-sweeper", daemon=True)
        self._sweeper.start()

    def close(self):
        self._stop.set()

    # ---- start --------------------------------------------------------

    def start(self, raw_body):
        """Returns the new call's UUID, or "" when refused (reason in the journal)."""
        form = None
        try:
            form = parse_form(raw_body)
            given = form.get("key", "").strip().lower().encode("ascii", "replace")
            if not hmac.compare_digest(given, self.key):
                raise Refused("wrong dialplan key")
            return self._start(form)
        except Refused as e:
            app = (form or {}).get("app", "?")[:10]
            log.warning("call refused: app=%s reason=%s", app if app.isdigit() else "?", e)
            return ""
        except Exception:  # noqa: BLE001 - the dialplan only ever sees an empty answer
            log.exception("call refused: internal error")
            return ""

    def _start(self, form):
        unknown = set(form) - set(_FIELDS)
        if unknown:
            log.info("call: ignoring unknown fields %s", ",".join(sorted(unknown))[:100])
        app = _int_field(form, "app", 1, APP_MAX)
        limit = _int_field(form, "max", 1, MAX_PER_APP_LIMIT, MAX_PER_APP_DEFAULT)
        model = form.get("model", "").strip()
        if not re.match(r"^[a-z0-9][a-z0-9._-]{0,63}$", model):
            raise Refused("invalid model")
        speed = _speed(form)
        text = _text(form, "text", True)
        fallback = _text(form, "fallback", False)
        lookup = _lookup(form)
        caller = _id_field(form, "caller", _ID_FIELD, MAX_ID_FIELD)
        did = _id_field(form, "did", _ID_FIELD, MAX_ID_FIELD)
        uniqueid = _id_field(form, "uniqueid", _UNIQUEID, MAX_UNIQUEID)

        try:
            info = self.manager.describe(model)
        except UnknownModel:
            raise Refused(f"unknown model {model}") from None
        if info["kind"] != "tts":
            raise Refused(f"model {model} is not a text-to-speech model")
        if info["state"] != "ready":
            raise Refused(f"model {model} is not ready ({info['state']})")

        session = CallSession(app, model, caller, did, uniqueid)
        self._reserve(session, limit)
        try:
            session.chars, spoken = self._prepare_text(session, text, fallback, lookup)
            try:
                pcm = self.manager.synthesize(model, spoken, speed, SAMPLE_RATE)
            except NotReady:
                raise Refused(f"model {model} is not ready") from None
            except WrongKind:
                raise Refused(f"model {model} is not a text-to-speech model") from None
            except ServiceBusy:
                raise Refused("service busy: too many requests waiting for the model") from None
            except Exception as e:  # noqa: BLE001
                log.exception("call synthesis failed")
                raise Refused(f"synthesis failed: {type(e).__name__}") from None
            if not pcm or len(pcm) % 2:
                raise Refused("synthesis returned no audio")
            session.pcm = bytes(pcm)
            session.audio_seconds = len(pcm) / 2 / SAMPLE_RATE
            session.prepare_ms = int((time.monotonic() - session.created) * 1000)
        except BaseException:
            with self._mu:
                self._live.pop(session.uuid, None)
            raise
        return session.uuid

    def _reserve(self, session, limit):
        with self._mu:
            if len(self._live) >= self.max_live:
                raise Refused(f"service busy: {self.max_live} live calls")
            n = sum(1 for s in self._live.values() if s.app == session.app)
            if n >= limit:
                raise Refused(f"application has {n} live calls (max {limit})")
            self._live[session.uuid] = session

    def _prepare_text(self, session, text, fallback, lookup):
        values, failed = {}, False
        if lookup:
            url = lookup_url(lookup, session.caller, session.did, session.app)
            try:
                values = fetch_values(url)
                session.lookup = "ok"
            except ValueError as e:
                failed = True
                session.lookup = f"failed: {e}"
                log.info("call app=%d: lookup failed (%s)", session.app, e)
        spoken, missing = render(text, values)
        if (failed or missing) and fallback:
            spoken, _ = render(fallback, values)
        if not spoken:
            raise Refused("nothing to say (empty text)")
        if len(spoken) > MAX_FILLED:
            raise Refused(f"text with values is longer than {MAX_FILLED} characters")
        return len(spoken), spoken

    # ---- AudioSocket side -------------------------------------------

    def claim(self, call_uuid):
        """The session for a new AudioSocket connection (once), or None."""
        with self._mu:
            session = self._live.get(call_uuid)
            if session is None or session.state != PREPARED:
                return None
            session.state = PLAYING
            session.connected = time.monotonic()
            return session

    def finish(self, session, result):
        with self._mu:
            if session.state == DONE:
                return
            self._live.pop(session.uuid, None)
            session.state = DONE
            session.result = result
            session.ended = time.time()
            session.ended_mono = time.monotonic()
            session.pcm = b""          # free the audio
            self._finished.append(session)
        played = session.sent_bytes / 2 / SAMPLE_RATE
        wall = session.ended_mono - session.connected if session.connected else 0.0
        log.info("call app=%d caller=%s chars=%d audio=%.1fs prepared=%dms played=%.1fs in %.2fs "
                 "lookup=%s dtmf=%s result=%s",
                 session.app, session.caller or "-", session.chars, session.audio_seconds,
                 session.prepare_ms, played, wall, session.lookup or "-", "".join(session.dtmf) or "-", result)

    # ---- housekeeping and the live view -----------------------------

    def sweep(self):
        now = time.monotonic()
        with self._mu:
            expired = [s for s in self._live.values()
                       if s.state == PREPARED and now - s.created > self.ttl]
        for s in expired:
            self.finish(s, "expired")

    def _sweep_loop(self):
        while not self._stop.wait(1.0):
            try:
                self.sweep()
            except Exception:  # noqa: BLE001
                log.exception("call sweeper failed")

    def snapshot(self):
        self.sweep()
        cutoff = time.time() - FINISHED_SECONDS
        with self._mu:
            live = list(self._live.values())
            finished = [s for s in self._finished if s.ended and s.ended >= cutoff]
        per_app = {}
        for s in live:
            per_app[str(s.app)] = per_app.get(str(s.app), 0) + 1
        calls = [s.describe() for s in sorted(live, key=lambda s: s.created)]
        calls += [s.describe() for s in reversed(finished)]
        return {"calls": calls, "per_app": per_app}

    def live_count(self, app=None):
        with self._mu:
            return sum(1 for s in self._live.values() if app is None or s.app == app)


# ---- AudioSocket server ----------------------------------------------------

def message(kind, payload=b""):
    return struct.pack(">BH", kind, len(payload)) + payload


class _Reader:
    """Parses messages from the peer into the session, in its own thread."""

    def __init__(self, conn, session, buffered):
        self.conn = conn
        self.session = session
        self.buf = bytearray(buffered)
        self.gone = threading.Event()      # peer closed, sent 0x00 or an error
        self.reason = ""
        self.done = threading.Event()      # we are finished: stop reading
        self.thread = threading.Thread(target=self.run, name="call-reader", daemon=True)

    def run(self):
        try:
            while not self.done.is_set():
                if not self._drain():
                    return
                try:
                    chunk = self.conn.recv(65536)
                except socket.timeout:
                    continue
                except ConnectionError:      # reset: the peer closed with audio unread
                    self._gone("closed")
                    return
                except OSError:
                    self._gone("socket error")
                    return
                if not chunk:
                    self._gone("closed")
                    return
                self.buf += chunk
        except Exception:  # noqa: BLE001
            log.exception("AudioSocket reader failed")
            self._gone("reader error")

    def _gone(self, reason):
        if not self.gone.is_set():
            self.reason = reason
            self.gone.set()

    def _drain(self):
        """Handles every complete message in the buffer; False once the peer is gone."""
        while len(self.buf) >= 3:
            kind, length = struct.unpack_from(">BH", self.buf)
            if len(self.buf) < 3 + length:
                break
            payload = bytes(self.buf[3:3 + length])
            del self.buf[:3 + length]
            if kind == KIND_HANGUP:
                self._gone("hangup")
                return False
            if kind in AUDIO_RATES:
                self.session.audio_in(kind, payload)
            elif kind == KIND_DTMF:
                if length >= 1:
                    digit = chr(payload[0])
                    if digit in "0123456789*#ABCD":
                        self.session.digit_in(digit)
            elif kind == KIND_ERROR:
                log.warning("AudioSocket: error from Asterisk (code %s)", payload.hex() or "none")
                self._gone("error")
                return False
            # 0x01 again or unknown types: ignored
        return True


class AudioSocketServer:
    """TCP server for Asterisk's AudioSocket(); one thread per connection."""

    def __init__(self, address, registry, max_connections=MAX_CONNECTIONS):
        self.registry = registry
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(address)
        self.sock.listen(64)
        self.server_address = self.sock.getsockname()
        self._slots = threading.BoundedSemaphore(max_connections)
        self._stop = threading.Event()
        self._thread = None

    def start(self):
        self._thread = threading.Thread(target=self.serve_forever, name="audiosocket", daemon=True)
        self._thread.start()
        return self

    def serve_forever(self):
        while not self._stop.is_set():
            try:
                conn, _ = self.sock.accept()
            except OSError:
                if self._stop.is_set():
                    return
                time.sleep(0.05)
                continue
            if not self._slots.acquire(blocking=False):
                log.warning("AudioSocket: too many connections, closing one")
                _close(conn)
                continue
            try:
                threading.Thread(target=self._serve, args=(conn,), name="audiosocket-call", daemon=True).start()
            except Exception:  # noqa: BLE001
                self._slots.release()
                _close(conn)

    def shutdown(self):
        self._stop.set()
        try:
            self.sock.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        self.sock.close()

    def _serve(self, conn):
        try:
            self._handle(conn)
        except Exception:  # noqa: BLE001 - one bad connection never stops the service
            log.exception("AudioSocket connection failed")
        finally:
            _close(conn)
            self._slots.release()

    @staticmethod
    def _read_uuid(conn):
        """Reads the first message; returns (uuid string, bytes read after it) or (None, b"")."""
        conn.settimeout(UUID_WAIT)
        deadline = time.monotonic() + UUID_WAIT
        buf = b""
        while len(buf) < 19 and time.monotonic() < deadline:
            try:
                chunk = conn.recv(4096)
            except (socket.timeout, OSError):
                return None, b""
            if not chunk:
                return None, b""
            buf += chunk
            if len(buf) >= 3 and (buf[0] != KIND_UUID or struct.unpack_from(">H", buf, 1)[0] != 16):
                return None, b""
        if len(buf) < 19:
            return None, b""
        return str(uuid.UUID(bytes=buf[3:19])), buf[19:]

    def _handle(self, conn):
        conn.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        call_uuid, rest = self._read_uuid(conn)
        if call_uuid is None:
            log.warning("AudioSocket: no UUID message, closing")
            return
        session = self.registry.claim(call_uuid)
        if session is None:
            log.warning("AudioSocket: unknown or used call %s, closing", call_uuid)
            return
        conn.settimeout(0.5)
        reader = _Reader(conn, session, rest)
        reader.thread.start()
        result = "error"
        try:
            result = self._play(conn, session, reader)
        finally:
            reader.done.set()
            reader.thread.join(2.0)
            self.registry.finish(session, result)

    def _play(self, conn, session, reader):
        pcm = session.pcm
        frames = (len(pcm) + FRAME_BYTES - 1) // FRAME_BYTES
        t0 = time.monotonic()
        try:
            for i in range(frames):
                wait = t0 + i * FRAME_SECONDS - LEAD_SECONDS - time.monotonic()
                if wait > 0 and reader.gone.wait(wait):
                    break
                if reader.gone.is_set():
                    break
                chunk = pcm[i * FRAME_BYTES:(i + 1) * FRAME_BYTES]
                conn.sendall(message(KIND_AUDIO, chunk))
                session.sent_bytes += len(chunk)
            if reader.gone.is_set():
                return "hangup" if reader.reason in ("hangup", "closed") else "error"
            # Let the last frames play out, then hang up the socket: the
            # dialplan continues after AudioSocket().
            wait = t0 + frames * FRAME_SECONDS + TAIL_SECONDS - time.monotonic()
            if wait > 0 and reader.gone.wait(wait):
                return "played"         # the caller hung up during the tail
            conn.sendall(message(KIND_HANGUP))
            reader.gone.wait(1.0)       # Asterisk closes the socket on 0x00
            return "played"
        except OSError:
            return "hangup" if session.sent_bytes else "error"


def _close(conn):
    try:
        conn.shutdown(socket.SHUT_RDWR)
    except OSError:
        pass
    try:
        conn.close()
    except OSError:
        pass
