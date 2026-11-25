"""Buffered server-side WebSocket reads shared by the two clients."""

import base64
import hashlib

WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
_MAX_HEADER = 16 * 1024
_MAX_FRAME = 16 * 1024 * 1024


class WebSocketReader:
    def __init__(self, sock):
        self.sock = sock
        self.buffer = bytearray()
        self._frag_opcode = None
        self._frag_chunks = bytearray()

    def _fill(self, size):
        while len(self.buffer) < size:
            data = self.sock.recv(min(65536, size - len(self.buffer)))
            if not data:
                raise ConnectionError("WebSocket closed")
            self.buffer.extend(data)

    def read_upgrade(self, key):
        while (end := self.buffer.find(b"\r\n\r\n")) < 0:
            if len(self.buffer) >= _MAX_HEADER:
                raise ConnectionError("WebSocket upgrade headers too large")
            data = self.sock.recv(min(4096, _MAX_HEADER - len(self.buffer)))
            if not data:
                raise ConnectionError("WebSocket closed during upgrade")
            self.buffer.extend(data)
        lines = bytes(self.buffer[:end]).split(b"\r\n")
        del self.buffer[:end + 4]
        status = lines[0].split()
        headers = {}
        for line in lines[1:]:
            name, sep, value = line.partition(b":")
            if sep:
                headers[name.strip().lower()] = value.strip()
        expected = base64.b64encode(hashlib.sha1((key + WS_GUID).encode("ascii")).digest())
        if len(status) < 2 or status[1] != b"101" or headers.get(b"sec-websocket-accept") != expected:
            raise ConnectionError("WebSocket upgrade rejected")

    def _read_one_frame(self):
        # Keep the whole frame buffered until complete, including across read timeouts.
        self._fill(2)
        fin = bool(self.buffer[0] & 0x80)
        opcode = self.buffer[0] & 0x0F
        masked = bool(self.buffer[1] & 0x80)
        length = self.buffer[1] & 0x7F
        offset = 2
        if length >= 126:
            offset += 2 if length == 126 else 8
            self._fill(offset)
            length = int.from_bytes(self.buffer[2:offset], "big")
        if length > _MAX_FRAME:
            raise ConnectionError("WebSocket frame too large")
        end = offset + (4 if masked else 0) + length
        self._fill(end)
        if masked:
            mask = self.buffer[offset:offset + 4]
            offset += 4
            payload = bytes(b ^ mask[i & 3] for i, b in enumerate(self.buffer[offset:end]))
        else:
            payload = bytes(self.buffer[offset:end])
        del self.buffer[:end]
        return fin, opcode, payload

    def read_frame(self):
        while True:
            fin, opcode, payload = self._read_one_frame()
            if opcode >= 0x8:
                return opcode, payload
            if opcode != 0x0:
                if fin:
                    return opcode, payload
                self._frag_opcode = opcode
                self._frag_chunks = bytearray(payload)
                continue
            if self._frag_opcode is None:
                raise ConnectionError("Unexpected WebSocket continuation frame")
            if len(self._frag_chunks) + len(payload) > _MAX_FRAME:
                self._frag_opcode = None
                self._frag_chunks.clear()
                raise ConnectionError("WebSocket frame too large")
            self._frag_chunks.extend(payload)
            if fin:
                full_opcode = self._frag_opcode
                full_payload = bytes(self._frag_chunks)
                self._frag_opcode = None
                self._frag_chunks.clear()
                return full_opcode, full_payload
