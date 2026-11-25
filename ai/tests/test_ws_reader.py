import base64
import hashlib
import socket
import unittest
from unittest.mock import Mock

from aipbx_ai.ws_reader import WS_GUID, WebSocketReader


class WebSocketReaderTest(unittest.TestCase):
    def test_keeps_first_frame_delivered_with_upgrade(self):
        key = "test-key"
        accept = base64.b64encode(hashlib.sha1((key + WS_GUID).encode("ascii")).digest())
        sock = Mock()
        sock.recv.return_value = (
            b"HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: " + accept + b"\r\n\r\n"
            b"\x81\x02ok"
        )
        reader = WebSocketReader(sock)
        reader.read_upgrade(key)
        self.assertEqual((1, b"ok"), reader.read_frame())
        sock.recv.assert_called_once()

    def test_keeps_partial_frame_after_timeout(self):
        sock = Mock()
        sock.recv.side_effect = [b"\x81\x02", b"o", socket.timeout, b"k"]
        reader = WebSocketReader(sock)
        with self.assertRaises(socket.timeout):
            reader.read_frame()
        self.assertEqual((1, b"ok"), reader.read_frame())

    def test_reassembles_fragmented_frames_with_interleaved_ping_and_timeout(self):
        sock = Mock()
        # First fragment (FIN=0, opcode=1, "hel"), ping (FIN=1, opcode=9, "p"), timeout, continuation (FIN=1, opcode=0, "lo")
        sock.recv.side_effect = [
            b"\x01\x03hel\x89\x01p",
            socket.timeout,
            b"\x80\x02lo",
        ]
        reader = WebSocketReader(sock)
        self.assertEqual((9, b"p"), reader.read_frame())
        with self.assertRaises(socket.timeout):
            reader.read_frame()
        self.assertEqual((1, b"hello"), reader.read_frame())
