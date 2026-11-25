"""OpenAI Realtime (speech-to-speech) WebSocket bridge for live AudioSocket calls.

Stdlib-only (no external websocket/audioop dependencies): converts 8 kHz 16-bit
signed LE PCM frames <-> G.711 mu-law (g711_ulaw) natively supported by the
OpenAI Realtime API at 8 kHz, streams audio in both directions, handles
barge-in (input_audio_buffer.speech_started -> truncate), and executes
function-calling tools (transfer_call, end_call, lookup).
"""
from __future__ import annotations

import base64
import json
import logging
import os
import queue
import socket
import ssl
import struct
import threading
import time
import urllib.parse
from typing import Any

from .ws_reader import WebSocketReader

log = logging.getLogger("aipbx_ai.realtime")

_FRAME_BYTES = 320  # 20 ms at 8 kHz 16-bit mono = 160 samples = 320 bytes
_SILENCE_PCM = b"\x00" * _FRAME_BYTES
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
        out = [samples[i] for i in range(0, n, 3)]
        return struct.pack(f"<{len(out)}h", *out)
    out_len = max(1, int(n * dst_rate / src_rate))
    out = [samples[min(n - 1, int(i * src_rate / dst_rate))] for i in range(out_len)]
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
        model = str(rc.get("model") or "qwen-omni-turbo-realtime")
        base = str(rc.get("base_url") or "")
        host = "dashscope.aliyuncs.com" if "dashscope.aliyuncs.com" in base else "dashscope-intl.aliyuncs.com"
        ws_url = ws_override or f"wss://{host}/api-ws/v1/realtime?model={urllib.parse.quote(model)}"
        return ws_url, {"Authorization": f"Bearer {api_key}", "OpenAI-Beta": "realtime=v1"}

    if provider == "openai_compat":
        ga = _uses_openai_ga(rc)
        model = str(rc.get("model") or ("gpt-realtime" if ga else "gpt-4o-realtime-preview"))
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

    model = str(rc.get("model") or "gpt-realtime")
    # Persisted app configs can still contain the retired native preview models.
    if model.startswith(("gpt-4o-realtime-preview", "gpt-4o-mini-realtime-preview")):
        model = "gpt-realtime"
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
            self.sock: socket.socket = ctx.wrap_socket(raw, server_hostname=host)
        else:
            self.sock = raw

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
        self.sock.sendall(("\r\n".join(lines) + "\r\n\r\n").encode("utf-8"))

        self.reader = WebSocketReader(self.sock)
        try:
            self.reader.read_upgrade(key)
        except (ConnectionError, OSError):
            self.sock.close()
            raise
        self.sock.settimeout(0.2)
        self._send_lock = threading.Lock()

    def send_json(self, obj: dict[str, Any]) -> None:
        data = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        with self._send_lock:
            self.sock.sendall(_ws_frame(0x1, data))

    def recv_json(self) -> dict[str, Any] | None:
        opcode, payload = self.reader.read_frame()
        if opcode == 0x8:
            return None
        if opcode == 0x9:
            with self._send_lock:
                self.sock.sendall(_ws_frame(0xA, payload))
            return {}
        if opcode in (0x1, 0x2) and payload:
            parsed = json.loads(payload.decode("utf-8"))
            return parsed if isinstance(parsed, dict) else {}
        return {}

    def close(self) -> None:
        try:
            with self._send_lock:
                self.sock.sendall(_ws_frame(0x8, b""))
        except OSError:
            pass
        try:
            self.sock.close()
        except OSError:
            pass


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
        self._upstream_ready = threading.Event()
        self._audio_played_ms = 0
        self._last_item_id = ""
        self._transcript_lines: list[str] = []

    def _build_tools(self) -> list[dict[str, Any]]:
        tools: list[dict[str, Any]] = [
            {
                "type": "function",
                "name": "end_call",
                "description": "End the phone call politely when the caller is done.",
                "parameters": {
                    "type": "object",
                    "properties": {"summary": {"type": "string"}},
                    "required": ["summary"],
                },
            }
        ]
        intents = [str(i.get("id") or "") for i in (self.cfg.get("intents") or []) if i.get("id")]
        if intents:
            tools.append({
                "type": "function",
                "name": "transfer_call",
                "description": "Transfer the caller to one of the configured targets.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "target": {"type": "string", "enum": intents},
                        "summary": {"type": "string"},
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
            tools.append({
                "type": "function",
                "name": "lookup",
                "description": "Look up information in the knowledge base or connected database/API.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "tool": {"type": "string"},
                        "query": {"type": "string"},
                    },
                    "required": ["query"],
                },
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
        from .conversation import render_instructions

        parts: list[str] = []
        lang = str(self.rc.get("lang") or self.cfg.get("stt_lang") or "tr")
        if self.cfg.get("greeting"):
            from .voice_requests import AppStore
            greeting = AppStore.greeting_text(
                self.cfg,
                getattr(self.call.session, "caller_name", ""),
                getattr(self.call.session, "caller", ""),
                getattr(self.call.session, "did", ""),
            )
            if greeting:
                parts.append(f"Start by greeting the caller: {greeting}")
        if self.call.session.caller_name:
            parts.append(f"Caller name: {self.call.session.caller_name}")
        cust_id = getattr(self.call.session, "verified_customer", "")
        if cust_id:
            parts.append(f"System-resolved Customer ID: {cust_id}")
        if self.cfg.get("instructions"):
            ctx_cfg = {
                **self.cfg,
                "caller": getattr(self.call.session, "caller", ""),
                "caller_name": getattr(self.call.session, "caller_name", ""),
                "verified_customer": cust_id,
                "did": getattr(self.call.session, "did", ""),
            }
            parts.append(render_instructions(str(self.cfg["instructions"]), ctx_cfg, lang))
        if self.cfg.get("db_queries"):
            parts.append("For customer, invoice, or service records, use `get_db_value` with exact string params (if params list is none, pass {}).")
        parts.append(f"Respond concisely in language '{lang}' suitable for a phone call.")
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
                self._audio_played_ms += 20
            except queue.Empty:
                frame = _SILENCE_PCM
            try:
                self.call._send_pcm(frame)
            except OSError:
                self._stop.set()
                break
            next_tick += 0.020
            sleep_for = next_tick - time.monotonic()
            if sleep_for > 0:
                time.sleep(sleep_for)
            else:
                next_tick = time.monotonic()

    def _wait_upstream_ready(self) -> bool:
        while not self._stop.is_set() and not self.call.gone():
            if self._upstream_ready.wait(0.05):
                return True
        return False

    def _pump_upstream(self, ws: RealtimeSocket, in_q: queue.Queue[Any]) -> None:
        if not self._wait_upstream_ready():
            return
        while not self._stop.is_set() and not self.call.gone():
            try:
                item = in_q.get(timeout=0.1)
            except queue.Empty:
                continue
            if isinstance(item, tuple):
                if len(item) >= 3 and item[0] == "dtmf":
                    digit = str(item[2])
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
                return True, json.dumps({"ok": True})
            if "transfer_call" not in pbx_names:
                return False, json.dumps({"ok": False, "error": "Unknown transfer target"})

        if name == "end_call":
            self.call.session.intent = "_end"
            self.call.summary = str(args.get("summary") or "").strip()[:500]
            self.call.session.summary = self.call.summary
            return True, json.dumps({"ok": True})

        if name == "lookup":
            tool = str(args.get("tool") or "knowledge")
            query = str(args.get("query") or "")
            try:
                answer = str(self.call.resolve_lookup(tool, query))
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
        ended, output = self._execute_tool(name, args if isinstance(args, dict) else {})
        ws.send_json({
            "type": "conversation.item.create",
            "item": {"type": "function_call_output", "call_id": call_id, "output": output},
        })
        if not ended:
            ws.send_json({"type": "response.create"})
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
            ended, output = self._execute_tool(name, args)
            should_end = should_end or ended
            responses.append({
                "id": call_id,
                "name": name,
                "response": {"result": output},
            })
        if responses:
            ws.send_json({"toolResponse": {"functionResponses": responses}})
        return should_end

    def _pump_upstream_gemini(self, ws: RealtimeSocket, in_q: queue.Queue[Any]) -> None:
        if not self._wait_upstream_ready():
            return
        while not self._stop.is_set() and not self.call.gone():
            try:
                item = in_q.get(timeout=0.1)
            except queue.Empty:
                continue
            if isinstance(item, tuple):
                if len(item) >= 3 and item[0] == "dtmf":
                    digit = str(item[2])
                    self._dtmf_q.put(digit)
                    if hasattr(self.call.session, "dtmf") and (
                        not self.call.session.dtmf or self.call.session.dtmf[-1] != digit
                    ):
                        self.call.session.dtmf.append(digit)
                    if not self._collecting_dtmf:
                        try:
                            ws.send_json({
                                "clientContent": {
                                    "turns": [{"role": "user", "parts": [{"text": f"[Caller pressed DTMF: {digit}]"}]}],
                                    "turnComplete": True,
                                }
                            })
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
            pcm16k = resample_pcm16(pcm, 8000, 16000)
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
        vad = {
            "type": "server_vad",
            "threshold": 0.5,
            "prefix_padding_ms": 300,
            "silence_duration_ms": 500,
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
        else:
            session.update({
                "modalities": ["audio", "text"],
                "voice": voice,
                "input_audio_format": "g711_ulaw",
                "output_audio_format": "g711_ulaw",
                "input_audio_transcription": {"model": "whisper-1"},
                "turn_detection": vad,
            })
        ws.send_json({"type": "session.update", "session": session})
        self._upstream_ready.set()
        ws.send_json({"type": "response.create"})

        while not self._stop.is_set() and not self.call.gone():
            try:
                ev = ws.recv_json()
            except socket.timeout:
                continue
            if ev is None:
                break
            etype = str(ev.get("type") or "")
            if etype in ("response.output_audio.delta", "response.audio.delta"):
                b64 = str(ev.get("delta") or "")
                if ev.get("item_id"):
                    self._last_item_id = str(ev["item_id"])
                if b64:
                    pcm = ulaw_to_pcm16(base64.b64decode(b64))
                    self._enqueue_pcm(pcm)
            elif etype == "input_audio_buffer.speech_started":
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
                txt = str(ev.get("transcript") or "").strip()
                if txt:
                    self._transcript_lines.append(f"Caller: {txt}")
            elif etype in ("response.output_audio_transcript.done", "response.audio_transcript.done"):
                txt = str(ev.get("transcript") or "").strip()
                if txt:
                    self._transcript_lines.append(f"Assistant: {txt}")
            elif etype == "response.function_call_arguments.done":
                if self._handle_function_call(ws, ev):
                    break
            elif etype == "error":
                log.warning("Realtime error event: %s", ev.get("error"))

    def _run_gemini(self, ws: RealtimeSocket) -> None:
        model = str(self.rc.get("model") or "").removeprefix("models/")
        if model in ("", "gemini-2.0-flash-live-001"):
            model = "gemini-3.8-live"
        model_path = f"models/{model}"
        voice_key = str(self.rc.get("voice") or "alloy")
        voice_name = _GEMINI_VOICES.get(voice_key, "Puck")
        decls = [
            {
                "name": t["name"],
                "description": t.get("description") or "",
                "parameters": t.get("parameters") or {"type": "object", "properties": {}},
            }
            for t in self._build_tools()
        ]
        setup: dict[str, Any] = {
            "setup": {
                "model": model_path,
                "generationConfig": {
                    "responseModalities": ["AUDIO"],
                    "speechConfig": {
                        "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice_name}},
                    },
                },
                "systemInstruction": {"parts": [{"text": self._instructions()}]},
            }
        }
        if decls:
            setup["setup"]["tools"] = [{"functionDeclarations": decls}]
        ws.send_json(setup)
        deadline = time.monotonic() + 5.0
        while time.monotonic() < deadline and not self.call.gone():
            try:
                ready = ws.recv_json()
            except socket.timeout:
                continue
            if ready is None or "error" in ready:
                break
            if "setupComplete" in ready:
                break
        else:
            ready = None
        if ready is None or "setupComplete" not in ready:
            raise RuntimeError("Gemini Live did not acknowledge the session setup")
        ws.send_json({"realtimeInput": {"text": "Start the phone call and greet the caller now."}})
        self._upstream_ready.set()

        while not self._stop.is_set() and not self.call.gone():
            try:
                ev = ws.recv_json()
            except socket.timeout:
                continue
            if ev is None:
                break
            if isinstance(ev.get("toolCall"), dict):
                if self._handle_gemini_tool_calls(ws, ev["toolCall"]):
                    break
            sc = ev.get("serverContent")
            if isinstance(sc, dict):
                if sc.get("interrupted"):
                    self._clear_playback()
                    self._audio_played_ms = 0
                mt = sc.get("modelTurn")
                if isinstance(mt, dict):
                    for part in mt.get("parts") or []:
                        if not isinstance(part, dict):
                            continue
                        inline = part.get("inlineData")
                        if isinstance(inline, dict) and inline.get("data") and "audio/" in str(inline.get("mimeType") or ""):
                            raw_pcm = base64.b64decode(str(inline["data"]))
                            self._enqueue_pcm(resample_pcm16(raw_pcm, 24000, 8000))
                        txt = part.get("text")
                        if txt:
                            cleaned = str(txt).strip()
                            if cleaned:
                                self._transcript_lines.append(f"Assistant: {cleaned}")

    def run(self) -> bool:
        """Runs the Realtime session. Returns False only if initial connection failed."""
        provider = str(self.rc.get("provider") or "openai")
        ws_url, headers = _resolve_ws_endpoint(self.rc)
        if not ws_url or (not self.rc.get("api_key") and not self.rc.get("ws_url") and provider != "openai_compat"):
            return False

        try:
            ws = RealtimeSocket(ws_url, headers)
        except Exception as e:
            log.warning("Realtime connect failed (%s), falling back to STT->LLM->TTS", e)
            return False

        # Stop the default silence pump so we control the 20 ms AudioSocket stream.
        self.call._stop_pump.set()
        self.call._pump_thread.join(0.5)
        reader_q = getattr(getattr(self.call, "reader", None), "audio", None)
        own_listen_q = reader_q is None
        in_q: queue.Queue[Any] = queue.Queue() if own_listen_q else reader_q
        if own_listen_q:
            self.call.session.listen_q = in_q
        up_target = self._pump_upstream_gemini if provider == "google_ai" else self._pump_upstream
        self._upstream_ready.clear()
        play_t = threading.Thread(target=self._pump_playback, name="rt-play", daemon=True)
        up_t = threading.Thread(target=up_target, args=(ws, in_q), name="rt-up", daemon=True)
        play_t.start()
        up_t.start()

        try:
            if provider == "google_ai":
                self._run_gemini(ws)
            else:
                self._run_openai_protocol(ws)

            if self._transcript_lines:
                self.call.session.transcript = "\n".join(self._transcript_lines)
            intent = self.call.session.intent
            result = "ended" if intent == "_end" else "matched" if intent else "hangup"
            self.call.save(result)
            self.call.session.result = result
            return True
        except Exception as e:
            log.warning("Realtime session error: %s", e)
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
            if getattr(self.call, "conn", None) is not None:
                try:
                    self.call.conn.sendall(b"\x00\x00\x00")
                except OSError:
                    pass
