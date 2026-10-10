"""Tests for live calls: /v1/calls/start, the lookup, the AudioSocket server.

Fake TTS backend (no model runtime), a local HTTP server for lookups and a
fake AudioSocket client in place of Asterisk.

    python3 -m unittest discover ai/tests
"""
import http.client
import json
import logging
import os
import socket
import struct
import sys
import threading
import time
import unittest
import urllib.parse
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import calls  # noqa: E402
from aipbx_ai.calls import AudioSocketServer, CallRegistry, dialplan_key, render  # noqa: E402
from aipbx_ai.models import ModelManager, ModelSpec  # noqa: E402
from aipbx_ai.server import AiServer  # noqa: E402

TOKEN = "c" * 64
KEY = dialplan_key(TOKEN)
logging.disable(logging.CRITICAL)


class FakeTts:
    def __init__(self, spec, data_dir=None):
        self.spec = spec
        self.files = True
        self.calls = []
        self.seconds = 0.5          # audio per synthesis
        self.fail = False

    def installed(self):
        return self.files

    def disk_bytes(self):
        return 0

    def download(self, progress):
        progress(100)

    def load(self):
        pass

    def unload(self):
        pass

    def delete_files(self):
        pass

    def synthesize(self, text, speed, sample_rate, on_first_audio=None):
        self.calls.append((text, speed, sample_rate))
        if self.fail:
            raise RuntimeError("model crashed")
        n = int(sample_rate * self.seconds)
        # a recognisable ramp, so the bytes can be compared
        return b"".join(struct.pack("<h", (i % 2000) - 1000) for i in range(n))


def spec(id, kind="tts"):
    return ModelSpec(id=id, title=id, kind=kind, languages=("tr",), license="Apache-2.0",
                     license_url="https://example.invalid", homepage="https://example.invalid",
                     download_mb=1, backend=FakeTts)


class Lookup(BaseHTTPRequestHandler):
    """/json -> {"amount": ...}; /slow sleeps 3 s; /html is not JSON; /list a JSON list."""
    seen = []

    def log_message(self, *a):
        pass

    def do_GET(self):  # noqa: N802
        parts = urllib.parse.urlsplit(self.path)
        Lookup.seen.append(urllib.parse.parse_qs(parts.query))
        if parts.path == "/slow":
            time.sleep(3)
        if parts.path == "/html":
            body, ctype = b"<html>no</html>", "text/html"
        elif parts.path == "/list":
            body, ctype = b"[1, 2]", "application/json"
        elif parts.path == "/big":
            body, ctype = b'{"a":"' + b"x" * 70000 + b'"}', "application/json"
        elif parts.path == "/404":
            self.send_response(404)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        else:
            body = json.dumps({"amount": "1.234,50", "name": "Ali", "n": 7, "f": 2.5, "flag": True,
                               "nested": {"x": 1}, "bad key": "x", "none": None}).encode()
            ctype = "application/json"
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except OSError:
            pass


class CallsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.lookup = ThreadingHTTPServer(("127.0.0.1", 0), Lookup)
        cls.lookup.daemon_threads = True
        threading.Thread(target=cls.lookup.serve_forever, daemon=True).start()
        cls.lookup_base = "http://127.0.0.1:%d" % cls.lookup.server_address[1]

    @classmethod
    def tearDownClass(cls):
        cls.lookup.shutdown()
        cls.lookup.server_close()

    def setUp(self):
        self.backends = {}

        def factory(s):
            self.backends[s.id] = FakeTts(s)
            return self.backends[s.id]

        self.manager = ModelManager((spec("ema-lightning"), spec("stopped-tts"), spec("vosk", "stt")),
                                    "/nonexistent", backend_factory=factory)
        for mid in ("ema-lightning", "vosk"):
            self.manager.install(mid)
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline and any(self.manager.state(m) != "ready" for m in ("ema-lightning", "vosk")):
            time.sleep(0.01)
        self.tts = self.backends["ema-lightning"]
        self.registry = CallRegistry(self.manager, TOKEN, ttl=30)
        self.server = AiServer(("127.0.0.1", 0), TOKEN, self.manager, calls=self.registry)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.audio = AudioSocketServer(("127.0.0.1", 0), self.registry).start()
        self.audio_port = self.audio.server_address[1]

    def tearDown(self):
        self.audio.shutdown()
        self.registry.close()
        self.server.shutdown()
        self.server.server_close()

    # ---- helpers ----------------------------------------------------------

    def start(self, headers=None, raw=None, **fields):
        form = {"app": "7", "key": KEY, "model": "ema-lightning", "speed": "1", "max": "4",
                "caller": "+905551112233", "did": "02125550000", "uniqueid": "1700000000.42",
                "text": "Merhaba, hoş geldiniz.", "lookup": "", "fallback": ""}
        for k, v in fields.items():
            if v is None:
                form.pop(k, None)
            else:
                form[k] = v
        body = raw if raw is not None else urllib.parse.urlencode(form, quote_via=urllib.parse.quote).encode()
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=20)
        h = {"Content-Type": "application/x-www-form-urlencoded"}
        h.update(headers or {})
        conn.request("POST", "/v1/calls/start", body=body, headers=h)
        resp = conn.getresponse()
        data = resp.read()
        conn.close()
        self.assertEqual(resp.status, 200)
        self.assertTrue(resp.getheader("Content-Type").startswith("text/plain"))
        return data.decode()

    def get_calls(self, token=TOKEN):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=20)
        conn.request("GET", "/v1/calls", headers={"Authorization": "Bearer " + token} if token else {})
        resp = conn.getresponse()
        data = resp.read()
        conn.close()
        return resp.status, json.loads(data)

    def connect(self, call_uuid):
        s = socket.create_connection(("127.0.0.1", self.audio_port), timeout=10)
        s.sendall(struct.pack(">BH", 1, 16) + uuid.UUID(call_uuid).bytes)
        return s

    @staticmethod
    def read_msg(s):
        def exact(n):
            buf = b""
            while len(buf) < n:
                chunk = s.recv(n - len(buf))
                if not chunk:
                    return None
                buf += chunk
            return buf
        head = exact(3)
        if head is None:
            return None
        kind, length = struct.unpack(">BH", head)
        return kind, exact(length) if length else b""

    def play_all(self, s):
        """Reads until terminate/close; returns (audio bytes, [arrival times], ended with 0x00)."""
        audio, times = b"", []
        while True:
            m = self.read_msg(s)
            if m is None:
                return audio, times, False
            kind, payload = m
            if kind == 0x00:
                return audio, times, True
            self.assertEqual(kind, 0x10)
            self.assertLessEqual(len(payload), 320)
            audio += payload
            times.append(time.monotonic())

    def wait(self, cond, timeout=5):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if cond():
                return True
            time.sleep(0.02)
        return False

    # ---- key and fields -----------------------------------------------------

    def test_key_is_hmac_of_token(self):
        import hashlib
        import hmac
        self.assertEqual(KEY, hmac.new(TOKEN.encode(), b"aipbx-dialplan", hashlib.sha256).hexdigest())
        self.assertEqual(len(KEY), 64)

    def test_start_ok(self):
        u = self.start()
        self.assertEqual(len(u), 36)
        uuid.UUID(u)
        self.assertEqual(self.tts.calls, [("Merhaba, hoş geldiniz.", 1.0, 8000)])
        status, body = self.get_calls()
        self.assertEqual(status, 200)
        self.assertEqual(body["per_app"], {"7": 1})
        call = body["calls"][0]
        self.assertEqual((call["uuid"], call["app"], call["model"], call["state"], call["caller"]),
                         (u, 7, "ema-lightning", "prepared", "+905551112233"))
        self.assertIsInstance(call["started"], int)
        self.assertEqual(call["audio_seconds"], 0.5)

    def test_wrong_key(self):
        self.assertEqual(self.start(key="0" * 64), "")
        self.assertEqual(self.start(key=None), "")
        self.assertEqual(self.start(key=dialplan_key("d" * 64)), "")
        self.assertEqual(self.tts.calls, [])
        self.assertEqual(self.start(key=KEY.upper()), self.registry.snapshot()["calls"][0]["uuid"])

    def test_forwarded_refused(self):
        self.assertEqual(self.start(headers={"X-Forwarded-For": "10.0.0.1"}), "")
        self.assertEqual(self.start(headers={"Forwarded": "for=1.2.3.4"}), "")

    def test_calls_list_needs_token(self):
        self.assertEqual(self.get_calls(token=None)[0], 401)
        self.assertEqual(self.get_calls(token="x" * 64)[0], 401)

    def test_model_checks(self):
        self.assertEqual(self.start(model="stopped-tts"), "")   # installed, not ready
        self.assertEqual(self.start(model="vosk"), "")          # not TTS
        self.assertEqual(self.start(model="nope"), "")
        self.assertEqual(self.start(model="../x"), "")
        self.assertEqual(self.registry.live_count(), 0)

    def test_invalid_fields(self):
        for fields in ({"app": "0"}, {"app": "1000001"}, {"app": "x"}, {"app": None},
                       {"speed": "3"}, {"speed": "0.4"}, {"speed": "nan"}, {"max": "0"}, {"max": "51"},
                       {"text": ""}, {"text": "   "}, {"text": "a" * 2001}, {"fallback": "b" * 2001},
                       {"lookup": "ftp://example.invalid/x"}, {"lookup": "file:///etc/passwd"},
                       {"lookup": "http://" + "a" * 500}, {"lookup": "http:///nohost"}):
            with self.subTest(fields=fields):
                self.assertEqual(self.start(**fields), "")
        self.assertEqual(self.tts.calls, [])
        # duplicated field, non-ASCII raw body, garbage
        self.assertEqual(self.start(raw=b"app=1&app=2&key=" + KEY.encode() + b"&model=ema-lightning&text=x"), "")
        self.assertEqual(self.start(raw="app=1&text=ş".encode()), "")
        self.assertEqual(self.start(raw=b"%%%"), "")
        self.assertEqual(self.start(raw=b""), "")

    def test_text_limits_and_defaults(self):
        self.assertEqual(len(self.start(text="ş" * 2000)), 36)      # 2000 characters, not bytes
        self.assertEqual(len(self.start(speed=None, max=None, app="8")), 36)
        self.assertEqual(self.tts.calls[-1][1], 1.0)
        self.assertEqual(len(self.start(speed="1.5", app="9")), 36)
        self.assertEqual(self.tts.calls[-1][1], 1.5)

    def test_odd_caller_is_dropped_not_refused(self):
        u = self.start(caller="Bob <123>", did="x" * 41)
        self.assertEqual(len(u), 36)
        call = self.registry.snapshot()["calls"][0]
        self.assertEqual((call["caller"], call["did"]), ("", ""))

    def test_plus_and_percent_encoding(self):
        # CURL posts what URIENCODE made: spaces as %20, "+" as %2B
        body = ("app=3&key=%s&model=ema-lightning&caller=%%2B90555&text=%s" %
                (KEY, urllib.parse.quote("1+1 = 2 ş"))).encode()
        self.assertEqual(len(self.start(raw=body)), 36)
        self.assertEqual(self.tts.calls[-1][0], "1+1 = 2 ş")
        self.assertEqual(self.registry.snapshot()["calls"][0]["caller"], "+90555")

    def test_max_per_app(self):
        uuids = [self.start(max="2") for _ in range(2)]
        self.assertTrue(all(len(u) == 36 for u in uuids))
        self.assertEqual(self.start(max="2"), "")
        self.assertEqual(len(self.start(max="2", app="8")), 36)      # other app
        self.assertEqual(self.registry.snapshot()["per_app"], {"7": 2, "8": 1})
        # finishing one frees a slot
        s = self.connect(uuids[0])
        self.play_all(s)
        s.close()
        self.assertTrue(self.wait(lambda: self.registry.live_count(7) == 1))
        self.assertEqual(len(self.start(max="2")), 36)

    def test_global_limit(self):
        self.registry.max_live = 2
        self.assertEqual(len(self.start(app="1")), 36)
        self.assertEqual(len(self.start(app="2")), 36)
        self.assertEqual(self.start(app="3"), "")

    def test_synthesis_failure(self):
        self.tts.fail = True
        self.assertEqual(self.start(), "")
        self.assertEqual(self.registry.live_count(), 0)

    def test_expiry(self):
        self.registry.ttl = 0.2
        u = self.start()
        time.sleep(0.3)
        body = self.get_calls()[1]
        self.assertEqual(body["per_app"], {})
        self.assertEqual([(c["uuid"], c["state"], c["result"]) for c in body["calls"]], [(u, "done", "expired")])
        s = self.connect(u)                 # too late: closed
        self.assertIsNone(self.read_msg(s))
        s.close()

    # ---- template and lookup ------------------------------------------------

    def test_render(self):
        self.assertEqual(render("Borç {amount} TL.", {"amount": "5"}), ("Borç 5 TL.", []))
        self.assertEqual(render("Borç {amount} , {x}!", {}), ("Borç,!", ["amount", "x"]))
        self.assertEqual(render("{a}{b} {not valid} {}", {"a": "1", "b": "{a}"}), ("1{a} {not valid} {}", []))

    def test_lookup_fills_values(self):
        Lookup.seen.clear()
        u = self.start(text="Sayın {name}, borcunuz {amount} lira, {n} gün, {f} puan.",
                       lookup=self.lookup_base + "/json?token=abc&app=999")
        self.assertEqual(len(u), 36)
        self.assertEqual(self.tts.calls[-1][0], "Sayın Ali, borcunuz 1.234,50 lira, 7 gün, 2.5 puan.")
        q = Lookup.seen[-1]
        self.assertEqual((q["caller"], q["did"], q["app"], q["token"]),
                         (["+905551112233"], ["02125550000"], ["7"], ["abc"]))

    def test_lookup_ignores_nested_and_bool(self):
        self.start(text="A{nested}B{flag}C{none}D", lookup=self.lookup_base + "/json")
        self.assertEqual(self.tts.calls[-1][0], "ABCD")

    def test_missing_placeholder_uses_fallback(self):
        self.start(text="Borç {amount}, vade {due}.", fallback="Borcunuz {amount}. {unknown}Sorun var.",
                   lookup=self.lookup_base + "/json")
        self.assertEqual(self.tts.calls[-1][0], "Borcunuz 1.234,50. Sorun var.")

    def test_missing_placeholder_removed_without_fallback(self):
        self.start(text="Borç {amount}, vade {due}.", lookup=self.lookup_base + "/json")
        self.assertEqual(self.tts.calls[-1][0], "Borç 1.234,50, vade.")

    def test_lookup_timeout_uses_fallback(self):
        started = time.monotonic()
        u = self.start(text="Borç {amount}.", fallback="Bilgi alınamadı.", lookup=self.lookup_base + "/slow")
        self.assertLess(time.monotonic() - started, 2.8)
        self.assertEqual(len(u), 36)
        self.assertEqual(self.tts.calls[-1][0], "Bilgi alınamadı.")

    def test_lookup_failures_use_fallback(self):
        for path in ("/html", "/list", "/big", "/404"):
            with self.subTest(path=path):
                self.start(text="Borç {amount}.", fallback="Yedek metin.", lookup=self.lookup_base + path,
                           app=str(len(self.tts.calls) + 1))
                self.assertEqual(self.tts.calls[-1][0], "Yedek metin.")

    def test_lookup_failure_without_fallback(self):
        self.start(text="Borç {amount} lira.", lookup=self.lookup_base + "/html")
        self.assertEqual(self.tts.calls[-1][0], "Borç lira.")
        # a closed port fails fast, too
        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            dead = s.getsockname()[1]
        self.start(text="X {amount} Y.", lookup="http://127.0.0.1:%d/" % dead, app="2")
        self.assertEqual(self.tts.calls[-1][0], "X Y.")

    def test_lookup_failure_without_text_left(self):
        self.assertEqual(self.start(text="{amount}", lookup=self.lookup_base + "/html"), "")

    # ---- AudioSocket --------------------------------------------------------

    def test_playback_paced_and_terminated(self):
        self.tts.seconds = 1.0
        u = self.start()
        pcm = self.tts.synthesize("x", 1, 8000)
        s = self.connect(u)
        started = time.monotonic()
        audio, times, terminated = self.play_all(s)
        elapsed = time.monotonic() - started
        self.assertTrue(terminated)
        self.assertEqual(audio, pcm)
        self.assertEqual(len(times), 50)        # 1 s in 20 ms frames
        # 1 s of audio + 0.2 s tail, less the 60 ms lead; never a burst
        self.assertGreater(elapsed, 1.0)
        self.assertLess(elapsed, 1.6)
        self.assertLess(times[25] - started, 0.55)   # frame 25 is due at 0.5 s - 0.06 s lead
        self.assertGreater(times[25] - started, 0.38)
        self.assertGreater(times[-1] - started, 0.85)
        # peer closes after 0x00, as Asterisk does
        s.close()
        self.assertTrue(self.wait(lambda: self.registry.snapshot()["calls"][0]["state"] == "done"))
        call = self.registry.snapshot()["calls"][0]
        self.assertEqual((call["result"], call["state"]), ("played", "done"))
        self.assertEqual(self.registry.snapshot()["per_app"], {})

    def test_early_hangup_by_caller(self):
        self.tts.seconds = 3.0
        u = self.start()
        s = self.connect(u)
        for _ in range(5):
            self.assertEqual(self.read_msg(s)[0], 0x10)
        s.sendall(b"\x00\x00\x00")
        s.close()
        self.assertTrue(self.wait(lambda: self.registry.live_count() == 0, 2))
        call = self.registry.snapshot()["calls"][0]
        self.assertEqual(call["result"], "hangup")
        self.assertLess(call["seconds"], 2.0)

    def test_socket_closed_by_caller(self):
        self.tts.seconds = 3.0
        s = self.connect(self.start())
        self.read_msg(s)
        s.close()
        self.assertTrue(self.wait(lambda: self.registry.live_count() == 0, 2))
        self.assertEqual(self.registry.snapshot()["calls"][0]["result"], "hangup")

    def test_unknown_uuid_closed(self):
        s = self.connect(str(uuid.uuid4()))
        self.assertIsNone(self.read_msg(s))
        s.close()

    def test_uuid_used_once(self):
        u = self.start()
        s1 = self.connect(u)
        self.assertEqual(self.read_msg(s1)[0], 0x10)
        s2 = self.connect(u)
        self.assertIsNone(self.read_msg(s2))
        s1.close()
        s2.close()

    def test_bad_first_message_closed(self):
        for first in (b"\x10\x00\x02ab", b"\x01\x00\x05abcde", b"\xff\x00\x00", b"\x01\x00"):
            with self.subTest(first=first):
                s = socket.create_connection(("127.0.0.1", self.audio_port), timeout=10)
                s.sendall(first)
                if len(first) < 3:
                    s.shutdown(socket.SHUT_WR)
                self.assertIsNone(self.read_msg(s))
                s.close()
        # the server still works
        s = self.connect(self.start())
        self.assertEqual(self.read_msg(s)[0], 0x10)
        s.close()

    def test_dtmf_and_inbound_audio(self):
        self.tts.seconds = 0.6
        u = self.start()
        s = self.connect(u)
        frame = struct.pack(">BH", 0x10, 320) + b"\x01\x00" * 160
        # split messages across writes, as TCP may
        s.sendall(frame * 3 + b"\x03\x00")
        time.sleep(0.05)
        s.sendall(b"\x015" + b"\x03\x00\x01#" + b"\x12\x00\x04abcd" + b"\x03\x00\x01x" + b"\x7f\x00\x01z")
        audio, _, terminated = self.play_all(s)
        self.assertTrue(terminated)
        s.close()
        self.assertTrue(self.wait(lambda: self.registry.live_count() == 0))
        session = [x for x in self.registry._finished if x.uuid == u][0]
        self.assertEqual(session.dtmf, ["5", "#"])
        self.assertEqual(session.inbound_bytes, 3 * 320 + 4)
        self.assertEqual(session.inbound_frames, 4)
        self.assertEqual(session.inbound_rate, 16000)
        self.assertEqual(self.registry.snapshot()["calls"][0]["dtmf"], "5#")

    def test_error_from_asterisk_stops(self):
        self.tts.seconds = 3.0
        s = self.connect(self.start())
        self.read_msg(s)
        s.sendall(b"\xff\x00\x01\x02")
        self.assertTrue(self.wait(lambda: self.registry.live_count() == 0, 2))
        self.assertEqual(self.registry.snapshot()["calls"][0]["result"], "error")
        s.close()

    def test_connection_limit(self):
        self.audio.shutdown()
        self.audio = AudioSocketServer(("127.0.0.1", 0), self.registry, max_connections=1).start()
        self.audio_port = self.audio.server_address[1]
        self.tts.seconds = 2.0
        s1 = self.connect(self.start())
        self.assertEqual(self.read_msg(s1)[0], 0x10)
        s2 = self.connect(self.start(app="2"))
        self.assertIsNone(self.read_msg(s2))
        s1.close()
        s2.close()

    def test_finished_list_is_bounded(self):
        for i in range(calls.FINISHED_KEEP + 5):
            u = self.start(app=str(i + 1))
            self.registry.finish(self.registry.claim(u), "played")
        body = self.get_calls()[1]
        self.assertEqual(len(body["calls"]), calls.FINISHED_KEEP)
        self.assertEqual(body["calls"][0]["app"], calls.FINISHED_KEEP + 5)   # newest first


if __name__ == "__main__":
    unittest.main()
