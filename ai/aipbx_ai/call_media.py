"""The audio of a call as AudioSocket carries it: the message framing, the 20 ms pacing, and playing a prepared
sound in real time. The lowest layer of a call: it knows nothing of listening or of what is said.
"""
import struct
import threading
import time


SAMPLE_RATE = 8000


FRAME_BYTES = 320            # 20 ms of 8 kHz 16-bit mono


FRAME_SECONDS = 0.02


LEAD_SECONDS = 0.06          # how far ahead of real time we may send


TAIL_SECONDS = 0.2           # after the last frame, before "terminate"


SILENCE_FRAME = b"\x00" * FRAME_BYTES


KIND_HANGUP, KIND_UUID, KIND_DTMF, KIND_AUDIO, KIND_ERROR = 0x00, 0x01, 0x03, 0x10, 0xFF


AUDIO_RATES = {0x10: 8000, 0x11: 12000, 0x12: 16000, 0x13: 24000, 0x14: 32000,
               0x15: 44100, 0x16: 48000, 0x17: 96000, 0x18: 192000}


def message(kind, payload=b""):
    return struct.pack(">BH", kind, len(payload)) + payload


class CallMedia:
    """CallMedia (a part of VoiceRequestsCall; its methods use the call's own attributes)."""

    def _pump(self):
        """Sends a 20 ms frame every 20 ms: the current prompt, or silence.

        Asterisk's AudioSocket() hangs up after 2 s without a message from us,
        so we never pause, not even while listening or transcribing."""
        t0 = time.monotonic()
        i = 0
        try:
            while not self._stop_pump.is_set():
                now = time.monotonic()
                wait = t0 + i * FRAME_SECONDS - LEAD_SECONDS - now
                self.session.late(-wait - LEAD_SECONDS)
                if wait < -LEAD_SECONDS:
                    self.session.resyncs += 1
                    t0 = now - i * FRAME_SECONDS
                    wait = 0.0
                if wait > 0 and (self._stop_pump.wait(wait) or self.reader.gone.is_set()):
                    return
                if self.reader.gone.is_set():
                    return
                with self._mu:
                    cur = self._current
                    if cur is not None:
                        chunk = cur["pcm"][cur["pos"]:cur["pos"] + FRAME_BYTES]
                        if cur["pos"] == 0:
                            cur["started"] = time.monotonic()
                        cur["pos"] += FRAME_BYTES
                        if cur["pos"] >= len(cur["pcm"]):
                            cur["ends"] = t0 + (i + 1) * FRAME_SECONDS     # heard to the end then
                            self._current = None
                            cur["sent"].set()
                    else:
                        chunk = SILENCE_FRAME
                self.conn.sendall(message(KIND_AUDIO, chunk))
                if cur is not None:
                    self.session.sent_bytes += len(chunk)
                i += 1
        except OSError:
            self._broken.set()

    def _send_pcm(self, frame):
        self.conn.sendall(message(KIND_AUDIO, frame))
        self.session.sent_bytes += len(frame)

    def gone(self):
        # DONE: the sweeper ended the call (max call time); the turn stops and the socket closes.
        return self.reader.gone.is_set() or self._broken.is_set() or self.session.closed

    def play(self, pcm, mark_reply=False, stop_when=None):
        """Plays PCM in real time; True when it has played out, False when the peer is gone."""
        if not pcm:
            return not self.gone()
        cur = {"pcm": pcm, "pos": 0, "sent": threading.Event(), "started": None, "ends": None}
        with self._mu:
            self._current = cur
        while not cur["sent"].wait(0.05):
            if self.gone():
                return False
            if stop_when is not None and stop_when.is_set():
                with self._mu:
                    if self._current is cur:
                        self._current = None
                return not self.gone()
        if mark_reply:
            self.reply_started = cur["started"]
        # Wait until the caller has heard the end (the pump sends 60 ms ahead).
        while time.monotonic() < cur["ends"]:
            if self.gone():
                return False
            if stop_when is not None and stop_when.is_set():
                with self._mu:
                    if self._current is cur:
                        self._current = None
                return not self.gone()
            if self.reader.gone.wait(min(0.05, max(0.0, cur["ends"] - time.monotonic()))):
                return False
        return not self.gone()
