"""The AudioSocket server Asterisk connects to: it reads the call's UUID, claims the session from the registry and
hands the connection to a call (calls.VoiceRequestsCall) or plays the prepared announcement.
"""
import logging
import socket
import struct
import threading
import time
import uuid
from .call_media import AUDIO_RATES, FRAME_BYTES, FRAME_SECONDS, KIND_AUDIO, KIND_DTMF, KIND_ERROR, KIND_HANGUP, KIND_UUID, LEAD_SECONDS, SILENCE_FRAME, TAIL_SECONDS, message
from .call_session import DONE
from .voice_requests import APP_TYPE as VOICE_REQUESTS
from .calls import VoiceRequestsCall


log = logging.getLogger("aipbx_ai.calls")


MAX_CONNECTIONS = 100        # simultaneous AudioSocket connections


UUID_WAIT = 5.0              # seconds to wait for the UUID message


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
            if session.kind == VOICE_REQUESTS:
                result = VoiceRequestsCall(self.registry, conn, session, reader).run()
            elif session.kind == "listen":
                result = self._listen_only(conn, session, reader)
            else:
                result = self._play(conn, session, reader)
        finally:
            reader.done.set()
            reader.thread.join(2.0)
            self.registry.finish(session, result)

    def _listen_only(self, conn, session, reader):
        """Keeps a listen-only AudioSocket alive with 20ms silence while inbound audio queues into listen_q."""
        t0 = time.monotonic()
        i = 0
        try:
            while not reader.gone.is_set() and session.state != DONE:
                now = time.monotonic()
                wait = t0 + i * FRAME_SECONDS - LEAD_SECONDS - now
                if wait < -LEAD_SECONDS:
                    t0 = now - i * FRAME_SECONDS
                    wait = 0.0
                if wait > 0 and reader.gone.wait(wait):
                    break
                if reader.gone.is_set() or session.state == DONE:
                    break
                conn.sendall(message(KIND_AUDIO, SILENCE_FRAME))
                i += 1
            return "hangup" if reader.gone.is_set() else "done"
        except OSError:
            return "hangup"

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
                session.late(-wait - LEAD_SECONDS)
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
