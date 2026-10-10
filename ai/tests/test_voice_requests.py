"""Tests for the voice_requests application type: config push, call flow, results.

Fake TTS and STT backends (no model runtime, no numpy) and a fake AudioSocket
client in place of Asterisk that plays "the caller": low noise, and loud
noise bursts as speech. The client sends its frames 4x faster than real time
(the VAD counts frames, not seconds), so a test call takes about a second.

    python3 -m unittest discover ai/tests
"""
import http.client
import json
import logging
import os
import random
import shutil
import socket
import struct
import sys
import tempfile
import threading
import time
import unittest
import urllib.parse
import uuid
import wave
import io

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import voice_requests  # noqa: E402
from aipbx_ai.calls import AudioSocketServer, CallRegistry, dialplan_key  # noqa: E402
from aipbx_ai.models import ModelManager, ModelSpec  # noqa: E402
from aipbx_ai.server import AiServer  # noqa: E402
from aipbx_ai.voice_requests import Listener, validate_config, ConfigError  # noqa: E402

TOKEN = "e" * 64
KEY = dialplan_key(TOKEN)
logging.disable(logging.CRITICAL)

PROMPT_SECONDS = 0.3
PROMPT_BYTES = int(8000 * PROMPT_SECONDS) * 2
FRAME_INTERVAL = 0.005          # the fake caller sends 20 ms frames every 5 ms


def prompt_value(text):
    """The constant sample value the fake TTS uses for a text (to tell prompts apart)."""
    return 100 + sum(text.encode()) % 900


class FakeBackend:
    def __init__(self, spec, data_dir=None):
        self.spec = spec
        self.files = spec.id != "absent-stt"
        self.calls = []             # TTS: texts; STT: WAV bytes
        self.texts = []             # STT: transcripts to return, in order
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
        self.calls.append(text)
        if self.fail:
            raise RuntimeError("model crashed")
        return struct.pack("<h", prompt_value(text)) * int(sample_rate * PROMPT_SECONDS)

    def transcribe(self, wav):
        self.calls.append(wav)
        if self.fail:
            raise RuntimeError("model crashed")
        return {"text": self.texts.pop(0) if self.texts else "", "seconds": 1.0}


def spec(id, kind):
    return ModelSpec(id=id, title=id, kind=kind, languages=("tr",), license="Apache-2.0",
                     license_url="https://example.invalid", homepage="https://example.invalid",
                     download_mb=1, backend=FakeBackend)


def hotel_config(**over):
    config = {
        "type": "voice_requests", "tts_model": "ema-lightning", "stt_model": "vosk-tr-small", "speed": 1.0,
        "greeting": "Merhaba, nasıl yardımcı olabilirim?",
        "retry": "Anlayamadım, isteğinizi tekrar söyler misiniz?",
        "not_understood": "Sizi resepsiyona aktarıyorum.",
        "listen_seconds": 7, "retries": 1, "threshold": 0.5,
        "intents": [
            {"id": "towels", "name": "Havlu", "keywords": ["havlu"],
             "examples": ["İki havlu daha alabilir miyim?"],
             "reply": "Havlu talebiniz alındı, en kısa sürede getirilecek."},
            {"id": "fault", "name": "Arıza", "keywords": ["çalışmıyor", "bozuk"], "examples": [],
             "reply": "Arıza kaydınız alındı."},
        ],
    }
    config.update(over)
    return config


class Caller:
    """A fake Asterisk: sends the UUID, then frames all the time; reads what we play."""

    def __init__(self, port, call_uuid):
        self.sock = socket.create_connection(("127.0.0.1", port), timeout=10)
        self.sock.sendall(struct.pack(">BH", 1, 16) + uuid.UUID(call_uuid).bytes)
        self.audio = bytearray()
        self.terminated = threading.Event()
        self.closed = threading.Event()
        self.last_frame = None
        self.max_gap = 0.0
        self.speech_frames = 0
        self.mu = threading.Lock()
        self.rng = random.Random(7)
        self.stop = threading.Event()
        self.mute_after_speech = False
        self.spoke = False
        threading.Thread(target=self._read, daemon=True).start()
        threading.Thread(target=self._send, daemon=True).start()

    def _exact(self, n):
        buf = b""
        while len(buf) < n:
            chunk = self.sock.recv(n - len(buf))
            if not chunk:
                return None
            buf += chunk
        return buf

    def _read(self):
        try:
            while True:
                head = self._exact(3)
                if head is None:
                    break
                kind, length = struct.unpack(">BH", head)
                payload = self._exact(length) if length else b""
                if kind == 0x00:
                    self.terminated.set()
                    break
                now = time.monotonic()
                with self.mu:
                    if self.last_frame is not None:
                        self.max_gap = max(self.max_gap, now - self.last_frame)
                    self.last_frame = now
                    self.audio += payload
        except OSError:
            pass
        self.closed.set()
        self.stop.set()

    def _frame(self, amplitude):
        return b"".join(struct.pack("<h", self.rng.randint(-amplitude, amplitude)) for _ in range(160))

    def _send(self):
        quiet = [self._frame(60) for _ in range(8)]
        loud = [self._frame(9000) for _ in range(8)]
        n = 0
        while not self.stop.is_set():
            with self.mu:
                speaking = self.speech_frames > 0
                if speaking:
                    self.speech_frames -= 1
                mute = self.mute_after_speech and not speaking and n > 0 and self.spoke
                self.spoke = self.spoke or speaking
            if mute:                    # a channel that sends nothing (Local channel in Wait())
                time.sleep(FRAME_INTERVAL)
                continue
            frame = (loud if speaking else quiet)[n % 8]
            n += 1
            try:
                self.sock.sendall(struct.pack(">BH", 0x10, 320) + frame)
            except OSError:
                return
            time.sleep(FRAME_INTERVAL)

    def speak(self, seconds=1.0):
        with self.mu:
            self.speech_frames += int(seconds / 0.02)

    def dtmf(self, digit):
        self.sock.sendall(b"\x03\x00\x01" + digit.encode())

    def received(self):
        with self.mu:
            return bytes(self.audio)

    def finished_prompts(self):
        """How many prompts have been followed by silence (played to the end)."""
        audio = self.received()
        values = struct.unpack("<%dh" % (len(audio) // 2), audio)
        n, prev = 0, 0
        for v in values:
            if v == 0 and prev != 0:
                n += 1
            prev = v
        return n

    def wait_prompts(self, n, timeout=10):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if self.finished_prompts() >= n:
                time.sleep(0.15)        # listening starts when the prompt has played out
                return True
            time.sleep(0.01)
        return False

    def prompts(self):
        """The constant values of the prompts played, in order."""
        audio = self.received()
        values = struct.unpack("<%dh" % (len(audio) // 2), audio)
        out = []
        for v in values:
            if v and (not out or out[-1] != v):     # 0: the silence between prompts
                out.append(v)
        return out

    def close(self):
        self.stop.set()
        try:
            self.sock.close()
        except OSError:
            pass


class VoiceRequestsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="aipbx-vr-")
        self.backends = {}

        def factory(s):
            self.backends[s.id] = FakeBackend(s)
            return self.backends[s.id]

        self.manager = ModelManager((spec("ema-lightning", "tts"), spec("vosk-tr-small", "stt"),
                                     spec("absent-stt", "stt"), spec("other-tts", "tts")),
                                    self.tmp, backend_factory=factory)
        for mid in ("ema-lightning", "vosk-tr-small"):
            self.manager.install(mid)
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline and any(self.manager.state(m) != "ready"
                                                  for m in ("ema-lightning", "vosk-tr-small")):
            time.sleep(0.01)
        self.tts = self.backends["ema-lightning"]
        self.stt = self.backends["vosk-tr-small"]
        self.registry = CallRegistry(self.manager, TOKEN, data_dir=self.tmp)
        self.server = AiServer(("127.0.0.1", 0), TOKEN, self.manager, calls=self.registry)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.audio = AudioSocketServer(("127.0.0.1", 0), self.registry).start()
        self.callers = []

    def tearDown(self):
        for c in self.callers:
            c.close()
        self.audio.shutdown()
        self.registry.close()
        self.server.shutdown()
        self.server.server_close()
        shutil.rmtree(self.tmp, ignore_errors=True)

    # ---- helpers ----------------------------------------------------------

    def request(self, method, path, body=None, token=TOKEN, headers=None, raw=None):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=20)
        h = dict(headers or {})
        if token:
            h["Authorization"] = "Bearer " + token
        data = raw
        if body is not None:
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
        conn.request(method, path, body=data, headers=h)
        resp = conn.getresponse()
        out = resp.read()
        conn.close()
        return resp.status, resp.getheader("Content-Type") or "", out

    def put_app(self, app_id=902, **over):
        return self.request("PUT", f"/v1/apps/{app_id}", hotel_config(**over))

    def start(self, app="902", **fields):
        form = {"app": app, "key": KEY, "max": "4", "caller": "101", "did": "", "uniqueid": "1700000000.1"}
        form.update(fields)
        form = {k: v for k, v in form.items() if v is not None}
        status, ctype, body = self.request("POST", "/v1/calls/start", token=None,
                                           raw=urllib.parse.urlencode(form).encode(),
                                           headers={"Content-Type": "application/x-www-form-urlencoded"})
        self.assertEqual(status, 200)
        return body.decode()

    def call(self, **over):
        self.assertEqual(self.put_app(**over)[0], 200)
        u = self.start()
        self.assertEqual(len(u), 36)
        c = Caller(self.audio.server_address[1], u)
        self.callers.append(c)
        return u, c

    def result(self, u, key=KEY):
        status, ctype, body = self.request("GET", f"/v1/calls/{u}/result?key={key}", token=None)
        self.assertEqual(status, 200)
        self.assertTrue(ctype.startswith("text/plain"))
        return body.decode()

    def details(self, u, token=TOKEN, key=None):
        path = f"/v1/calls/{u}" + (f"?key={key}" if key is not None else "")
        status, _, body = self.request("GET", path, token=token)
        return status, json.loads(body) if body else None

    def wait_done(self, c, timeout=15):
        self.assertTrue(c.closed.wait(timeout) or c.terminated.wait(0), "the call did not end")

    def value(self, text):
        return prompt_value(text)

    # ---- config -----------------------------------------------------------

    def test_put_get_list_delete_and_reload(self):
        status, _, body = self.put_app()
        self.assertEqual(status, 200)
        stored = json.loads(body)
        self.assertEqual((stored["id"], stored["listen_seconds"], stored["intents"][0]["id"]), (902, 7, "towels"))
        path = os.path.join(self.tmp, "apps", "902.json")
        self.assertTrue(os.path.isfile(path))
        status, _, body = self.request("GET", "/v1/apps")
        self.assertEqual(status, 200)
        apps = json.loads(body)["apps"]
        self.assertEqual([a["id"] for a in apps], [902])
        self.assertEqual(apps[0]["greeting"], "Merhaba, nasıl yardımcı olabilirim?")
        self.assertEqual(json.loads(self.request("GET", "/v1/apps/902")[2])["id"], 902)
        # a new registry (service restart) loads it
        again = CallRegistry(self.manager, TOKEN, data_dir=self.tmp)
        self.assertEqual(again.apps.get(902)[0]["intents"][1]["keywords"], ["çalışmıyor", "bozuk"])
        again.close()
        status, _, body = self.request("DELETE", "/v1/apps/902")
        self.assertEqual((status, json.loads(body)), (200, {"deleted": True}))
        self.assertFalse(os.path.exists(path))
        self.assertEqual(json.loads(self.request("DELETE", "/v1/apps/902")[2]), {"deleted": False})
        self.assertEqual(self.request("GET", "/v1/apps/902")[0], 404)
        self.assertEqual(json.loads(self.request("GET", "/v1/apps")[2]), {"apps": []})

    def test_apps_need_token(self):
        self.assertEqual(self.request("GET", "/v1/apps", token=None)[0], 401)
        self.assertEqual(self.request("PUT", "/v1/apps/1", hotel_config(), token="x" * 64)[0], 401)
        self.assertEqual(self.request("DELETE", "/v1/apps/1", token=None)[0], 401)
        self.assertEqual(self.request("PUT", "/v1/apps/0", hotel_config())[0], 404)
        self.assertEqual(self.request("PUT", "/v1/apps/1000001", hotel_config())[0], 404)
        self.assertEqual(self.request("PUT", "/v1/apps/1000000", hotel_config())[0], 200)

    def test_validation(self):
        intent = hotel_config()["intents"][0]
        bad = [
            {"type": "announcement"}, {"tts_model": "nope"}, {"tts_model": "vosk-tr-small"},
            {"stt_model": "ema-lightning"}, {"stt_model": "../x"}, {"greeting": ""}, {"greeting": "a" * 1001},
            {"retry": 5}, {"listen_seconds": 1}, {"listen_seconds": 16}, {"listen_seconds": 7.5},
            {"retries": 3}, {"retries": -1}, {"retries": True}, {"threshold": 1.1}, {"threshold": "0.5"},
            {"speed": 3}, {"intents": []}, {"intents": "x"}, {"intents": [intent] * 2},
            {"intents": [dict(intent, id=f"i{n}") for n in range(31)]},
            {"intents": [dict(intent, id="Towels")]}, {"intents": [dict(intent, id="a" * 41)]},
            {"intents": [dict(intent, id="none")]}, {"intents": [dict(intent, keywords=["x"] * 31)]},
            {"intents": [dict(intent, keywords=[f"k{n}" for n in range(31)])]},
            {"intents": [dict(intent, examples=[f"e{n}" for n in range(31)])]},
            {"intents": [dict(intent, keywords=[1])]}, {"intents": [dict(intent, reply="r" * 1001)]},
            {"intents": [dict(intent, keywords=[], examples=[])]}, {"intents": [dict(intent, keywords=["x" * 1001])]},
        ]
        for over in bad:
            with self.subTest(over=str(over)[:60]):
                status, _, body = self.put_app(**over)
                self.assertEqual(status, 400)
                self.assertIn("error", json.loads(body))
        self.assertEqual(self.request("PUT", "/v1/apps/5", raw=b"[1]",
                                      headers={"Content-Type": "application/json"})[0], 400)
        self.assertFalse(os.path.exists(os.path.join(self.tmp, "apps", "902.json")))
        # limits are inclusive; a model that is not ready is accepted
        ok = self.put_app(listen_seconds=15, retries=2, threshold=0, stt_model="absent-stt",
                          intents=[dict(intent, id=f"i-{n}_x", keywords=[f"k{k}" for k in range(30)])
                                   for n in range(30)])
        self.assertEqual(ok[0], 200)
        config = validate_config(hotel_config(retry=None, unknown_field=1), self.manager)
        self.assertEqual(config["retry"], "")
        self.assertNotIn("unknown_field", config)
        with self.assertRaises(ConfigError):
            validate_config(None, self.manager)

    def test_load_skips_broken_files(self):
        apps = os.path.join(self.tmp, "apps")
        os.makedirs(apps, exist_ok=True)
        with open(os.path.join(apps, "5.json"), "w") as f:
            f.write("{broken")
        with open(os.path.join(apps, "6.json"), "w") as f:
            json.dump(hotel_config(), f)
        with open(os.path.join(apps, "x.json"), "w") as f:
            json.dump(hotel_config(), f)
        store = voice_requests.AppStore(self.manager, self.tmp)
        self.assertEqual([a["id"] for a in store.list()], [6])

    # ---- start --------------------------------------------------------------

    def test_start_refused(self):
        self.assertEqual(self.start(), "")                         # no config
        self.assertEqual(self.put_app(stt_model="absent-stt")[0], 200)
        self.assertEqual(self.start(), "")                         # STT not ready
        self.assertEqual(self.put_app(tts_model="other-tts")[0], 200)
        self.assertEqual(self.start(), "")                         # TTS not ready (not installed... loaded)
        self.assertEqual(self.put_app()[0], 200)
        self.assertEqual(self.start(key="0" * 64), "")             # wrong key
        self.tts.fail = True
        self.assertEqual(self.start(), "")                         # synthesis failed
        self.tts.fail = False
        self.assertEqual(len(self.start(max="1")), 36)
        self.assertEqual(self.start(max="1"), "")                  # per-app limit
        self.assertEqual(len(self.start(max="2")), 36)
        self.assertEqual(self.registry.live_count(902), 2)

    def test_announcement_still_works(self):
        self.put_app()
        u = self.start(text="Duyuru metni.", model="ema-lightning")
        self.assertEqual(len(u), 36)
        call = self.registry.snapshot()["calls"][0]
        self.assertEqual((call["type"], call["intent"]), ("announcement", None))

    def test_prompts_cached_and_invalidated(self):
        self.put_app()
        self.start()
        deadline = time.monotonic() + 5     # the other prompts are made in the background
        while time.monotonic() < deadline and len(self.tts.calls) < 5:
            time.sleep(0.02)
        self.assertEqual(len(self.tts.calls), 5)    # greeting, retry, not_understood, 2 replies
        self.start()
        time.sleep(0.1)
        self.assertEqual(len(self.tts.calls), 5)
        self.put_app(greeting="Yeni karşılama.")
        self.start()
        self.assertEqual(self.tts.calls[5], "Yeni karşılama.")

    # ---- calls --------------------------------------------------------------

    def test_matched_call(self):
        self.stt.texts = ["iki tane daha havlu alabilir miyim"]
        u, c = self.call()
        cfg = hotel_config()
        self.assertTrue(c.wait_prompts(1))
        c.speak(1.0)
        self.wait_done(c)
        self.assertTrue(c.terminated.is_set())
        self.assertEqual(c.prompts(), [self.value(cfg["greeting"]), self.value(cfg["intents"][0]["reply"])])
        # the STT got the utterance as an 8 kHz WAV: 1 s of speech + up to 0.6 s around it
        wav = wave.open(io.BytesIO(self.stt.calls[0]))
        self.assertEqual((wav.getframerate(), wav.getnchannels(), wav.getsampwidth()), (8000, 1, 2))
        self.assertGreaterEqual(wav.getnframes(), 8000)
        self.assertLessEqual(wav.getnframes(), 8000 * 1.7)
        # results
        self.assertEqual(self.result(u), "towels")
        self.assertEqual(self.result(u, key="0" * 64), "")
        self.assertEqual(self.result(str(uuid.uuid4())), "")
        status, data = self.details(u)
        self.assertEqual(status, 200)
        for field in ("uuid", "app", "caller", "did", "started", "ended", "intent", "intent_name", "score",
                      "transcripts", "dtmf", "result", "audio"):
            self.assertIn(field, data)
        self.assertEqual((data["uuid"], data["app"], data["caller"], data["intent"], data["intent_name"],
                          data["score"], data["transcripts"], data["result"], data["audio"]),
                         (u, 902, "101", "towels", "Havlu", 0.7, ["iki tane daha havlu alabilir miyim"],
                          "matched", True))
        self.assertEqual(data["attempts"][0]["reason"], "speech")
        self.assertIn("speech_end_to_reply_ms", data["timings"])
        # audio
        status, ctype, body = self.request("GET", f"/v1/calls/{u}/audio")
        self.assertEqual((status, ctype), (200, "audio/wav"))
        self.assertEqual(body, self.stt.calls[0])
        self.assertTrue(os.path.isfile(os.path.join(self.tmp, "calls", u + ".wav")))
        self.assertTrue(os.path.isfile(os.path.join(self.tmp, "calls", u + ".json")))
        # the live view
        self.assertTrue(self.wait(lambda: self.registry.live_count() == 0))
        call = json.loads(self.request("GET", "/v1/calls")[2])["calls"][0]
        self.assertEqual((call["type"], call["intent"], call["transcript"], call["result"]),
                         ("voice_requests", "towels", "iki tane daha havlu alabilir miyim", "matched"))

    def test_call_auth_bearer_or_key(self):
        self.stt.texts = ["klima çalışmıyor"]
        u, c = self.call(retries=0)
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.6)
        self.wait_done(c)
        self.assertEqual(self.result(u), "fault")
        self.assertEqual(self.details(u)[0], 200)                       # bearer
        status, data = self.details(u, token=None, key=KEY)             # dialplan key
        self.assertEqual((status, data["intent"]), (200, "fault"))
        self.assertEqual(self.details(u, token=None, key=KEY.upper())[0], 200)
        self.assertEqual(self.details(u, token=None, key="0" * 64)[0], 401)
        self.assertEqual(self.details(u, token=None)[0], 401)
        self.assertEqual(self.details(u, token="x" * 64, key=KEY)[0], 401)   # a wrong bearer is not overridden
        self.assertEqual(self.request("GET", f"/v1/calls/{u}/audio?key={KEY}", token=None)[0], 200)
        self.assertEqual(self.request("GET", f"/v1/calls/{u}/audio?key=00", token=None)[0], 401)
        self.assertEqual(self.request("GET", f"/v1/calls/{u}/audio", token=None)[0], 401)
        fwd = {"X-Forwarded-For": "10.0.0.1"}
        self.assertEqual(self.request("GET", f"/v1/calls/{u}?key={KEY}", token=None, headers=fwd)[0], 403)
        self.assertEqual(self.request("GET", f"/v1/calls/{u}/audio", headers={"Forwarded": "for=x"})[0], 403)
        status, _, body = self.request("GET", f"/v1/calls/{u}/result?key={KEY}", token=None, headers=fwd)
        self.assertEqual((status, body), (200, b""))
        self.assertEqual(self.details(str(uuid.uuid4()), token=None, key=KEY)[0], 404)
        self.assertEqual(self.request("DELETE", f"/v1/calls/{u}")[0], 405)

    def test_retry_then_matched(self):
        self.stt.texts = ["şey bir şey soracaktım", "havlu lütfen"]
        u, c = self.call()
        cfg = hotel_config()
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.assertTrue(c.wait_prompts(2))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(c.prompts(), [self.value(cfg["greeting"]), self.value(cfg["retry"]),
                                       self.value(cfg["intents"][0]["reply"])])
        self.assertEqual(self.result(u), "towels")
        data = self.details(u)[1]
        self.assertEqual(data["transcripts"], ["şey bir şey soracaktım", "havlu lütfen"])
        self.assertEqual([a["intent"] for a in data["attempts"]], [None, "towels"])
        self.assertEqual(len(self.stt.calls), 2)

    def test_not_understood_after_retries(self):
        self.stt.texts = ["bir şey", "başka bir şey"]
        u, c = self.call()
        cfg = hotel_config()
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.5)
        self.assertTrue(c.wait_prompts(2))
        c.speak(0.5)
        self.wait_done(c)
        self.assertEqual(c.prompts(), [self.value(cfg["greeting"]), self.value(cfg["retry"]),
                                       self.value(cfg["not_understood"])])
        self.assertEqual(self.result(u), "none")
        data = self.details(u)[1]
        self.assertEqual((data["intent"], data["result"], data["audio"]), (None, "not_understood", True))

    def test_silence(self):
        u, c = self.call()
        cfg = hotel_config()
        started = time.monotonic()
        self.wait_done(c)
        # 2 x 4 s of no speech at 4x speed, plus the prompts
        self.assertLess(time.monotonic() - started, 6)
        self.assertEqual(c.prompts(), [self.value(cfg["greeting"]), self.value(cfg["retry"]),
                                       self.value(cfg["not_understood"])])
        self.assertEqual(self.stt.calls, [])                 # nothing to transcribe
        # frames all the time (silence while listening): Asterisk hangs up after 2 s without one
        self.assertLess(c.max_gap, 0.2)
        audio = c.received()
        self.assertGreater(len(audio), 3 * PROMPT_BYTES + 8000)   # prompts plus silence frames
        self.assertEqual(self.result(u), "none")
        data = self.details(u)[1]
        self.assertEqual((data["result"], data["audio"], data["transcripts"]), ("silence", False, ["", ""]))
        self.assertEqual(self.request("GET", f"/v1/calls/{u}/audio")[0], 404)

    def test_dtmf_ends_listening(self):
        u, c = self.call(retries=0, listen_seconds=15)
        self.assertTrue(c.wait_prompts(1))
        started = time.monotonic()
        c.dtmf("5")
        self.wait_done(c)
        self.assertLess(time.monotonic() - started, 0.9)      # before the 4 s (1 s here) of silence
        data = self.details(u)[1]
        self.assertEqual((data["dtmf"], data["attempts"][0]["reason"], data["result"]), ("5", "dtmf", "silence"))
        self.assertEqual(self.result(u), "none")

    def test_dtmf_after_speech_keeps_the_utterance(self):
        self.stt.texts = ["havlu"]
        u, c = self.call(retries=0)
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.5)
        time.sleep(0.2)
        c.dtmf("#")
        self.wait_done(c)
        self.assertEqual(self.result(u), "towels")
        self.assertEqual(self.details(u)[1]["attempts"][0]["reason"], "dtmf")

    def test_no_inbound_frames_after_speech_count_as_silence(self):
        self.stt.texts = ["klima çalışmıyor"]
        u, c = self.call(retries=0, listen_seconds=15)
        c.mute_after_speech = True
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.5)
        started = time.monotonic()
        self.wait_done(c)
        # 0.8 s of (missing) silence ends the utterance in real time, not after 15 + 2 s
        self.assertLess(time.monotonic() - started, 3.5)
        data = self.details(u)[1]
        self.assertEqual((data["intent"], data["attempts"][0]["reason"]), ("fault", "speech"))

    def test_stt_failure_is_not_understood(self):
        self.stt.fail = True
        u, c = self.call(retries=0)
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.5)
        self.wait_done(c)
        self.assertTrue(c.terminated.is_set())
        self.assertEqual(self.result(u), "none")
        self.assertEqual(self.details(u)[1]["result"], "not_understood")

    def test_hangup_during_listening(self):
        u, c = self.call()
        self.assertTrue(c.wait_prompts(1))
        c.sock.sendall(b"\x00\x00\x00")
        self.assertTrue(self.wait(lambda: self.registry.live_count() == 0))
        self.assertEqual(self.details(u)[1]["result"], "hangup")
        self.assertEqual(self.result(u), "none")

    def test_cleanup_after_24h(self):
        self.stt.texts = ["havlu"]
        u, c = self.call(retries=0)
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.5)
        self.wait_done(c)
        results = self.registry.results
        self.assertEqual(results.cleanup(), 0)
        # ten minutes later: still there, also after a restart (from the file)
        self.assertEqual(results.cleanup(time.time() + 600), 0)
        fresh = voice_requests.ResultStore(self.tmp)
        self.assertEqual(fresh.get(u)["intent"], "towels")
        self.assertEqual(results.cleanup(time.time() + 24 * 3600 + 5), 2)
        self.assertEqual(os.listdir(os.path.join(self.tmp, "calls")), [])
        self.assertIsNone(results.get(u))
        self.assertEqual(self.result(u), "")
        self.assertEqual(self.details(u)[0], 404)

    def wait(self, cond, timeout=5):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if cond():
                return True
            time.sleep(0.02)
        return False


class ListenerTest(unittest.TestCase):
    @staticmethod
    def frames(amplitude, n, seed=1):
        rng = random.Random(seed)
        return b"".join(struct.pack("<h", rng.randint(-amplitude, amplitude)) for _ in range(160 * n))

    def test_speech_then_silence(self):
        lst = Listener(7)
        audio = self.frames(50, 25) + self.frames(8000, 50) + self.frames(50, 60)
        reason = None
        for i in range(0, len(audio), 320):
            reason = reason or lst.feed(audio[i:i + 320])
        self.assertEqual(reason, "speech")
        pcm = lst.utterance()
        # 50 loud frames + 15 before + 15 after
        self.assertEqual(len(pcm), 80 * 320)

    def test_silence_after_4s_and_odd_chunk_sizes(self):
        lst = Listener(7)
        audio = self.frames(50, 250)
        reason = None
        for i in range(0, len(audio), 333):
            reason = reason or lst.feed(audio[i:i + 333])
        self.assertEqual(reason, "silence")
        self.assertEqual(lst.utterance(), b"")
        self.assertEqual(len(lst.frames), 200)

    def test_listen_seconds_cut_speech(self):
        lst = Listener(2)
        self.assertEqual(lst.feed(self.frames(50, 15) + self.frames(8000, 200)), "timeout")
        self.assertEqual(len(lst.frames), 100)

    def test_short_click_is_not_speech(self):
        lst = Listener(3)
        audio = self.frames(50, 20) + self.frames(8000, 2) + self.frames(50, 200)
        self.assertEqual(lst.feed(audio), "silence")
        self.assertFalse(lst.heard)

    def test_speech_from_the_start(self):
        lst = Listener(7)
        self.assertEqual(lst.feed(self.frames(6000, 40) + self.frames(40, 45)), "speech")
        self.assertTrue(lst.heard)

    def test_loud_line_noise_raises_the_floor(self):
        lst = Listener(5)
        # constant noise at 600 RMS is not speech; speech 10x louder is
        noise = self.frames(1000, 100)
        self.assertIsNone(lst.feed(noise[:320 * 100]))
        self.assertEqual(lst.feed(self.frames(12000, 30) + self.frames(1000, 45)), "speech")


if __name__ == "__main__":
    unittest.main()
