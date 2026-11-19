"""Stdlib-only Deepgram WebSocket streaming STT client for live AudioSocket calls.

Streams 8 kHz 16-bit LE mono PCM frames in real time while the caller speaks
so turn-taking latency after end-of-speech is ~50-150 ms instead of a full
HTTP upload round-trip. Any connection or protocol error fails cleanly so the
caller path falls back to REST STT / local STT.
"""
import base64
import hashlib
import json
import logging
import os
import socket
import ssl
import struct
import threading
import urllib.parse

log = logging.getLogger("aipbx_ai.deepgram_stream")

_WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


def _ws_frame(opcode: int, payload: bytes) -> bytes:
    """Builds a masked client-to-server RFC 6455 WebSocket frame."""
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


def _read_exact(sock, n: int) -> bytes:
    buf = bytearray()
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise EOFError("websocket closed")
        buf.extend(chunk)
    return bytes(buf)


def _read_ws_frame(sock):
    """Reads one server-to-client WebSocket frame -> (fin, opcode, payload)."""
    b1, b2 = _read_exact(sock, 2)
    fin = bool(b1 & 0x80)
    opcode = b1 & 0x0F
    masked = bool(b2 & 0x80)
    length = b2 & 0x7F
    if length == 126:
        length = struct.unpack("!H", _read_exact(sock, 2))[0]
    elif length == 127:
        length = struct.unpack("!Q", _read_exact(sock, 8))[0]
    if length > 2 * 1024 * 1024:
        raise ValueError("websocket frame too large")
    mask = _read_exact(sock, 4) if masked else None
    payload = _read_exact(sock, length)
    if mask is not None:
        payload = bytes(b ^ mask[i & 3] for i, b in enumerate(payload))
    return fin, opcode, payload


class DeepgramStream:
    """Real-time Deepgram /v1/listen WebSocket session."""

    def __init__(self, api_key: str, lang: str = "tr", model: str = "nova-3",
                 sample_rate: int = 8000, connect_timeout: float = 2.0):
        self.api_key = api_key.strip()
        self.lang = (lang or "tr").strip().lower()[:2]
        self.model = model
        self.sample_rate = int(sample_rate)
        self.connect_timeout = connect_timeout
        self.failed = False
        self._sock = None
        self._send_lock = threading.Lock()
        self._state_lock = threading.Lock()
        self._finals: list[str] = []
        self._done = threading.Event()
        self._completed = False
        self._reader: threading.Thread | None = None

    def connect(self) -> None:
        qs = urllib.parse.urlencode({
            "model": self.model,
            "smart_format": "true",
            "language": self.lang,
            "encoding": "linear16",
            "sample_rate": str(self.sample_rate),
            "channels": "1",
            "interim_results": "true",
            "endpointing": "300",
        })
        ws_key = base64.b64encode(os.urandom(16)).decode("ascii")
        expected_accept = base64.b64encode(
            hashlib.sha1((ws_key + _WS_GUID).encode("ascii")).digest()
        ).decode("ascii")

        raw_sock = socket.create_connection(("api.deepgram.com", 443), timeout=self.connect_timeout)
        try:
            ctx = ssl.create_default_context()
            sock = ctx.wrap_socket(raw_sock, server_hostname="api.deepgram.com")
        except BaseException:
            raw_sock.close()
            raise

        req = (
            f"GET /v1/listen?{qs} HTTP/1.1\r\n"
            f"Host: api.deepgram.com\r\n"
            f"Upgrade: websocket\r\n"
            f"Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {ws_key}\r\n"
            f"Sec-WebSocket-Version: 13\r\n"
            f"Authorization: Token {self.api_key}\r\n"
            f"User-Agent: aipbx-ai\r\n\r\n"
        ).encode("ascii")
        try:
            sock.settimeout(self.connect_timeout)
            sock.sendall(req)

            hdr_buf = bytearray()
            while b"\r\n\r\n" not in hdr_buf:
                chunk = sock.recv(1024)
                if not chunk or len(hdr_buf) > 16384:
                    raise ConnectionError("websocket handshake failed")
                hdr_buf.extend(chunk)
            head, rest = bytes(hdr_buf).split(b"\r\n\r\n", 1)
            lines = head.decode("latin1").split("\r\n")
            if not lines or " 101 " not in lines[0]:
                raise ConnectionError(f"websocket rejected: {lines[0] if lines else 'empty'}")
            headers = {}
            for line in lines[1:]:
                if ":" in line:
                    k, v = line.split(":", 1)
                    headers[k.strip().lower()] = v.strip()
            if headers.get("sec-websocket-accept") != expected_accept:
                raise ConnectionError("invalid sec-websocket-accept")
            if rest:
                # Deepgram does not send frames before audio, but keep socket clean.
                pass
            sock.settimeout(5.0)
        except BaseException:
            sock.close()
            raise
        self._sock = sock
        self._reader = threading.Thread(target=self._reader_loop, name="dg-ws-read", daemon=True)
        self._reader.start()

    def _reader_loop(self) -> None:
        frag = bytearray()
        frag_opcode = 0
        try:
            while (sock := self._sock) is not None:
                fin, opcode, payload = _read_ws_frame(sock)
                if opcode == 0x8:  # Close
                    break
                if opcode == 0x9:  # Ping -> Pong
                    self._send_raw(_ws_frame(0xA, payload))
                    continue
                if opcode == 0xA:  # Pong
                    continue
                if opcode != 0x0:
                    frag = bytearray(payload)
                    frag_opcode = opcode
                else:
                    frag.extend(payload)
                if not fin:
                    continue
                if frag_opcode == 0x1:
                    self._handle_message(frag.decode("utf-8", errors="replace"))
                    if self._completed:
                        break
        except (OSError, EOFError, ValueError):
            if not self._completed:
                self.failed = True
        finally:
            if not self._completed:
                self.failed = True
            self._done.set()

    def _handle_message(self, raw_text: str) -> None:
        try:
            msg = json.loads(raw_text)
        except json.JSONDecodeError:
            return
        if msg.get("type") == "Metadata":
            self._completed = True
            self._done.set()
            return
        if msg.get("type") != "Results":
            return
        if not msg.get("is_final"):
            return
        alts = (msg.get("channel") or {}).get("alternatives") or []
        if not alts:
            return
        transcript = (alts[0].get("transcript") or "").strip()
        if transcript:
            with self._state_lock:
                self._finals.append(transcript)

    def _send_raw(self, frame: bytes) -> None:
        with self._send_lock:
            if self._sock is None or self.failed:
                return
            try:
                self._sock.sendall(frame)
            except OSError:
                self.failed = True

    def send_audio(self, pcm: bytes) -> None:
        if pcm and not self.failed:
            self._send_raw(_ws_frame(0x2, pcm))

    def finish(self, timeout: float = 1.5) -> str:
        if self._sock is not None and not self.failed:
            self._send_raw(_ws_frame(0x1, b'{"type":"CloseStream"}'))
            if not self._done.wait(timeout=timeout) or not self._completed:
                self.failed = True
        self.close()
        if self.failed or not self._completed:
            return ""
        with self._state_lock:
            return " ".join(self._finals).strip()

    def close(self) -> None:
        sock = self._sock
        self._sock = None
        if sock is not None:
            try:
                sock.close()
            except OSError:
                pass
        self._done.set()
