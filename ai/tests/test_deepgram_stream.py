"""Tests for stdlib Deepgram WebSocket streaming STT client."""
import base64
import hashlib
import json
import socket
import struct
import threading
import unittest
from unittest.mock import patch

from aipbx_ai.deepgram_stream import DeepgramStream, _WS_GUID


def _server_ws_text(text: str) -> bytes:
    payload = text.encode("utf-8")
    n = len(payload)
    hdr = bytearray([0x81])
    if n < 126:
        hdr.append(n)
    else:
        hdr.append(126)
        hdr.extend(struct.pack("!H", n))
    return bytes(hdr) + payload


def _read_client_frame(sock):
    b1, b2 = sock.recv(2)
    opcode = b1 & 0x0F
    length = b2 & 0x7F
    if length == 126:
        length = struct.unpack("!H", sock.recv(2))[0]
    mask = sock.recv(4)
    data = bytearray()
    while len(data) < length:
        data.extend(sock.recv(length - len(data)))
    unmasked = bytes(b ^ mask[i & 3] for i, b in enumerate(data))
    return opcode, unmasked


class DeepgramStreamTest(unittest.TestCase):
    def test_streams_audio_and_collects_final_transcript(self):
        client_sock, server_sock = socket.socketpair()
        received = {"audio": bytearray(), "close_stream": False, "auth": ""}

        def serve():
            try:
                req = b""
                while b"\r\n\r\n" not in req:
                    req += server_sock.recv(4096)
                for line in req.decode("latin1").split("\r\n"):
                    if line.lower().startswith("sec-websocket-key:"):
                        key = line.split(":", 1)[1].strip()
                    if line.lower().startswith("authorization:"):
                        received["auth"] = line.split(":", 1)[1].strip()
                accept = base64.b64encode(hashlib.sha1((key + _WS_GUID).encode()).digest()).decode()
                server_sock.sendall(
                    f"HTTP/1.1 101 Switching Protocols\r\n"
                    f"Upgrade: websocket\r\nConnection: Upgrade\r\n"
                    f"Sec-WebSocket-Accept: {accept}\r\n\r\n".encode("ascii")
                )
                while True:
                    op, payload = _read_client_frame(server_sock)
                    if op == 0x2:
                        received["audio"].extend(payload)
                    elif op == 0x1 and b"CloseStream" in payload:
                        received["close_stream"] = True
                        server_sock.sendall(_server_ws_text(json.dumps({
                            "type": "Results",
                            "is_final": False,
                            "channel": {"alternatives": [{"transcript": "merha"}]},
                        })))
                        server_sock.sendall(_server_ws_text(json.dumps({
                            "type": "Results",
                            "is_final": True,
                            "speech_final": True,
                            "channel": {"alternatives": [{"transcript": "merhaba destek"}]},
                        })))
                        server_sock.sendall(_server_ws_text(json.dumps({"type": "Metadata"})))
                        break
            finally:
                server_sock.close()

        t = threading.Thread(target=serve, daemon=True)
        t.start()

        class _FakeCtx:
            def wrap_socket(self, s, server_hostname=None):
                return s

        with patch("socket.create_connection", return_value=client_sock), \
             patch("ssl.create_default_context", return_value=_FakeCtx()):
            dg = DeepgramStream("dg-secret-key", lang="tr", sample_rate=8000)
            dg.connect()
            dg.send_audio(b"\x01\x02" * 160)
            dg.send_audio(b"\x03\x04" * 160)
            text = dg.finish(timeout=2.0)

        t.join(timeout=2.0)
        self.assertEqual(text, "merhaba destek")
        self.assertEqual(received["auth"], "Token dg-secret-key")
        self.assertEqual(len(received["audio"]), 640)
        self.assertTrue(received["close_stream"])

    def test_rejected_handshake_raises_cleanly(self):
        client_sock, server_sock = socket.socketpair()

        def serve():
            server_sock.recv(4096)
            server_sock.sendall(b"HTTP/1.1 401 Unauthorized\r\n\r\n")
            server_sock.close()

        t = threading.Thread(target=serve, daemon=True)
        t.start()

        class _FakeCtx:
            def wrap_socket(self, s, server_hostname=None):
                return s

        with patch("socket.create_connection", return_value=client_sock), \
             patch("ssl.create_default_context", return_value=_FakeCtx()):
            dg = DeepgramStream("bad-key")
            with self.assertRaises(ConnectionError):
                dg.connect()


if __name__ == "__main__":
    unittest.main()
