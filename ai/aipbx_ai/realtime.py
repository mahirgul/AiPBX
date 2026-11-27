"""OpenAI Realtime (speech-to-speech) WebSocket bridge for live AudioSocket calls.

Stdlib-only (no external websocket/audioop dependencies): converts 8 kHz 16-bit
signed LE PCM frames <-> G.711 mu-law (g711_ulaw) natively supported by the
OpenAI Realtime API at 8 kHz, streams audio in both directions, handles
barge-in (input_audio_buffer.speech_started -> truncate), and executes
function-calling tools (transfer_call, end_call, lookup).
"""
from __future__ import annotations

import base64
import copy
import json
import logging
import os
import queue
import re
import select
import socket
import ssl
import struct
import threading
import time
import urllib.parse
from typing import Any

from . import providers, secret_refs
from .conversation import LANG_NAMES, source_lines
from .ws_reader import WebSocketReader

log = logging.getLogger("aipbx_ai.realtime")

_FRAME_BYTES = 320  # 20 ms at 8 kHz 16-bit mono = 160 samples = 320 bytes
_SILENCE_PCM = b"\x00" * _FRAME_BYTES
SILENCE_NUDGE_SECONDS = 8.0   # caller quiet this long after the model finished: tell the model
CLOSING_TURN_TIMEOUT = 6.0    # max wait for the model's parting sentence after transfer_call / end_call
PLAYBACK_DRAIN_TIMEOUT = 8.0  # max wait to play queued closing audio before AudioSocket hangs up
_SPEECH_RMS = 250             # as voice_requests.MIN_SPEECH_RMS: a frame this loud is the caller talking
_BIAS = 0x84
_CLIP = 32635


def _linear_to_ulaw_sample(sample: int) -> int:
    sign = (sample >> 8) & 0x80
    if sign:
        sample = -sample
    if sample > _CLIP:
        sample = _CLIP
    sample += _BIAS
    exponent = 7
    mask = 0x4000
    while exponent > 0 and not (sample & mask):
        exponent -= 1
        mask >>= 1
    mantissa = (sample >> (exponent + 3)) & 0x0F
    return (~(sign | (exponent << 4) | mantissa)) & 0xFF


def _ulaw_to_linear_sample(u: int) -> int:
    u = (~u) & 0xFF
    sign = u & 0x80
    exponent = (u >> 4) & 0x07
    mantissa = u & 0x0F
    sample = ((mantissa << 3) + _BIAS) << exponent
    sample -= _BIAS
    return -sample if sign else sample


_ULAW_DECODE = [_ulaw_to_linear_sample(i) for i in range(256)]


def _audio_stats(pcm: bytes) -> tuple[float, bool]:
    """Returns (RMS level, whether any sample is near full-scale clipping)."""
    n = len(pcm) // 2
    if not n:
        return 0.0, False
    samples = struct.unpack(f"<{n}h", pcm[: n * 2])
    rms = (sum(x * x for x in samples) / n) ** 0.5
    clipped = any(x >= 32700 or x <= -32700 for x in samples)
    return rms, clipped


def _rms(pcm: bytes) -> float:
    return _audio_stats(pcm)[0]


def pcm16_to_ulaw(pcm: bytes) -> bytes:
    """Converts 16-bit signed LE PCM bytes to 8-bit G.711 mu-law bytes."""
    n = len(pcm) // 2
    if n <= 0:
        return b""
    samples = struct.unpack(f"<{n}h", pcm[: n * 2])
    return bytes(_linear_to_ulaw_sample(s) for s in samples)


def ulaw_to_pcm16(ulaw: bytes) -> bytes:
    """Converts 8-bit G.711 mu-law bytes to 16-bit signed LE PCM bytes."""
    if not ulaw:
        return b""
    return struct.pack(f"<{len(ulaw)}h", *(_ULAW_DECODE[b] for b in ulaw))


def resample_pcm16(pcm: bytes, src_rate: int, dst_rate: int) -> bytes:
    """Resamples 16-bit signed LE mono PCM between 8000, 16000, and 24000 Hz."""
    if not pcm or src_rate == dst_rate or src_rate <= 0 or dst_rate <= 0:
        return pcm
    n = len(pcm) // 2
    if n <= 0:
        return b""
    samples = struct.unpack(f"<{n}h", pcm[: n * 2])
    if src_rate == 8000 and dst_rate == 16000:
        out: list[int] = []
        for i, s in enumerate(samples):
            nxt = samples[i + 1] if i + 1 < n else s
            out.append(s)
            out.append((s + nxt) // 2)
        return struct.pack(f"<{len(out)}h", *out)
    if src_rate == 24000 and dst_rate == 8000:
        usable = (n // 3) * 3
        if usable:
            out = [(samples[i] + samples[i + 1] + samples[i + 2]) // 3 for i in range(0, usable, 3)]
        else:
            out = [sum(samples) // n]
        return struct.pack(f"<{len(out)}h", *out)
    out_len = max(1, int(n * dst_rate / src_rate))
    out = [samples[min(n - 1, int(i * src_rate / dst_rate))] for i in range(out_len)]
    return struct.pack(f"<{len(out)}h", *out)


class _Upsampler8to16:
    """Stateful 8 kHz -> 16 kHz Catmull-Rom PCM16 upsampler across chunk boundaries."""

    def __init__(self) -> None:
        self._prev_before_held: int | None = None
        self._held: int | None = None

    def process(self, pcm: bytes) -> bytes:
        n = len(pcm) // 2
        if n <= 0:
            return b""
        incoming = list(struct.unpack(f"<{n}h", pcm[: n * 2]))
        if self._held is None:
            if n == 1:
                self._held = incoming[0]
                return b""
            seq = incoming
        else:
            seq = [self._held] + incoming
        m = len(seq)
        out: list[int] = []
        for i in range(m - 1):
            s1 = seq[i]
            s2 = seq[i + 1]
            s0 = (self._prev_before_held if self._prev_before_held is not None else (2 * s1 - s2)) if i == 0 else seq[i - 1]
            s3 = seq[i + 2] if i + 2 < m else (2 * s2 - s1)
            mid = (-s0 + 9 * s1 + 9 * s2 - s3 + 8) >> 4
            if mid > 32767:
                mid = 32767
            elif mid < -32768:
                mid = -32768
            out.append(s1)
            out.append(mid)
        self._prev_before_held = seq[-2]
        self._held = seq[-1]
        return struct.pack(f"<{len(out)}h", *out)


class _Downsampler24to8:
    """Stateful 24 kHz -> 8 kHz PCM16 downsampler with 5-tap low-pass anti-aliasing."""

    def __init__(self) -> None:
        self._rem: list[int] = []
        self._prev1: int | None = None

    def process(self, pcm: bytes) -> bytes:
        n = len(pcm) // 2
        if n <= 0:
            return b""
        samples = self._rem + list(struct.unpack(f"<{n}h", pcm[: n * 2]))
        total = len(samples)
        usable = (total // 3) * 3
        if not usable:
            self._rem = samples
            return b""
        self._rem = samples[usable:]
        out: list[int] = []
        right_edge = samples[usable] if self._rem else samples[usable - 1]
        for i in range(0, usable, 3):
            c = i + 1
            s_m2 = self._prev1 if c < 2 and self._prev1 is not None else samples[max(0, c - 2)]
            s_m1 = samples[c - 1]
            s_0 = samples[c]
            s_p1 = samples[c + 1]
            s_p2 = right_edge if c + 2 >= usable else samples[c + 2]
            val = (s_m2 + 2 * s_m1 + 3 * s_0 + 2 * s_p1 + s_p2 + 4) // 9
            out.append(max(-32768, min(32767, val)))
        self._prev1 = samples[usable - 1]
        return struct.pack(f"<{len(out)}h", *out)


_GEMINI_VOICES = {
    "alloy": "Puck",
    "ash": "Charon",
    "ballad": "Aoede",
    "coral": "Kore",
    "echo": "Fenrir",
    "sage": "Kore",
    "shimmer": "Aoede",
    "verse": "Puck",
}


def _uses_openai_ga(rc: dict[str, Any]) -> bool:
    provider = rc.get("provider") or "openai"
    return provider == "openai" or (provider == "openai_compat" and rc.get("protocol") == "openai_ga")


def _resolve_ws_endpoint(rc: dict[str, Any]) -> tuple[str, dict[str, str]]:
    """Resolves WebSocket URL and HTTP upgrade headers for the configured realtime provider."""
    provider = str(rc.get("provider") or "openai")
    api_key = str(rc.get("api_key") or "")
    ws_override = str(rc.get("ws_url") or "")

    if provider == "google_ai":
        ws_url = ws_override or (
            "wss://generativelanguage.googleapis.com/ws/"
            "google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent"
            f"?key={urllib.parse.quote(api_key)}"
        )
        return ws_url, {}

    if provider == "qwen":
        model = str(rc.get("model") or providers.realtime_model("qwen"))
        base = str(rc.get("base_url") or "")
        host = "dashscope.aliyuncs.com" if "dashscope.aliyuncs.com" in base else "dashscope-intl.aliyuncs.com"
        ws_url = ws_override or f"wss://{host}/api-ws/v1/realtime?model={urllib.parse.quote(model)}"
        return ws_url, {"Authorization": f"Bearer {api_key}", "OpenAI-Beta": "realtime=v1"}

    if provider == "openai_compat":
        ga = _uses_openai_ga(rc)
        model = str(rc.get("model") or (providers.realtime_model("openai") if ga else "gpt-4o-realtime-preview"))
        base = str(rc.get("base_url") or "").rstrip("/")
        if base.startswith("https://"):
            ws_base = "wss://" + base[8:]
        elif base.startswith("http://"):
            ws_base = "ws://" + base[7:]
        else:
            ws_base = base
        endpoint = ws_override or (f"{ws_base}/realtime" if ws_base else "")
        ws_url = f"{endpoint}?{urllib.parse.urlencode({'model': model})}" if endpoint and "?" not in endpoint else endpoint
        headers: dict[str, str] = {} if ga else {"OpenAI-Beta": "realtime=v1"}
        if api_key:
            headers["Authorization"] = f"Bearer {api_key}"
        return ws_url, headers

    model = str(rc.get("model") or providers.realtime_model("openai"))
    # Persisted app configs can still contain the retired native preview models.
    if model.startswith(("gpt-4o-realtime-preview", "gpt-4o-mini-realtime-preview")):
        model = providers.realtime_model("openai")
    ws_url = ws_override or f"wss://api.openai.com/v1/realtime?model={urllib.parse.quote(model)}"
    return ws_url, {"Authorization": f"Bearer {api_key}"}


def _ws_frame(opcode: int, payload: bytes) -> bytes:
    n = len(payload)
    header = bytearray([0x80 | (opcode & 0x0F)])
    if n < 126:
        header.append(0x80 | n)
    elif n < 65536:
        header.append(0x80 | 126)
        header.extend(struct.pack("!H", n))
    else:
        header.append(0x80 | 127)
        header.extend(struct.pack("!Q", n))
    mask = os.urandom(4)
    header.extend(mask)
    masked = bytes(b ^ mask[i & 3] for i, b in enumerate(payload))
    return bytes(header) + masked


class _LockedSocket:
    """Serializes SSL/TCP recv and sendall across threads without holding the lock while waiting."""

    def __init__(self, sock: socket.socket) -> None:
        self._sock = sock
        self._lock = threading.Lock()

    def recv(self, size: int) -> bytes:
        timeout = self._sock.gettimeout()
        has_pending = isinstance(self._sock, ssl.SSLSocket) and self._sock.pending() > 0
        if not has_pending:
            r, _, _ = select.select([self._sock], [], [], timeout)
            if not r:
                raise socket.timeout("timed out")
        with self._lock:
            return self._sock.recv(size)

    def sendall(self, data: bytes) -> None:
        with self._lock:
            self._sock.sendall(data)

    def close(self) -> None:
        self._sock.close()


class RealtimeSocket:
    """Minimal stdlib RFC 6455 WebSocket client for OpenAI Realtime."""

    def __init__(self, url: str, headers: dict[str, str], timeout: float = 8.0) -> None:
        parsed = urllib.parse.urlparse(url)
        use_tls = parsed.scheme == "wss"
        host = parsed.hostname or "api.openai.com"
        port = parsed.port or (443 if use_tls else 80)
        path = parsed.path or "/v1/realtime"
        if parsed.query:
            path += "?" + parsed.query

        raw = socket.create_connection((host, port), timeout=timeout)
        if use_tls:
            ctx = ssl.create_default_context()
            raw_sock: socket.socket = ctx.wrap_socket(raw, server_hostname=host)
        else:
            raw_sock = raw

        key = base64.b64encode(os.urandom(16)).decode("ascii")
        lines = [
            f"GET {path} HTTP/1.1",
            f"Host: {host}:{port}",
            "Upgrade: websocket",
            "Connection: Upgrade",
            f"Sec-WebSocket-Key: {key}",
            "Sec-WebSocket-Version: 13",
        ]
        for k, v in headers.items():
            lines.append(f"{k}: {v}")
        raw_sock.sendall(("\r\n".join(lines) + "\r\n\r\n").encode("utf-8"))

        self._io = _LockedSocket(raw_sock)
        self.sock: socket.socket = raw_sock
        self.reader = WebSocketReader(self._io)
        self.close_code: int | None = None
        self.close_reason: str = ""
        try:
            self.reader.read_upgrade(key)
        except (ConnectionError, OSError):
            self.sock.close()
            raise
        self.sock.settimeout(0.2)

    def send_json(self, obj: dict[str, Any]) -> None:
        data = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self._io.sendall(_ws_frame(0x1, data))

    def recv_json(self) -> dict[str, Any] | None:
        opcode, payload = self.reader.read_frame()
        if opcode == 0x8:
            if len(payload) >= 2:
                self.close_code = struct.unpack("!H", payload[:2])[0]
                self.close_reason = payload[2:].decode("utf-8", errors="replace")[:200]
            if self.close_code not in (None, 1000, 1001):
                log.warning("Realtime WebSocket closed code=%s reason=%s", self.close_code, self.close_reason or "-")
            return None
        if opcode == 0x9:
            self._io.sendall(_ws_frame(0xA, payload))
            return {}
        if opcode in (0x1, 0x2) and payload:
            parsed = json.loads(payload.decode("utf-8"))
            return parsed if isinstance(parsed, dict) else {}
        return {}

    def close(self) -> None:
        try:
            self._io.sendall(_ws_frame(0x8, b""))
        except OSError:
            pass
        try:
            self._io.close()
        except OSError:
            pass


GEMINI_DEFAULT_MODEL = providers.realtime_model("google_ai")


class RealtimeCall:
    """Bridges an AudioSocket VoiceRequestsCall with an OpenAI Realtime session."""

    def __init__(self, call: Any) -> None:
        self.call = call
        self.cfg = call.config
        self.rc = self.cfg.get("realtime_cloud") or {}
        self._out_q: queue.Queue[bytes] = queue.Queue(maxsize=400)
        self._dtmf_q: queue.Queue[str] = queue.Queue()
        self._collecting_dtmf = False
        self._stop = threading.Event()
        self._closing = threading.Event()
        self._upstream_ready = threading.Event()
        self._audio_played_ms = 0
        self._last_item_id = ""
        self._transcript_lines: list[str] = []
        self._in_tr: list[str] = []
        self._out_tr: list[str] = []
        self._saw_out_tr_turn = False
        self._spoke_in_turn = False
        self._last_activity = time.monotonic()   # caller speech, model audio or a tool: restarts the silence clock
        self._last_frame = 0.0                   # last audio frame from the line
        self._nudges = 0
        self._gave_up = False
        self._speech_stopped = None     # monotonic: the provider heard the caller stop (OpenAI protocol)
        self._in_frames = 0
        self._in_speech_frames = 0
        self._in_rms_sum = 0.0
        self._in_rms_max = 0.0
        self._in_clipped_frames = 0
        self._out_audio_frames = 0

    def _lang_and_name(self) -> tuple[str, str]:
        session = self.call.session
        lang = str(
            getattr(session, "lang", "")
            or self.rc.get("lang")
            or self.cfg.get("stt_lang")
            or self.cfg.get("language")
            or "tr"
        )
        lang_name = str(
            getattr(session, "lang_name", "")
            or self.cfg.get("language_name")
            or LANG_NAMES.get(lang, lang)
        )
        return lang, lang_name

    def _lang_locale(self, lang: str) -> str:
        loc = str(getattr(self.call.session, "lang_locale", "") or "")
        if loc:
            return loc
        langs_map = self.cfg.get("languages")
        if isinstance(langs_map, dict) and isinstance(langs_map.get(lang), dict):
            loc = str(langs_map[lang].get("locale") or "")
            if loc:
                return loc
        if lang == str(self.cfg.get("language") or "") and self.cfg.get("language_locale"):
            return str(self.cfg["language_locale"])
        return {"tr": "tr-TR", "en": "en-US", "de": "de-DE"}.get(lang, "")

    def _record_transcript(self, role: str, text: str) -> None:
        cleaned = str(text or "").strip()
        if not cleaned:
            return
        prefix = "Caller" if role == "caller" else "Assistant"
        self._transcript_lines.append(f"{prefix}: {cleaned}")
        hist = getattr(self.call, "history", None)
        if isinstance(hist, list):
            hist.append((role, cleaned))
        if role == "caller":
            hear = getattr(self.call.session, "hear", None)
            if callable(hear):
                hear(cleaned)

    def _flush_transcript(self, role: str) -> None:
        buf = self._in_tr if role == "caller" else self._out_tr
        if not buf:
            return
        text = "".join(buf)
        buf.clear()
        self._record_transcript(role, text)

    def _build_tools(self) -> list[dict[str, Any]]:
        tools: list[dict[str, Any]] = [
            {
                "type": "function",
                "name": "end_call",
                "description": "End the phone call when the caller has nothing more to ask. Always say a brief polite goodbye.",
                "parameters": {
                    "type": "object",
                    "properties": {"summary": {"type": "string", "description": "One sentence: what the call was about "
                                                                               "and how it ended"}},
                    "required": ["summary"],
                },
            }
        ]
        intents = [str(i.get("id") or "") for i in (self.cfg.get("intents") or []) if i.get("id")]
        if intents:
            tools.append({
                "type": "function",
                "name": "transfer_call",
                "description": (
                    "Transfer the caller to one of the company's listed targets; your part of the call ends right after "
                    "your closing sentence. Only transfer to a target listed below that matches what the caller wants. "
                    "Always tell the caller briefly in one polite sentence that you are transferring them. "
                    "If the caller asks for a department, person, or action that is NOT in the target list, do NOT call "
                    "transfer_call—politely say you cannot perform that transfer right now. Targets: "
                    + "; ".join(f'{i["id"]}: {i.get("name") or i["id"]}'
                                for i in (self.cfg.get("intents") or []) if i.get("id"))
                ),
                "parameters": {
                    "type": "object",
                    "properties": {
                        "target": {"type": "string", "enum": intents},
                        "summary": {"type": "string", "description": "One sentence for whoever takes the call: "
                                                                     "who calls, what they want, what you found"},
                    },
                    "required": ["target"],
                },
            })
        if self.cfg.get("db_queries"):
            q_list = [q for q in (self.cfg.get("db_queries") or []) if isinstance(q, dict) and q.get("id")]
            if q_list:
                q_ids = [str(q["id"]) for q in q_list]
                q_desc = "; ".join(
                    f"{q['id']}: {q.get('description') or ''} (params: {', '.join(q.get('params') or []) or 'none'})"
                    for q in q_list
                )
                tools.append({
                    "type": "function",
                    "name": "get_db_value",
                    "description": f"Run one registered database query. Queries: {q_desc}",
                    "parameters": {
                        "type": "object",
                        "properties": {
                            "query_id": {"type": "string", "enum": q_ids},
                            "params": {"type": "object", "description": "Required string parameters for the query, if any"},
                        },
                        "required": ["query_id"],
                    },
                })
        if self.cfg.get("tools") or self.cfg.get("knowledge"):
            lookups = [(str(t.get("name") or ""), str(t.get("description") or "")) for t in (self.cfg.get("tools") or [])
                       if isinstance(t, dict) and t.get("name")]
            if self.cfg.get("knowledge"):
                lookups.append(("knowledge", "the company's own documents (policies, prices, opening hours, products)"))
            properties: dict[str, Any] = {
                "tool": {"type": "string", "description": "Which lookup to run",
                         **({"enum": [name for name, _ in lookups]} if lookups else {})},
                "query": {"type": "string", "description": "What to look up, in the caller's own words and language"},
            }
            description = ("Look something up in the company's own sources and get text back to answer from. "
                           "It returns only what the source says; when nothing relevant comes back, say so "
                           "instead of guessing. Lookups: " + "; ".join(f"{n}: {d}" for n, d in lookups))
            sources = self.cfg.get("knowledge_sources") or []
            if self.cfg.get("knowledge") and sources:
                properties["source"] = {"type": "integer", "enum": [s["id"] for s in sources],
                                        "description": "Knowledge source for tool \"knowledge\""}
                description += " Knowledge sources (set \"source\" when tool is \"knowledge\"): " \
                    + "; ".join(source_lines(self.cfg))
            tools.append({
                "type": "function",
                "name": "lookup",
                "description": description,
                "parameters": {"type": "object", "properties": properties, "required": ["query"]},
            })
        pbx_tools = self.cfg.get("pbx_tools") or []
        if pbx_tools:
            from .pbx_tools import as_openai_tools
            existing = {t["name"] for t in tools}
            for pt in as_openai_tools(pbx_tools):
                if pt["name"] not in existing:
                    tools.append(pt)
        return tools

    def _instructions(self) -> str:
        """The company instructions first (the same text on every call, so a provider can cache it),
        then what is known about this call."""
        from .conversation import local_now, render_instructions

        parts: list[str] = []
        session = self.call.session
        lang, lang_name = self._lang_and_name()
        cust_id = getattr(session, "verified_customer", "")
        if self.cfg.get("instructions"):
            ctx_cfg = {
                **self.cfg,
                "caller": getattr(session, "caller", ""),
                "caller_name": getattr(session, "caller_name", ""),
                "verified_customer": cust_id,
                "did": getattr(session, "did", ""),
                "language_name": lang_name,
            }
            parts.append(render_instructions(str(self.cfg["instructions"]), ctx_cfg, lang))
        if self.cfg.get("db_queries"):
            parts.append("For customer, invoice, or service records, use `get_db_value` with exact string params (if params list is none, pass {}).")
        parts.append(
            f"Speak {lang_name} (language code '{lang}'), concisely, as suits a phone call. "
            f"CRITICAL LANGUAGE RULE: Always speak and reply ONLY in {lang_name} ({lang}). "
            "Never switch to another language, never say words in another language, and never ask "
            f"the caller to speak {lang_name} or claim they are speaking a foreign language. "
            f"The caller is speaking {lang_name} on an 8 kHz telephone line; interpret faint, "
            f"accented, or noisy speech as {lang_name}. If a word is genuinely unclear, ask them "
            f"briefly in {lang_name} to repeat it."
        )
        parts.append(
            f"TRANSFER & UNAVAILABLE RULES (speak ONLY in {lang_name}): "
            "1) Whenever you call `transfer_call`, always tell the caller briefly and politely in one short sentence "
            "that you are transferring them now, then stop. "
            "2) Whenever you call `end_call`, always say a brief polite goodbye in one short sentence, then stop. "
            "3) If a requested department or transfer target is not in your available tools, or if a database, "
            "knowledge, or HTTP lookup returns no matching record or fails, NEVER guess or invent information and "
            "NEVER end the call silently—politely apologize and inform the caller that you cannot find that "
            "information or complete that request right now, then ask if there is anything else you can help with."
        )
        now = local_now(str(self.cfg.get("timezone") or ""))
        tz_label = now.tzname() or ""
        utc_off = now.strftime("%z")
        offset_str = f"UTC{utc_off[:3]}:{utc_off[3:]}" if len(utc_off) == 5 else ""
        tz_info = ", ".join(x for x in (tz_label, offset_str) if x)
        parts.append(
            f"Current local date and time: {now.strftime('%Y-%m-%d %H:%M')}"
            + (f" ({tz_info})" if tz_info else "")
            + ". Always use this exact local date and time (never UTC) when answering time or date questions."
        )
        if session.caller_name:
            parts.append(f"Caller name: {session.caller_name}")
        if cust_id:
            parts.append(f"System-resolved Customer ID: {cust_id}")
        if self.cfg.get("greeting"):
            from .voice_requests import AppStore
            greeting = AppStore.greeting_text(
                {"greeting": getattr(session, "greeting", "") or self.cfg["greeting"]},
                getattr(session, "caller_name", ""),
                getattr(session, "caller", ""),
                getattr(session, "did", ""),
            )
            if greeting:
                parts.append(f"Start by greeting the caller: {greeting}")
        return "\n".join(parts)

    def _clear_playback(self) -> None:
        while not self._out_q.empty():
            try:
                self._out_q.get_nowait()
            except queue.Empty:
                break

    def _enqueue_pcm(self, pcm: bytes) -> None:
        for i in range(0, len(pcm), _FRAME_BYTES):
            chunk = pcm[i : i + _FRAME_BYTES]
            if len(chunk) < _FRAME_BYTES:
                chunk = chunk + b"\x00" * (_FRAME_BYTES - len(chunk))
            try:
                self._out_q.put_nowait(chunk)
            except queue.Full:
                try:
                    self._out_q.get_nowait()
                    self._out_q.put_nowait(chunk)
                except queue.Empty:
                    pass

    def _pump_playback(self) -> None:
        next_tick = time.monotonic()
        while not self._stop.is_set() and not self.call.gone():
            try:
                frame = self._out_q.get_nowait()
                self._out_audio_frames += 1
                self._audio_played_ms += 20
                self._last_activity = time.monotonic()
                is_silence = False
            except queue.Empty:
                frame = _SILENCE_PCM
                is_silence = True
            try:
                self.call._send_pcm(frame)
                if is_silence and isinstance(getattr(self.call.session, "sent_bytes", None), int):
                    self.call.session.sent_bytes = max(0, self.call.session.sent_bytes - len(frame))
            except OSError:
                self._stop.set()
                break
            next_tick += 0.020
            sleep_for = next_tick - time.monotonic()
            if sleep_for > 0:
                time.sleep(sleep_for)
            else:
                next_tick = time.monotonic()

    def _drain_playback(self, timeout: float = PLAYBACK_DRAIN_TIMEOUT) -> None:
        """Waits for queued closing audio to finish playing before tearing down the call."""
        had_audio = not self._out_q.empty() or self._out_audio_frames > 0
        deadline = time.monotonic() + timeout
        while not self._out_q.empty() and not self._stop.is_set() and not self.call.gone():
            if time.monotonic() >= deadline:
                break
            time.sleep(0.02)
        if had_audio and not self._stop.is_set() and not self.call.gone():
            end = min(deadline, time.monotonic() + 0.14)
            while time.monotonic() < end and not self._stop.is_set() and not self.call.gone():
                time.sleep(0.02)

    def _wait_upstream_ready(self) -> bool:
        while not self._stop.is_set() and not self.call.gone():
            if self._upstream_ready.wait(0.05):
                return True
        return False

    def _heard(self, pcm: bytes) -> None:
        """Notes an inbound audio chunk for the silence clock and audio stats."""
        now = time.monotonic()
        self._last_frame = now
        if not pcm:
            return
        rms, clipped = _audio_stats(pcm)
        self._in_frames += 1
        self._in_rms_sum += rms
        if rms > self._in_rms_max:
            self._in_rms_max = rms
        if clipped:
            self._in_clipped_frames += 1
        if rms >= _SPEECH_RMS:
            self._in_speech_frames += 1
            self._last_activity = now
            self._nudges = 0                      # the caller is back: the silences are counted afresh

    def _nudge_seconds(self) -> float:
        """Twice the listening silence (default 4 s -> 8 s): server VAD and a caller who thinks need longer."""
        try:
            return max(SILENCE_NUDGE_SECONDS, 2.0 * float(self.cfg.get("silence_seconds") or 0))
        except (TypeError, ValueError):
            return SILENCE_NUDGE_SECONDS

    def _silence_note(self) -> str | None:
        """The note for the model when the caller has been silent too long; None otherwise.
        Past `retries` notes it stops the session: the call goes to its not-understood destination."""
        now = time.monotonic()
        wait = self._nudge_seconds()
        if self._closing.is_set() or not self._out_q.empty() or now - self._last_activity < wait:
            return None
        self._last_activity = now
        self._nudges += 1
        limit = int(self.cfg.get("retries", 2)) + 1
        line = "no sound at all came from the caller's line; they may not hear you either" \
            if now - self._last_frame > wait else "the caller said nothing"
        note = getattr(self.call.session, "note", None)
        if note:
            note(f"caller turn: {'no_audio' if 'no sound' in line else 'silence'} ({self._nudges} of {limit})")
        if self._nudges >= limit:
            self._gave_up = True
            self._stop.set()
            return None
        _, lang_name = self._lang_and_name()
        return (f"[Phone line note, not the caller: {line} for {int(wait)} seconds "
                f"(time {self._nudges}; the call leaves you after {limit}). In one short sentence, ask again: "
                f"repeat your last question in fewer words, or ask whether they can hear you. Reply ONLY in {lang_name}.]")

    def _pump_upstream(self, ws: RealtimeSocket, in_q: queue.Queue[Any]) -> None:
        if not self._wait_upstream_ready():
            return
        while not self._stop.is_set() and not self._closing.is_set() and not self.call.gone():
            note = self._silence_note()
            if note:
                try:
                    ws.send_json({"type": "conversation.item.create", "item": {
                        "type": "message", "role": "user", "content": [{"type": "input_text", "text": note}]}})
                    ws.send_json({"type": "response.create"})
                except OSError:
                    self._stop.set()
                    break
            try:
                item = in_q.get(timeout=0.1)
            except queue.Empty:
                continue
            if isinstance(item, tuple):
                if len(item) >= 3 and item[0] == "dtmf":
                    digit = str(item[2])
                    self._last_activity = time.monotonic()
                    self._nudges = 0
                    self._dtmf_q.put(digit)
                    if hasattr(self.call.session, "dtmf") and (
                        not self.call.session.dtmf or self.call.session.dtmf[-1] != digit
                    ):
                        self.call.session.dtmf.append(digit)
                    if not self._collecting_dtmf:
                        try:
                            ws.send_json({
                                "type": "conversation.item.create",
                                "item": {
                                    "type": "message",
                                    "role": "user",
                                    "content": [{"type": "input_text", "text": f"[Caller pressed DTMF: {digit}]"}],
                                },
                            })
                            ws.send_json({"type": "response.create"})
                        except OSError:
                            self._stop.set()
                            break
                    continue
                if len(item) < 3 or item[0] != "audio":
                    continue
                pcm = item[2]
            elif isinstance(item, (bytes, bytearray)):
                pcm = bytes(item)
            else:
                continue
            self._heard(pcm)
            ulaw = pcm16_to_ulaw(pcm)
            if not ulaw:
                continue
            try:
                ws.send_json({
                    "type": "input_audio_buffer.append",
                    "audio": base64.b64encode(ulaw).decode("ascii"),
                })
            except OSError:
                self._stop.set()
                break

    def _execute_tool(self, name: str, args: dict[str, Any]) -> tuple[bool, str]:
        """Executes one tool call across OpenAI Realtime and Gemini Live protocols."""
        pbx_names = {str(t.get("name") or "") for t in (self.cfg.get("pbx_tools") or [])}
        if name == "transfer_call":
            target = str(args.get("target") or "")
            intent = next((i for i in self.cfg.get("intents", []) if i["id"] == target), None)
            if intent is not None:
                self.call.matched = intent
                self.call.session.intent = target
                self.call.summary = str(args.get("summary") or "").strip()[:500]
                self.call.session.summary = self.call.summary
                payload: dict[str, Any] = {"ok": True, "status": "transferring"}
                if not self._spoke_in_turn:
                    _, lang_name = self._lang_and_name()
                    payload["instruction"] = (
                        f"Now tell the caller briefly in one short polite sentence in {lang_name} "
                        "that you are transferring them now, then stop speaking. Do not call any more tools."
                    )
                return True, json.dumps(payload, ensure_ascii=False)
            if "transfer_call" not in pbx_names:
                return False, json.dumps({"ok": False, "error": "Unknown transfer target"})

        if name == "end_call":
            self.call.session.intent = "_end"
            self.call.summary = str(args.get("summary") or "").strip()[:500]
            self.call.session.summary = self.call.summary
            payload = {"ok": True, "status": "ending"}
            if not self._spoke_in_turn:
                _, lang_name = self._lang_and_name()
                payload["instruction"] = (
                    f"Now say a brief polite goodbye in one short sentence in {lang_name}, "
                    "then stop speaking. Do not call any more tools."
                )
            return True, json.dumps(payload, ensure_ascii=False)

        if name == "lookup":
            tool = str(args.get("tool") or "knowledge")
            query = str(args.get("query") or "")
            try:
                answer = str(self.call.resolve_lookup(tool, query, args.get("source")))
            except Exception as e:
                answer = f"Lookup error: {e}"
            return False, answer

        if name == "get_db_value":
            qid = str(args.get("query_id") or "")
            raw_params = args.get("params") if isinstance(args.get("params"), dict) else {}
            clean_params = {str(k): str(v) for k, v in raw_params.items() if v is not None}
            try:
                answer = str(self.call.resolve_lookup("get_db_value", {"query_id": qid, "params": clean_params}))
            except Exception as e:
                answer = f"Database lookup error: {e}"
            return False, answer

        if name == "collect_dtmf":
            try:
                max_digits = max(1, min(32, int(args.get("max_digits") or 11)))
            except (ValueError, TypeError):
                max_digits = 11
            try:
                timeout_sec = max(2, min(20, int(args.get("timeout_sec") or 8)))
            except (ValueError, TypeError):
                timeout_sec = 8
            terminator = str(args.get("terminator") if args.get("terminator") is not None else "#")[:1]
            digits: list[str] = []
            while not self._dtmf_q.empty():
                try:
                    self._dtmf_q.get_nowait()
                except queue.Empty:
                    break
            self._collecting_dtmf = True
            deadline = time.monotonic() + float(timeout_sec)
            try:
                while len(digits) < max_digits and not self._stop.is_set() and not self.call.gone():
                    rem = deadline - time.monotonic()
                    if rem <= 0:
                        break
                    try:
                        d = self._dtmf_q.get(timeout=min(0.2, rem))
                    except queue.Empty:
                        continue
                    if terminator and d == terminator:
                        break
                    digits.append(d)
                    deadline = max(deadline, time.monotonic() + 3.0)
            finally:
                self._collecting_dtmf = False
            return False, json.dumps({"ok": True, "digits": "".join(digits)}, ensure_ascii=False)

        if name == "send_dtmf" and hasattr(self.call, "resolve_lookup"):
            try:
                return False, str(self.call.resolve_lookup("send_dtmf", args))
            except Exception:
                pass

        if name in pbx_names:
            from .pbx_tools import PbxToolsClient
            client = PbxToolsClient(key=str(self.cfg.get("pbx_tools_key") or ""))
            res = client.execute(name, args, caller={
                "app_id": getattr(self.call.session, "app", 0),
                "call_uuid": getattr(self.call.session, "uuid", ""),
                "caller": getattr(self.call.session, "caller", ""),
                "caller_name": getattr(self.call.session, "caller_name", ""),
                "did": getattr(self.call.session, "did", ""),
                "verified_customer": getattr(self.call.session, "verified_customer", ""),
            })
            action = res.get("action") or {}
            if res.get("ok") and action.get("type") == "transfer":
                target = str(action.get("target") or "")
                if target:
                    intent = next((i for i in (self.cfg.get("intents") or []) if i.get("id") == target), None)
                    if intent is None:
                        intent = {"id": target, "name": target}
                        if action.get("route_id"):
                            intent["route_id"] = str(action["route_id"])
                    self.call.matched = intent
                self.call.session.intent = target
                return True, str(res.get("text") or "")
            if res.get("ok") and action.get("type") in ("hangup", "end"):
                self.call.session.intent = "_end"
                return True, str(res.get("text") or "")
            return False, str(res.get("text") or "")

        return False, json.dumps({"ok": False, "error": f"Unknown tool: {name}"})

    def _handle_function_call(self, ws: RealtimeSocket, ev: dict[str, Any]) -> bool:
        name = str(ev.get("name") or "")
        call_id = str(ev.get("call_id") or "")
        try:
            args = json.loads(str(ev.get("arguments") or "{}"))
        except json.JSONDecodeError:
            args = {}
        self._last_activity = time.monotonic()
        ended, output = self._execute_tool(name, args if isinstance(args, dict) else {})
        self._last_activity = time.monotonic()
        if ended:
            self._closing.set()
        try:
            ws.send_json({
                "type": "conversation.item.create",
                "item": {"type": "function_call_output", "call_id": call_id, "output": output},
            })
            if not ended or not self._spoke_in_turn:
                ws.send_json({"type": "response.create"})
        except (ConnectionError, OSError):
            if not ended:
                raise
        return ended

    def _handle_gemini_tool_calls(self, ws: RealtimeSocket, tool_call: dict[str, Any]) -> bool:
        responses: list[dict[str, Any]] = []
        should_end = False
        for fc in tool_call.get("functionCalls") or []:
            if not isinstance(fc, dict):
                continue
            name = str(fc.get("name") or "")
            call_id = str(fc.get("id") or "")
            args = fc.get("args") if isinstance(fc.get("args"), dict) else {}
            self._last_activity = time.monotonic()
            ended, output = self._execute_tool(name, args)
            log.info("Realtime tool %s ended=%s", name, ended)
            self._last_activity = time.monotonic()
            should_end = should_end or ended
            responses.append({
                "id": call_id,
                "name": name,
                "response": {"result": output},
            })
        if should_end:
            self._closing.set()
        if responses:
            try:
                ws.send_json({"toolResponse": {"functionResponses": responses}})
            except (ConnectionError, OSError):
                if not should_end:
                    raise
        return should_end

    def _pump_upstream_gemini(self, ws: RealtimeSocket, in_q: queue.Queue[Any], drain_stale: bool = False) -> None:
        if not self._wait_upstream_ready():
            return
        if drain_stale and in_q.qsize() > 10:
            dtmf_items: list[Any] = []
            audio_items: list[Any] = []
            while True:
                try:
                    pending = in_q.get_nowait()
                except queue.Empty:
                    break
                if isinstance(pending, tuple) and len(pending) >= 3 and pending[0] == "dtmf":
                    dtmf_items.append(pending)
                elif isinstance(pending, (tuple, bytes, bytearray)):
                    audio_items.append(pending)
            for item in dtmf_items + audio_items[-2:]:
                in_q.put(item)
        upsampler = _Upsampler8to16()
        _, lang_name = self._lang_and_name()
        while not self._stop.is_set() and not self._closing.is_set() and not self.call.gone():
            note = self._silence_note()
            if note:
                try:
                    ws.send_json({"clientContent": {"turns": [{"role": "user", "parts": [{"text": note}]}],
                                                    "turnComplete": True}})
                except OSError:
                    self._stop.set()
                    break
            try:
                item = in_q.get(timeout=0.1)
            except queue.Empty:
                continue
            batch = [item]
            for _ in range(2):
                try:
                    batch.append(in_q.get_nowait())
                except queue.Empty:
                    break
            pcm_chunks: list[bytes] = []
            stop_now = False
            for entry in batch:
                if isinstance(entry, tuple):
                    if len(entry) >= 3 and entry[0] == "dtmf":
                        digit = str(entry[2])
                        self._last_activity = time.monotonic()
                        self._nudges = 0
                        self._dtmf_q.put(digit)
                        if hasattr(self.call.session, "dtmf") and (
                            not self.call.session.dtmf or self.call.session.dtmf[-1] != digit
                        ):
                            self.call.session.dtmf.append(digit)
                        if not self._collecting_dtmf:
                            try:
                                ws.send_json({
                                    "clientContent": {
                                        "turns": [{"role": "user", "parts": [{
                                            "text": f"[Caller pressed DTMF: {digit}] Reply ONLY in {lang_name}."
                                        }]}],
                                        "turnComplete": True,
                                    }
                                })
                            except OSError:
                                self._stop.set()
                                stop_now = True
                                break
                        continue
                    if len(entry) < 3 or entry[0] != "audio":
                        continue
                    pcm_chunks.append(bytes(entry[2]))
                elif isinstance(entry, (bytes, bytearray)):
                    pcm_chunks.append(bytes(entry))
            if stop_now:
                break
            if not pcm_chunks:
                continue
            pcm = b"".join(pcm_chunks)
            self._heard(pcm)
            pcm16k = upsampler.process(pcm)
            if not pcm16k:
                continue
            try:
                ws.send_json({
                    "realtimeInput": {
                        "audio": {
                            "mimeType": "audio/pcm;rate=16000",
                            "data": base64.b64encode(pcm16k).decode("ascii"),
                        }
                    }
                })
            except OSError:
                self._stop.set()
                break

    def _run_openai_protocol(self, ws: RealtimeSocket) -> None:
        voice = str(self.rc.get("voice") or "alloy")
        cfg_vad = self.rc.get("vad") or {}
        vad = {
            "type": "server_vad",
            "threshold": cfg_vad.get("threshold", 0.5),
            "prefix_padding_ms": 300,
            "silence_duration_ms": cfg_vad.get("silence_ms", 500),
        }
        session = {"instructions": self._instructions(), "tools": self._build_tools()}
        if _uses_openai_ga(self.rc):
            session.update({
                "type": "realtime",
                "output_modalities": ["audio"],
                "audio": {
                    "input": {
                        "format": {"type": "audio/pcmu"},
                        "transcription": {"model": "whisper-1"},
                        "turn_detection": {**vad, "interrupt_response": False},
                    },
                    "output": {"format": {"type": "audio/pcmu"}, "voice": voice},
                },
            })
            noise = self.rc.get("noise_reduction")
            if noise in ("near_field", "far_field") and (self.rc.get("provider") or "openai") == "openai":
                session["audio"]["input"]["noise_reduction"] = {"type": noise}
        else:
            session.update({
                "modalities": ["audio", "text"],
                "voice": voice,
                "input_audio_format": "g711_ulaw",
                "output_audio_format": "g711_ulaw",
                "input_audio_transcription": {"model": "whisper-1"},
                "turn_detection": vad,
            })
            if self.rc.get("temperature") is not None:     # the GA protocol has no temperature setting
                session["temperature"] = self.rc["temperature"]
        ws.send_json({"type": "session.update", "session": session})
        self._upstream_ready.set()
        ws.send_json({"type": "response.create"})
        closing_deadline: float | None = None
        closing_got_audio = False
        closing_done_events = 0

        while not self._stop.is_set() and not self.call.gone():
            if closing_deadline is not None and time.monotonic() >= closing_deadline:
                break
            try:
                ev = ws.recv_json()
            except socket.timeout:
                if closing_deadline is not None and time.monotonic() >= closing_deadline:
                    break
                continue
            except (ConnectionError, OSError):
                if self._closing.is_set():
                    break
                raise
            if ev is None:
                break
            etype = str(ev.get("type") or "")
            if etype in ("response.output_audio.delta", "response.audio.delta"):
                b64 = str(ev.get("delta") or "")
                if ev.get("item_id"):
                    self._last_item_id = str(ev["item_id"])
                if b64:
                    if self._closing.is_set():
                        closing_got_audio = True
                    else:
                        self._spoke_in_turn = True
                    pcm = ulaw_to_pcm16(base64.b64decode(b64))
                    self._enqueue_pcm(pcm)
                    if self._speech_stopped is not None:     # the caller's wait for the first sound of the answer
                        turn = getattr(self.call.session, "turn", None)
                        if turn is not None:
                            turn({"first_audio_ms": (time.monotonic() - self._speech_stopped) * 1000})
                        self._speech_stopped = None
            elif etype == "input_audio_buffer.speech_stopped":
                self._speech_stopped = time.monotonic()
                self._spoke_in_turn = False
            elif etype == "input_audio_buffer.speech_started":
                if self._closing.is_set():
                    continue
                self._spoke_in_turn = False
                self._last_activity = time.monotonic()
                self._nudges = 0
                self._clear_playback()
                if self._last_item_id:
                    ws.send_json({
                        "type": "conversation.item.truncate",
                        "item_id": self._last_item_id,
                        "content_index": 0,
                        "audio_end_ms": max(0, self._audio_played_ms),
                    })
                ws.send_json({"type": "response.cancel"})
                self._audio_played_ms = 0
            elif etype == "conversation.item.input_audio_transcription.completed":
                self._spoke_in_turn = False
                self._record_transcript("caller", str(ev.get("transcript") or ""))
            elif etype in ("response.output_audio_transcript.done", "response.audio_transcript.done"):
                if self._closing.is_set():
                    closing_got_audio = True
                else:
                    self._spoke_in_turn = True
                self._record_transcript("assistant", str(ev.get("transcript") or ""))
            elif etype == "response.function_call_arguments.done":
                if self._closing.is_set():
                    break
                log.info("Realtime tool %s", ev.get("name") or "")
                if self._handle_function_call(ws, ev):
                    self._closing.set()
                    if self._spoke_in_turn:
                        break
                    closing_deadline = time.monotonic() + CLOSING_TURN_TIMEOUT
                    closing_got_audio = False
                    closing_done_events = 0
            elif etype == "response.done":
                if not self._closing.is_set():
                    self._spoke_in_turn = False
                else:
                    closing_done_events += 1
                    if closing_got_audio or closing_done_events >= 2:
                        break
            elif etype == "error":
                log.warning("Realtime error event: %s", ev.get("error"))

    def _run_gemini(self, ws: RealtimeSocket) -> None:
        model = str(self.rc.get("model") or "").removeprefix("models/")
        if not model:
            # Google's English guide names this model; UNVERIFIED against a live call. The model is editable
            # in the agent (realtime_cloud.model), so a wrong default is a one-field change.
            model = GEMINI_DEFAULT_MODEL
        model_path = f"models/{model}"
        voice_key = str(self.rc.get("voice") or "alloy")
        # An OpenAI voice name maps to a Gemini one; a Gemini voice name (Kore, Puck ...) is used as it is.
        voice_name = _GEMINI_VOICES.get(voice_key) or (voice_key if re.fullmatch(r"[A-Z][A-Za-z]{2,20}", voice_key) else "Puck")
        def _gemini_params(params: dict[str, Any] | None) -> dict[str, Any]:
            out = copy.deepcopy(params) if params else {"type": "object", "properties": {}}
            for prop in (out.get("properties") or {}).values():
                if isinstance(prop, dict) and prop.get("type") == "integer" and isinstance(prop.get("enum"), list):
                    prop["type"] = "string"
                    prop["enum"] = [str(v) for v in prop["enum"]]
            return out

        decls = [
            {
                "name": t["name"],
                "description": t.get("description") or "",
                "parameters": _gemini_params(t.get("parameters")),
            }
            for t in self._build_tools()
        ]
        vad_silence = max(700, int((self.rc.get("vad") or {}).get("silence_ms", 500)))
        activity_handling = "START_OF_ACTIVITY_INTERRUPTS" if self.cfg.get("barge_in", True) else "NO_INTERRUPTION"
        lang, lang_name = self._lang_and_name()
        speech_cfg: dict[str, Any] = {
            "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice_name}},
        }
        locale = self._lang_locale(lang)
        if locale:
            speech_cfg["languageCode"] = locale
        setup: dict[str, Any] = {
            "setup": {
                "model": model_path,
                "generationConfig": {
                    "responseModalities": ["AUDIO"],
                    "speechConfig": speech_cfg,
                },
                "systemInstruction": {"parts": [{"text": self._instructions()}]},
                "inputAudioTranscription": {},
                "outputAudioTranscription": {},
                "realtimeInputConfig": {
                    "automaticActivityDetection": {
                        "disabled": False,
                        "prefixPaddingMs": 300,
                        "silenceDurationMs": vad_silence,
                    },
                    "activityHandling": activity_handling,
                },
            }
        }
        if self.rc.get("temperature") is not None:
            setup["setup"]["generationConfig"]["temperature"] = self.rc["temperature"]
        if decls:
            setup["setup"]["tools"] = [{"functionDeclarations": decls}]
        ws.send_json(setup)
        deadline = time.monotonic() + 5.0
        setup_error = ""
        while time.monotonic() < deadline and not self.call.gone():
            try:
                ready = ws.recv_json()
            except socket.timeout:
                continue
            if ready is None:
                break
            if "error" in ready:
                setup_error = json.dumps(ready["error"], ensure_ascii=False)[:200]
                break
            if "setupComplete" in ready:
                break
        else:
            ready = None
        if ready is None or "setupComplete" not in ready:
            code = getattr(ws, "close_code", None)
            reason = getattr(ws, "close_reason", "")
            detail = setup_error or (f"ws {code}: {reason}" if code else "")
            raise RuntimeError(
                f"Gemini Live did not acknowledge the session setup ({detail})"
                if detail else "Gemini Live did not acknowledge the session setup"
            )
        start_prompt = (
            "Çağrıyı başlat ve arayanı yalnızca Türkçe olarak, talimattaki selamlama cümlesiyle karşıla."
            if lang == "tr"
            else f"Start the phone call and greet the caller now in {lang_name} only."
        )
        ws.send_json({"realtimeInput": {"text": start_prompt}})
        self._upstream_ready.set()
        downsampler = _Downsampler24to8()
        closing_deadline: float | None = None
        closing_got_audio = False
        closing_turn_completes = 0

        while not self._stop.is_set() and not self.call.gone():
            if closing_deadline is not None and time.monotonic() >= closing_deadline:
                break
            try:
                ev = ws.recv_json()
            except socket.timeout:
                if closing_deadline is not None and time.monotonic() >= closing_deadline:
                    break
                continue
            except (ConnectionError, OSError):
                if self._closing.is_set():
                    break
                raise
            if ev is None:
                break
            if isinstance(ev.get("error"), dict):
                err_txt = json.dumps(ev["error"], ensure_ascii=False)[:200]
                log.warning("Gemini Live error event: %s", err_txt)
                self.call.session.note(f"Realtime error: {err_txt[:120]}")
                continue
            if isinstance(ev.get("goAway"), dict):
                left = ev["goAway"].get("timeLeft") or ""
                log.warning("Gemini Live goAway timeLeft=%s", left)
                self.call.session.note(f"Realtime session closing soon ({left})" if left else "Realtime session goAway")
                continue
            if isinstance(ev.get("toolCall"), dict):
                if self._closing.is_set():
                    break
                self._flush_transcript("caller")
                self._flush_transcript("assistant")
                self._saw_out_tr_turn = False
                if self._handle_gemini_tool_calls(ws, ev["toolCall"]):
                    self._closing.set()
                    if self._spoke_in_turn:
                        break
                    closing_deadline = time.monotonic() + CLOSING_TURN_TIMEOUT
                    closing_got_audio = False
                    closing_turn_completes = 0
            sc = ev.get("serverContent")
            if isinstance(sc, dict):
                if sc.get("interrupted") and not self._closing.is_set():
                    self._spoke_in_turn = False
                    self._flush_transcript("assistant")
                    self._saw_out_tr_turn = False
                    self._clear_playback()
                    self._audio_played_ms = 0
                in_tr = sc.get("inputTranscription")
                if not self._closing.is_set() and isinstance(in_tr, dict) and in_tr.get("text"):
                    self._spoke_in_turn = False
                    self._flush_transcript("assistant")
                    self._saw_out_tr_turn = False
                    self._in_tr.append(str(in_tr["text"]))
                    self._last_activity = time.monotonic()
                    self._nudges = 0
                mt = sc.get("modelTurn")
                if isinstance(mt, dict):
                    self._flush_transcript("caller")
                    for part in mt.get("parts") or []:
                        if not isinstance(part, dict):
                            continue
                        inline = part.get("inlineData")
                        mime = str(inline.get("mimeType") or "") if isinstance(inline, dict) else ""
                        if isinstance(inline, dict) and inline.get("data") and "audio/" in mime:
                            if self._closing.is_set():
                                closing_got_audio = True
                            else:
                                self._spoke_in_turn = True
                            raw_pcm = base64.b64decode(str(inline["data"]))
                            m_rate = re.search(r"rate=(\d+)", mime)
                            rate = int(m_rate.group(1)) if m_rate else 24000
                            if rate == 24000:
                                self._enqueue_pcm(downsampler.process(raw_pcm))
                            else:
                                self._enqueue_pcm(resample_pcm16(raw_pcm, rate, 8000))
                        txt = part.get("text")
                        if txt and not part.get("thought"):
                            if self._closing.is_set():
                                closing_got_audio = True
                            else:
                                self._spoke_in_turn = True
                            if not self._saw_out_tr_turn:
                                self._record_transcript("assistant", str(txt))
                out_tr = sc.get("outputTranscription")
                if isinstance(out_tr, dict) and out_tr.get("text"):
                    self._flush_transcript("caller")
                    self._saw_out_tr_turn = True
                    if self._closing.is_set():
                        closing_got_audio = True
                    else:
                        self._spoke_in_turn = True
                    self._out_tr.append(str(out_tr["text"]))
                    self._last_activity = time.monotonic()
                    self._nudges = 0
                if sc.get("turnComplete") or sc.get("generationComplete"):
                    self._flush_transcript("caller")
                    self._flush_transcript("assistant")
                    self._saw_out_tr_turn = False
                    if not self._closing.is_set():
                        self._spoke_in_turn = False
                    else:
                        if closing_got_audio:
                            break
                        if sc.get("turnComplete"):
                            closing_turn_completes += 1
                            if closing_turn_completes >= 2:
                                break

    def _unreachable(self, reason: str) -> bool:
        """The provider could not be reached: the call ends as "hangup" (the dialplan continues with the agent's
        fallback destination) and says why in the call log. There is no switch to speech to text, language
        model and text to speech."""
        log.warning("Realtime provider unreachable: %s", reason)
        self.call.session.note(f"Realtime provider unreachable: {reason}")
        self.call._stop_pump.set()
        self.call._pump_thread.join(0.5)
        self.call.save("hangup")
        self.call.session.result = "hangup"
        if getattr(self.call, "conn", None) is not None:
            try:
                self.call.conn.sendall(b"\x00\x00\x00")
            except OSError:
                pass
        return False

    def run(self) -> bool:
        """Runs the Realtime session. False only when the connection could not be made (the call is then
        already saved as "hangup"); a failure during the session also ends as "hangup" and returns True."""
        rc = dict(self.rc)
        if "secret_ref" in rc:
            try:
                rc = secret_refs.resolve(rc)
            except ValueError as e:
                return self._unreachable(str(e))
        self.rc = rc
        provider = str(self.rc.get("provider") or "openai")
        ws_url, headers = _resolve_ws_endpoint(self.rc)
        if not ws_url or (not self.rc.get("api_key") and not self.rc.get("ws_url") and provider != "openai_compat"):
            return self._unreachable("no endpoint or API key")

        try:
            ws = RealtimeSocket(ws_url, headers)
        except Exception as e:
            return self._unreachable(f"{type(e).__name__}: {str(e)[:100]}")

        # Stop the default silence pump so we control the 20 ms AudioSocket stream.
        self.call._stop_pump.set()
        self.call._pump_thread.join(0.5)
        reader_q = getattr(getattr(self.call, "reader", None), "audio", None)
        own_listen_q = reader_q is None
        in_q: queue.Queue[Any] = queue.Queue() if own_listen_q else reader_q
        if own_listen_q:
            self.call.session.listen_q = in_q
        up_args = (ws, in_q, own_listen_q) if provider == "google_ai" else (ws, in_q)
        up_target = self._pump_upstream_gemini if provider == "google_ai" else self._pump_upstream
        self._upstream_ready.clear()
        play_t = threading.Thread(target=self._pump_playback, name="rt-play", daemon=True)
        up_t = threading.Thread(target=up_target, args=up_args, name="rt-up", daemon=True)
        play_t.start()
        up_t.start()

        try:
            if provider == "google_ai":
                self._run_gemini(ws)
            else:
                self._run_openai_protocol(ws)

            if self.call.session.intent and not self._gave_up:
                self._drain_playback()

            self._flush_transcript("caller")
            self._flush_transcript("assistant")
            if self._transcript_lines:
                self.call.session.transcript = "\n".join(self._transcript_lines)
            intent = self.call.session.intent
            result = "ended" if intent == "_end" else "matched" if intent else "silence" if self._gave_up else "hangup"
            self.call.save(result)
            self.call.session.result = result
            return True
        except Exception as e:
            log.warning("Realtime session error: %s", e)
            self._flush_transcript("caller")
            self._flush_transcript("assistant")
            if self._transcript_lines:
                self.call.session.transcript = "\n".join(self._transcript_lines)
            self.call.session.note(f"Realtime session failed: {type(e).__name__}: {str(e)[:100]}")
            self.call.save("hangup")
            self.call.session.result = "hangup"
            return True
        finally:
            self._stop.set()
            self._upstream_ready.set()
            if own_listen_q:
                self.call.session.listen_q = None
            ws.close()
            play_t.join(0.5)
            up_t.join(0.5)
            avg_rms = int(self._in_rms_sum / self._in_frames) if self._in_frames else 0
            close_code = getattr(ws, "close_code", None)
            close_reason = getattr(ws, "close_reason", "")
            ws_close = f"{close_code}:{close_reason}" if close_code is not None else "-"
            log.info(
                "Realtime summary provider=%s model=%s in_frames=%d speech_frames=%d avg_rms=%d "
                "max_rms=%d clipped=%d out_audio_s=%.1f transcripts=%d ws_close=%s",
                provider,
                str(self.rc.get("model") or GEMINI_DEFAULT_MODEL),
                self._in_frames,
                self._in_speech_frames,
                avg_rms,
                int(self._in_rms_max),
                self._in_clipped_frames,
                self._out_audio_frames * 0.02,
                len(self._transcript_lines),
                ws_close,
            )
            if getattr(self.call, "conn", None) is not None:
                try:
                    self.call.conn.sendall(b"\x00\x00\x00")
                except OSError:
                    pass
