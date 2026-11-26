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

from unittest import mock  # noqa: E402

from aipbx_ai import voice_requests  # noqa: E402
from aipbx_ai.wav import wav_bytes  # noqa: E402
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
        self.files = spec.id != "other-tts"
        self.calls = []             # the texts of the TTS
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



class FakeStt:
    """The cloud speech to text: records the caller's utterance (8 kHz WAV) and answers from `texts`."""

    def __init__(self):
        self.calls = []
        self.texts = []             # transcripts to return, in order
        self.fail = False

    def transcribe(self, cfg, wav):
        w = wave.open(io.BytesIO(wav))
        self.calls.append(wav_bytes(w.readframes(w.getnframes()), 8000))
        if self.fail:
            raise RuntimeError("provider down")
        return self.texts.pop(0) if self.texts else ""


def _no_resample(wav):
    """The test runs without numpy: the utterance goes to the fake provider at its own rate."""
    w = wave.open(io.BytesIO(wav))
    return w.readframes(w.getnframes()), 0.0


def cloud_llm_error():
    from aipbx_ai.cloud_llm import CloudLlmError
    return CloudLlmError("timeout")


def spec(id, kind):
    return ModelSpec(id=id, title=id, kind=kind, languages=("tr",), license="Apache-2.0",
                     license_url="https://example.invalid", homepage="https://example.invalid",
                     download_mb=1, backend=FakeBackend)


def hotel_config(**over):
    config = {
        "type": "voice_requests", "tts_model": "ema-lightning", "stt_model": "cloud:openai",
        "stt_cloud": {"provider": "openai", "api_key": "k" * 12, "lang": "tr"}, "speed": 1.0,
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
        self.floor_amplitude = 60       # 300: a voice too faint for speech
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
        quiet = [self._frame(self.floor_amplitude) for _ in range(8)]
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

        self.manager = ModelManager((spec("ema-lightning", "tts"), spec("other-tts", "tts")),
                                    self.tmp, backend_factory=factory)
        self.manager.install("ema-lightning")
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline and self.manager.state("ema-lightning") != "ready":
            time.sleep(0.01)
        self.tts = self.backends["ema-lightning"]
        self.stt = FakeStt()
        for target, new in (("aipbx_ai.cloud_stt.transcribe", self.stt.transcribe),
                            ("aipbx_ai.wav.pcm16_from_wav", _no_resample)):
            patcher = mock.patch(target, new)
            patcher.start()
            self.addCleanup(patcher.stop)
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
            {"type": "announcement"}, {"tts_model": "nope"}, {"tts_model": "cloud:nope"},
            {"stt_model": "ema-lightning"}, {"stt_model": "../x"}, {"stt_model": "cloud:nope"}, {"greeting": ""}, {"greeting": "a" * 1001},
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
        # limits are inclusive
        ok = self.put_app(listen_seconds=15, retries=2, threshold=0,
                          intents=[dict(intent, id=f"i-{n}_x", keywords=[f"k{k}" for k in range(30)])
                                   for n in range(30)])
        self.assertEqual(ok[0], 200)
        config = validate_config(hotel_config(retry=None, unknown_field=1), self.manager)
        self.assertEqual(config["retry"], "")
        self.assertNotIn("unknown_field", config)
        with self.assertRaises(ConfigError):
            validate_config(None, self.manager)

    def _agent(self, **over):
        base = dict(builtin_agent=True, conversation=True, understand="cloud",
                    llm_cloud={"provider": "openai", "api_key": "k" * 12}, intents=[])
        return hotel_config(**{**base, **over})

    def test_builtin_agent_modes(self):
        rt = {"provider": "openai", "api_key": "sk-test-123456", "model": "gpt-realtime"}
        for bad in ({}, {"mode": "x"}):                         # mode is required
            with self.assertRaises(ConfigError):
                validate_config(self._agent(**bad), self.manager)
        with self.assertRaises(ConfigError):                    # realtime needs realtime_cloud
            validate_config(self._agent(mode="realtime"), self.manager)
        with self.assertRaises(ConfigError):                    # cascade needs a cloud language model
            validate_config(self._agent(mode="cascade", understand="keywords"), self.manager)
        with self.assertRaises(ConfigError):                    # ... and a cloud STT
            validate_config(self._agent(mode="cascade", stt_model="ema-lightning"), self.manager)
        cascade = validate_config(self._agent(mode="cascade"), self.manager)     # knowledge is optional
        self.assertEqual((cascade["mode"], cascade["knowledge"], cascade["knowledge_sources"]), ("cascade", False, []))
        # realtime: nothing about stt/tts/llm models
        cfg = self._agent(mode="realtime", realtime_cloud=rt)
        for key in ("tts_model", "stt_model", "stt_cloud", "llm_cloud"):
            cfg.pop(key, None)
        rtc = validate_config(cfg, self.manager)
        self.assertEqual((rtc["mode"], rtc["tts_model"], rtc["stt_model"], rtc["understand"]), ("realtime", "", "", "keywords"))
        self.assertNotIn("llm_cloud", rtc)

    def test_knowledge_sources_and_instructions_reach_the_config(self):
        sources = [{"id": 2, "name": "FAQ", "description": "answers"}]
        cfg = validate_config(self._agent(mode="cascade", knowledge=True, knowledge_sources=sources,
                                          instructions="y" * 60000), self.manager)
        self.assertEqual((cfg["knowledge_sources"], len(cfg["instructions"])), (sources, 60000))
        with self.assertRaises(ConfigError):
            validate_config(self._agent(mode="cascade", instructions="y" * 60001), self.manager)

    def test_realtime_tuning_keys(self):
        def conf(**rc):
            cfg = self._agent(mode="realtime", realtime_cloud={"provider": "openai", "api_key": "sk-test-123456", **rc})
            return validate_config(cfg, self.manager)["realtime_cloud"]
        default = conf()
        self.assertEqual((default["vad"], default["noise_reduction"], default["temperature"]),
                         ({"threshold": 0.5, "silence_ms": 500}, "off", None))
        tuned = conf(vad={"threshold": 0.8, "silence_ms": 1200}, noise_reduction="near_field", temperature=0.6,
                     model="gemini-3.1-flash-live-preview")
        self.assertEqual((tuned["vad"], tuned["noise_reduction"], tuned["temperature"]),
                         ({"threshold": 0.8, "silence_ms": 1200}, "near_field", 0.6))
        for bad in ({"vad": {"threshold": 0.05}}, {"vad": {"threshold": 1}}, {"vad": {"silence_ms": 100}},
                    {"vad": {"silence_ms": 3001}}, {"vad": {"silence_ms": 500.5}}, {"noise_reduction": "loud"},
                    {"temperature": 0.1}, {"temperature": 1.3}, {"temperature": "hot"}, {"model": "bad model!"}):
            with self.assertRaises(ConfigError, msg=str(bad)):
                conf(**bad)

    def test_realtime_call_starts_without_a_voice_model(self):
        cfg = self._agent(mode="realtime", realtime_cloud={"provider": "openai", "api_key": "sk-test-123456"})
        for key in ("tts_model", "stt_model", "stt_cloud", "llm_cloud"):
            cfg.pop(key, None)
        self.assertEqual(self.request("PUT", "/v1/apps/903", cfg)[0], 200)
        self.assertEqual(len(self.start(app="903")), 36)
        self.assertEqual(self.tts.calls, [])                   # no greeting was synthesised

    # ---- per-call language -------------------------------------------------

    def test_channel_language_is_normalised(self):
        from aipbx_ai import langs
        for raw, want in (("pt_BR", "pt"), ("EN", "en"), ("de-AT", "de"), ("tr", "tr"), ("", ""), (None, ""),
                          ("x", ""), ("en_US.UTF-8", "en"), ("12", ""), ("toolonglang", "")):
            self.assertEqual(langs.channel(raw), want, raw)

    def _lang_agent(self, **over):
        base = dict(mode="cascade", language="tr", greeting="Merhaba (varsayilan)",
                    greetings=["Merhaba {caller_name}, hos geldiniz.", "Hos geldiniz."],
                    greetings_by_lang={"tr": ["Merhaba {caller_name}", "Hos geldiniz"],
                                       "en": [{"order": 0, "text": "Hello {caller_name}"}, "Welcome",
                                              {"order": 3, "text": "Late"}]},
                    languages={"tr": {"name": "Turkish", "locale": "tr-TR"},
                               "en": {"name": "English", "locale": "en-US"}})
        return self._agent(**{**base, **over})

    def test_greetings_by_lang_and_languages_are_validated(self):
        cfg = validate_config(self._lang_agent(), self.manager)
        self.assertEqual(cfg["greetings_by_lang"]["en"], [{"order": 0, "text": "Hello {caller_name}"},
                                                          {"order": 0, "text": "Welcome"}, {"order": 3, "text": "Late"}])
        self.assertEqual(cfg["languages"]["en"], {"name": "English", "locale": "en-US"})
        for bad in ({"tr": ["a"] * 6}, {"tr": ["a" * 251]}, {"tr": "x"}, {"tr": [{"order": 100, "text": "a"}]},
                    {"tr": [{"order": -1, "text": "a"}]}, {"tr": [{"order": "1", "text": "a"}]}, {"tr": [{"order": 1}]}, {"turkish": ["a"]}, "x"):
            with self.assertRaises(ConfigError, msg=str(bad)[:40]):
                validate_config(self._lang_agent(greetings_by_lang=bad), self.manager)

    def test_call_language_follows_the_channel_when_the_pbx_has_it(self):
        cfg = validate_config(self._lang_agent(), self.manager)
        self.assertEqual(voice_requests.call_language(cfg, "en_GB"), ("en", "English", "en-US"))
        self.assertEqual(voice_requests.call_language(cfg, "pt_BR")[0], "tr")      # not an AI language here
        self.assertEqual(voice_requests.call_language(cfg, "")[0], "tr")

    def test_greeting_choice_by_language_with_fallback_and_nameless_preference(self):
        cfg = validate_config(self._lang_agent(), self.manager)
        choose = voice_requests.choose_greeting
        for _ in range(10):
            self.assertIn(choose(cfg, "en", "Ada"), ("Hello {caller_name}", "Welcome"))   # "Late" has a higher order
            self.assertEqual(choose(cfg, "en", ""), "Welcome")                     # no name: the one without it
            self.assertEqual(choose(cfg, "tr", ""), "Hos geldiniz")
        self.assertIn(choose(cfg, "de", "Ada"), cfg["greetings"])                  # no German list: the app's own
        self.assertEqual({choose(cfg, "en", "Ada") for _ in range(60)}, {"Hello {caller_name}", "Welcome"})
        ordered = dict(cfg, greetings=["Second", "First"], greeting_orders=[5, 2], greetings_by_lang={})
        self.assertEqual({choose(ordered, "de", "") for _ in range(10)}, {"First"})   # lowest order wins
        later = dict(cfg, greetings_by_lang={"en": [{"order": 1, "text": "Hello {caller_name}"},
                                                    {"order": 4, "text": "Plain"}]})
        self.assertEqual(choose(later, "en", ""), "Plain")                           # only a name-less one remains: it wins
        only_named = dict(cfg, greetings_by_lang={"en": [{"order": 0, "text": "Hello {caller_name}"}]})
        self.assertEqual(choose(only_named, "en", ""), "Hello {caller_name}")       # blank-filling takes over later
        bare = dict(cfg, greetings=[], greetings_by_lang={})
        self.assertEqual(choose(bare, "en", "Ada"), "Merhaba (varsayilan)")

    def test_greeting_cache_is_per_language(self):
        store = self.registry.apps
        cfg = validate_config(self._lang_agent(tts_model="ema-lightning"), self.manager)
        a = store.greeting_speech(1, 1, cfg, "Hello", lang="en")
        b = store.greeting_speech(1, 1, cfg, "Hello", lang="de")
        self.assertEqual(a, b)
        key = lambda lang: store._phrase_key({**cfg, "call_language": lang}, "Hello")  # noqa: E731
        self.assertNotEqual(key("en"), key("de"))
        self.assertTrue(store._phrase_get(key("en")) and store._phrase_get(key("de")))
        before = len(self.tts.calls)
        store.greeting_speech(1, 1, cfg, "Hello", lang="en")
        self.assertEqual(len(self.tts.calls), before)                              # cached
        own = store.greeting_speech(1, 1, cfg, cfg["greeting"], lang="tr")         # the app's language: the "greeting" prompt
        self.assertTrue(own)

    def test_stt_and_session_follow_the_call_language(self):
        seen = []
        patcher = mock.patch("aipbx_ai.cloud_stt.transcribe",
                             lambda cfg, wav: seen.append((cfg["lang"], cfg["locale"])) or "havlu")
        patcher.start()
        self.addCleanup(patcher.stop)
        cfg = self._lang_agent(retries=0)
        cfg["stt_cloud"] = {"provider": "openai", "api_key": "k" * 12, "lang": "tr"}
        self.assertEqual(self.request("PUT", "/v1/apps/904", cfg)[0], 200)
        u = self.start(app="904", lang="en_GB")
        c = Caller(self.audio.server_address[1], u)
        self.callers.append(c)
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(seen[0], ("en", "en-US"))
        self.assertTrue(self.poll(lambda: self.registry.log.recent(1)))
        self.assertEqual(self.registry.log.recent(1)[0]["language"], "en")

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

    def test_segmented_greeting_and_tts_phrase_cache(self):
        AppStore = voice_requests.AppStore
        self.assertEqual(
            AppStore.split_greeting_segments(
                "Merhaba, {caller_name}, şirketimize hoş geldiniz. Size nasıl yardımcı olabilirim?",
                caller_name="Ahmet Yılmaz",
            ),
            ["Merhaba,", "Ahmet Yılmaz,", "şirketimize hoş geldiniz. Size nasıl yardımcı olabilirim?"],
        )
        self.assertEqual(
            AppStore.split_greeting_segments(
                "Merhaba sayın {caller_name} şirketimize hoş geldiniz",
                caller_name="Ahmet Yılmaz",
            ),
            ["Merhaba sayın", "Ahmet Yılmaz", "şirketimize hoş geldiniz"],
        )
        self.assertEqual(
            AppStore.split_greeting_segments(
                "Merhaba {caller_name}, size nasıl yardımcı olabilirim?",
                caller_name="Ahmet Yılmaz",
            ),
            ["Merhaba Ahmet Yılmaz,", "size nasıl yardımcı olabilirim?"],
        )

        tpl = "Merhaba, {caller_name}, şirketimize hoş geldiniz."
        self.put_app(greeting=tpl)
        config, version = self.registry.apps.get(902)
        self.tts.calls.clear()

        pcm1 = self.registry.apps.greeting_speech(902, version, config, tpl, caller_name="Ahmet Yılmaz")
        self.assertGreater(len(pcm1), 0)
        self.assertEqual(self.tts.calls, ["Merhaba,", "Ahmet Yılmaz,", "şirketimize hoş geldiniz."])

        pcm2 = self.registry.apps.greeting_speech(902, version, config, tpl, caller_name="Mehmet Demir")
        self.assertGreater(len(pcm2), 0)
        self.assertEqual(
            self.tts.calls,
            ["Merhaba,", "Ahmet Yılmaz,", "şirketimize hoş geldiniz.", "Mehmet Demir,"],
        )

        pcm3 = self.registry.apps.greeting_speech(902, version, config, tpl, caller_name="Ahmet Yılmaz")
        self.assertEqual(pcm1, pcm3)
        self.assertEqual(len(self.tts.calls), 4)

        cache_files = os.listdir(os.path.join(self.tmp, "tts_cache"))
        self.assertEqual(len(cache_files), 4)

        fresh = AppStore(self.manager, self.tmp)
        pcm4 = fresh.greeting_speech(902, version, config, tpl, caller_name="Ahmet Yılmaz")
        self.assertEqual(pcm1, pcm4)
        self.assertEqual(len(self.tts.calls), 4)

    # ---- calls --------------------------------------------------------------

    def poll(self, fn, seconds=5.0):
        end = time.monotonic() + seconds
        while time.monotonic() < end:
            if fn():
                return True
            time.sleep(0.02)
        return False

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
        self.assertTrue(self.poll(lambda: self.registry.log.recent(1)))
        rec = self.registry.log.recent(1)[0]                 # the call log line: what the caller said, how it ended
        self.assertEqual(rec["transcripts"], ["iki tane daha havlu alabilir miyim"])
        self.assertEqual(rec["result"], "matched")
        self.assertEqual(rec["intent"], "towels")
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

    # ---- barge-in -----------------------------------------------------------

    def long_prompts(self):
        global PROMPT_SECONDS
        old, PROMPT_SECONDS = PROMPT_SECONDS, 3.0
        self.addCleanup(lambda: globals().__setitem__("PROMPT_SECONDS", old))

    def greeting_seconds(self, c):
        audio = c.received()
        values = struct.unpack("<%dh" % (len(audio) // 2), audio)
        return values.count(self.value(hotel_config()["greeting"])) / 8000

    def test_barge_in_speech_stops_the_greeting(self):
        self.long_prompts()
        self.stt.texts = ["havlu lütfen"]
        u, c = self.call(barge_in=True, retries=0)
        self.assertTrue(self.wait(lambda: len(c.received()) > 8000 * 2 * 0.5))   # half a second of greeting
        c.speak(1.0)
        self.wait_done(c)
        self.assertLess(self.greeting_seconds(c), 2.5)
        self.assertEqual(self.result(u), "towels")
        data = self.details(u)[1]
        self.assertEqual(data["attempts"][0]["reason"], "speech")
        wav = wave.open(io.BytesIO(self.stt.calls[0]))
        self.assertGreaterEqual(wav.getnframes(), 8000 * 0.9)     # the start of what they said is kept

    def test_barge_in_key_stops_the_greeting(self):
        self.long_prompts()
        u, c = self.call(barge_in=True, retries=0)
        self.assertTrue(self.wait(lambda: len(c.received()) > 8000 * 2 * 0.5))
        c.dtmf("2")
        self.wait_done(c)
        self.assertLess(self.greeting_seconds(c), 2.5)
        data = self.details(u)[1]
        self.assertEqual((data["dtmf"], data["attempts"][0]["reason"]), ("2", "dtmf"))

    def test_barge_in_is_on_only_when_asked(self):
        from aipbx_ai.voice_requests import validate_config
        self.assertFalse(validate_config(hotel_config(), self.manager)["barge_in"])
        self.assertFalse(validate_config(hotel_config(barge_in="yes"), self.manager)["barge_in"])
        self.assertTrue(validate_config(hotel_config(barge_in=True), self.manager)["barge_in"])
        config = validate_config(hotel_config(), self.manager)
        self.assertEqual((config["silence_seconds"], config["line_profile"]), (4, "normal"))
        config = validate_config(hotel_config(silence_seconds=8, line_profile="faint"), self.manager)
        self.assertEqual((config["silence_seconds"], config["line_profile"]), (8, "faint"))
        self.assertEqual(validate_config(hotel_config(line_profile="loud"), self.manager)["line_profile"], "normal")
        with self.assertRaises(ConfigError):
            validate_config(hotel_config(silence_seconds=30), self.manager)
        config = validate_config(hotel_config(speech_detection="silero", end_of_turn="smart"), self.manager)
        self.assertEqual((config["speech_detection"], config["end_of_turn"]), ("silero", "smart"))
        config = validate_config(hotel_config(speech_detection="x", end_of_turn=1), self.manager)
        self.assertEqual((config["speech_detection"], config["end_of_turn"]), ("standard", "fixed"))
        config = validate_config(hotel_config(barge_profile="noisy"), self.manager)
        self.assertEqual(config["barge_profile"], "noisy")
        config = validate_config(hotel_config(barge_profile="x"), self.manager)
        self.assertIsNone(config["barge_profile"])

    def test_without_barge_in_the_greeting_plays_out(self):
        self.long_prompts()
        u, c = self.call(retries=0, listen_seconds=2)
        self.assertTrue(self.wait(lambda: len(c.received()) > 8000 * 2 * 0.5))
        c.speak(1.0)                                  # over the greeting: not heard
        self.wait_done(c, timeout=20)
        self.assertGreaterEqual(self.greeting_seconds(c), 2.9)
        self.assertEqual(self.details(u)[1]["attempts"][0]["reason"], "silence")

    # ---- conversation -------------------------------------------------------

    def conversation_call(self, steps, **over):
        from unittest import mock
        steps = list(steps)
        prompts = []

        def answer(cfg, text, post=None):
            prompts.append(text)
            step = steps.pop(0)
            if isinstance(step, Exception):
                raise step
            return json.dumps(step)
        patcher = mock.patch("aipbx_ai.cloud_llm.answer_text", side_effect=answer)
        patcher.start()
        self.addCleanup(patcher.stop)
        cfg = dict(conversation=True, understand="cloud", retries=0,
                   llm_cloud={"provider": "openai", "api_key": "sk-test-1234567890"},
                   instructions="Kahvaltı 07-10 arası.",
                   tools=[{"name": "oda", "description": "oda bilgisi", "url": "https://crm.example.com/room"}])
        cfg.update(over)
        u, c = self.call(**cfg)
        return u, c, prompts

    def test_conversation_answers_then_transfers(self):
        self.stt.texts = ["kahvaltı saat kaçta", "bir de havlu lazım"]
        u, c, prompts = self.conversation_call([
            {"action": "reply", "say": "Kahvaltı yedi ile on arası."},
            {"action": "transfer", "intent": "towels", "say": "Havlunuz geliyor.", "summary": "Oda 12 iki havlu istiyor."}])
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.assertTrue(c.wait_prompts(2))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(c.prompts(), [self.value(hotel_config()["greeting"]), self.value("Kahvaltı yedi ile on arası."),
                                       self.value("Havlunuz geliyor.")])
        self.assertEqual(self.result(u), "towels")                  # the dialplan goes to the request's destination
        status, _, body = self.request("GET", f"/v1/calls/{u}/summary?key={KEY}", token=None)
        self.assertEqual((status, body.decode()), (200, "Oda 12 iki havlu istiyor."))   # handed over with the call
        self.assertEqual(self.request("GET", f"/v1/calls/{u}/summary?key=00", token=None)[2], b"")
        data = self.details(u)[1]
        self.assertEqual([m["role"] for m in data["conversation"]], ["caller", "assistant", "caller", "assistant"])
        self.assertIn("Caller: kahvaltı saat kaçta", prompts[1])
        self.assertIn("You: Kahvaltı yedi ile on arası.", prompts[1])

    def test_conversation_lookup_and_goodbye(self):
        from unittest import mock
        self.stt.texts = ["oda 12 temizlendi mi"]
        asked = []
        with mock.patch("aipbx_ai.calls.fetch_values", side_effect=lambda url: asked.append(url) or {"temiz": "evet"}):
            u, c, prompts = self.conversation_call([
                {"action": "lookup", "tool": "oda", "query": "12"},
                {"action": "end", "say": "Odanız temiz. İyi günler."}])
            self.assertTrue(c.wait_prompts(1))
            c.speak(0.8)
            self.wait_done(c)
        self.assertEqual(self.result(u), "_end")                    # goodbye: the dialplan hangs up
        self.assertIn("caller=101", asked[0])
        self.assertTrue(asked[0].endswith("&q=12"))
        self.assertIn('oda("12") -> {"temiz": "evet"}', prompts[1])

    def test_conversation_goodbye_ends_the_call_without_the_model(self):
        self.stt.texts = ["kahvaltı saat kaçta", "hayır teşekkürler"]
        u, c, prompts = self.conversation_call([
            {"action": "reply", "say": "Kahvaltı yedi ile on arası. Başka bir isteğiniz var mı?"}],
            end_on_goodbye=True, goodbye="Hoşça kalın.")
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.assertTrue(c.wait_prompts(2))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(len(prompts), 1)                           # the goodbye never reached the model
        self.assertEqual(c.prompts()[-1], self.value("Hoşça kalın."))
        self.assertEqual((self.result(u), self.details(u)[1]["result"]), ("_end", "ended"))
        self.assertTrue(self.poll(lambda: self.registry.log.recent(1)))
        self.assertTrue(any("the caller said goodbye" in n[1] for n in self.registry.log.recent(1)[0]["notes"]))

    def test_conversation_goodbye_needs_the_setting_and_a_fitting_question(self):
        self.stt.texts = ["merhaba", "teşekkürler"]
        u, c, prompts = self.conversation_call([
            {"action": "reply", "say": "Adınızı söyler misiniz?"},
            {"action": "end", "say": "Görüşürüz."}], end_on_goodbye=True, goodbye="Hoşça kalın.")
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.assertTrue(c.wait_prompts(2))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(len(prompts), 2)                           # thanks for a question's answer: the model decides
        self.assertEqual(self.result(u), "_end")

    def test_conversation_agent_goodbye_ends_the_call(self):
        self.stt.texts = ["teşekkür ederim ama bir şey soracağım"]
        u, c, prompts = self.conversation_call([
            {"action": "reply", "say": "Rica ederim, iyi günler dilerim."}], end_on_goodbye=True)
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(len(prompts), 1)
        self.assertEqual((self.result(u), self.details(u)[1]["result"]), ("_end", "ended"))

    def test_chosen_detectors_that_are_not_installed_are_noted_and_the_call_goes_on(self):
        self.stt.texts = ["merhaba"]
        u, c, prompts = self.conversation_call([{"action": "end", "say": "Görüşürüz."}],
                                               speech_detection="silero", end_of_turn="smart")
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(self.result(u), "_end")
        self.assertTrue(self.poll(lambda: self.registry.log.recent(1)))
        notes = [n[1] for n in self.registry.log.recent(1)[0]["notes"]]
        self.assertEqual(sum("Silero VAD is chosen but not installed" in n for n in notes), 1)      # once per call
        self.assertEqual(sum("Smart Turn is chosen but not installed" in n for n in notes), 1)

    def test_conversation_model_failure_goes_to_the_destination(self):
        self.stt.texts = ["merhaba"]
        u, c, _ = self.conversation_call([cloud_llm_error()])
        self.assertTrue(c.wait_prompts(1))
        c.speak(0.8)
        self.wait_done(c)
        self.assertEqual(self.result(u), "none")
        self.assertEqual(self.details(u)[1]["result"], "not_understood")
        self.assertTrue(self.poll(lambda: self.registry.log.recent(1)))
        notes = self.registry.log.recent(1)[0]["notes"]       # the provider error reaches the call log
        self.assertTrue(any("conversation model failed" in n[1] for n in notes))

    def test_conversation_gets_the_summary_of_the_assistant_before(self):
        self.stt.texts = ["evet"]
        from unittest import mock
        prompts = []

        def answer(cfg, text, post=None):
            prompts.append(text)
            return json.dumps({"action": "end", "say": "Görüşmek üzere."})
        with mock.patch("aipbx_ai.cloud_llm.answer_text", side_effect=answer):
            self.assertEqual(self.put_app(conversation=True, understand="cloud", retries=0,
                                          llm_cloud={"provider": "openai", "api_key": "sk-test-1234567890"})[0], 200)
            u = self.start(context="Kemal Bey faturasını soruyor.\x07")
            c = Caller(self.audio.server_address[1], u)
            self.callers.append(c)
            self.assertTrue(c.wait_prompts(1))
            c.speak(0.8)
            self.wait_done(c)
        self.assertIn("Before you (another assistant's summary): Kemal Bey faturasını soruyor.", prompts[0])
        self.assertEqual(self.result(u), "_end")

    def test_conversation_silence_asks_the_model_with_the_reason(self):
        u, c, prompts = self.conversation_call([
            {"action": "reply", "say": "Sizi duyamadım, kahvaltı saatini mi soruyorsunuz?"}], retries=1)
        self.wait_done(c)
        cfg = hotel_config()
        self.assertEqual(c.prompts(), [self.value(cfg["greeting"]),
                                       self.value("Sizi duyamadım, kahvaltı saatini mi soruyorsunuz?"),
                                       self.value(cfg["not_understood"])])
        self.assertEqual(len(prompts), 1)                  # the second silence is the limit: no model call
        self.assertIn("Phone line note: the caller said nothing (time 1;", prompts[0])
        self.assertEqual((self.result(u), self.details(u)[1]["result"]), ("none", "silence"))
        self.assertTrue(self.poll(lambda: self.registry.log.recent(1)))
        notes = [n[1] for n in self.registry.log.recent(1)[0]["notes"]]
        self.assertIn("caller turn: silence (2 of 2)", notes)

    def test_conversation_model_may_end_on_a_silent_turn(self):
        u, c, prompts = self.conversation_call([{"action": "end", "say": "Hoşça kalın."}], retries=1)
        self.wait_done(c)
        self.assertIn("Phone line note: the caller said nothing", prompts[0])
        self.assertEqual(self.result(u), "_end")           # the model's decision on a silent turn holds

    def test_conversation_silence_falls_back_to_the_fixed_retry(self):
        u, c, _ = self.conversation_call([cloud_llm_error()], retries=1)
        self.wait_done(c)
        cfg = hotel_config()
        self.assertEqual(c.prompts(), [self.value(cfg["greeting"]), self.value(cfg["retry"]),
                                       self.value(cfg["not_understood"])])
        self.assertEqual(self.details(u)[1]["result"], "silence")

    def test_conversation_needs_a_cloud_model(self):
        self.assertEqual(self.put_app(conversation=True)[0], 400)

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

    def test_faint_voice_below_speech_level(self):
        lst = Listener(7)
        self.assertEqual(lst.feed(self.frames(50, 25) + self.frames(400, 40) + self.frames(50, 200)), "silence")
        self.assertTrue(lst.faint())                       # too quiet for speech, clearly not line noise
        lst = Listener(7)
        self.assertEqual(lst.feed(self.frames(50, 25) + self.frames(400, 5) + self.frames(50, 200)), "silence")
        self.assertFalse(lst.faint())                      # a short click is not a voice
        lst = Listener(7)
        lst.feed(self.frames(50, 15) + self.frames(8000, 50) + self.frames(50, 60))
        self.assertFalse(lst.faint())                      # speech was heard

    def test_silence_seconds_and_line_profiles(self):
        lst = Listener(10, silence_seconds=6)
        self.assertIsNone(lst.feed(self.frames(50, 250)))                # 5 s: still listening
        self.assertEqual(lst.feed(self.frames(50, 50)), "silence")       # 6 s
        voice = self.frames(50, 25) + self.frames(500, 40) + self.frames(50, 60)   # RMS about 290
        self.assertEqual(Listener(7).feed(voice), "speech")
        self.assertEqual(Listener(7, profile="noisy").feed(voice + self.frames(50, 200)), "silence")
        quiet = self.frames(50, 25) + self.frames(300, 40) + self.frames(50, 60)   # RMS about 170
        self.assertEqual(Listener(7, profile="faint").feed(quiet), "speech")
        self.assertEqual(Listener(7).feed(quiet + self.frames(50, 200)), "silence")

    def test_barge_in_needs_a_longer_voice_than_listening(self):
        click = self.frames(50, 25) + self.frames(8000, 6) + self.frames(50, 200)       # 120 ms of sound: a cough
        self.assertEqual(Listener(3).feed(click), "speech")
        self.assertEqual(Listener(3, start_frames=12).feed(click), "silence")
        word = self.frames(50, 25) + self.frames(8000, 20) + self.frames(50, 60)        # 400 ms: someone speaking
        self.assertEqual(Listener(7, start_frames=12).feed(word), "speech")

    # ---- optional detectors (Silero VAD, Smart Turn), here with fakes --------------------------------

    class FakeVad:
        def __init__(self, probability):
            self.probability = probability

        def feed(self, frame):
            return self.probability

    class FakeTurn:
        def __init__(self, probabilities):
            self.probabilities, self.calls = list(probabilities), 0

        def complete(self, pcm):
            self.calls += 1
            value = self.probabilities.pop(0) if len(self.probabilities) > 1 else self.probabilities[0]
            if isinstance(value, Exception):
                raise value
            return value

    def speech_then_silence(self, seconds=3):
        return self.frames(50, 25) + self.frames(8000, 50) + self.frames(50, 50 * seconds)

    def test_voice_detector_gates_loud_noise(self):
        noise = self.frames(50, 25) + self.frames(8000, 50) + self.frames(50, 250)
        self.assertEqual(Listener(3, vad=self.FakeVad(0.9)).feed(noise), "speech")
        lst = Listener(3, vad=self.FakeVad(0.1))
        self.assertEqual(lst.feed(noise), "silence")              # loud, but not a voice: no turn starts
        self.assertFalse(lst.heard)

    def test_failing_voice_detector_falls_back_to_the_energy_level(self):
        class Broken:
            def feed(self, frame):
                raise RuntimeError("boom")
        lst = Listener(7, vad=Broken())
        self.assertEqual(lst.feed(self.speech_then_silence()), "speech")
        self.assertIn("voice detector failed", lst.turn_notes[0])

    def test_smart_turn_ends_a_finished_sentence_early(self):
        fixed = Listener(7)
        fixed.feed(self.speech_then_silence())
        turn = self.FakeTurn([0.9])
        smart = Listener(7, turn=turn)
        self.assertEqual(smart.feed(self.speech_then_silence()), "speech")
        self.assertEqual(len(fixed.frames) - len(smart.frames), 20)      # 400 ms instead of 800 ms of silence
        self.assertEqual(turn.calls, 1)
        self.assertIn("complete (p=0.90) after 400 ms", smart.turn_notes[0])

    def test_smart_turn_waits_for_an_unfinished_sentence_then_ends(self):
        fixed = Listener(7)
        fixed.feed(self.speech_then_silence())
        turn = self.FakeTurn([0.1])
        smart = Listener(7, turn=turn)
        self.assertEqual(smart.feed(self.speech_then_silence()), "speech")
        self.assertEqual(len(smart.frames) - len(fixed.frames), 40)      # 1.6 s instead of 0.8 s, then it ends anyway
        self.assertEqual(turn.calls, 3)                                  # looked at 400, 800 and 1200 ms
        self.assertIn("no sign that the caller is done", smart.turn_notes[0])

    def test_smart_turn_sees_the_caller_go_on_talking(self):
        turn = self.FakeTurn([0.1])
        lst = Listener(15, turn=turn)
        audio = self.frames(50, 25) + self.frames(8000, 30) + self.frames(50, 30) + self.frames(8000, 30) + self.frames(50, 120)
        self.assertEqual(lst.feed(audio), "speech")
        self.assertEqual(lst.utterance()[:0], b"")
        self.assertGreater(len(lst.utterance()), 60 * 320)               # the pause did not end the turn: both parts are in

    def test_failing_turn_model_falls_back_to_the_fixed_silence(self):
        fixed = Listener(7)
        fixed.feed(self.speech_then_silence())
        lst = Listener(7, turn=self.FakeTurn([RuntimeError("boom")]))
        self.assertEqual(lst.feed(self.speech_then_silence()), "speech")
        self.assertEqual(len(lst.frames), len(fixed.frames))
        self.assertIn("end-of-turn model failed", lst.turn_notes[0])

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

    def test_wav_pcm16_from_wav_works_without_numpy(self):
        import builtins
        import struct
        from aipbx_ai import wav as wav_mod

        orig_import = builtins.__import__

        def fake_import(name, globals=None, locals=None, fromlist=(), level=0):
            if name == "numpy":
                raise ImportError("No module named 'numpy'")
            return orig_import(name, globals, locals, fromlist, level)

        mono_8k = wav_mod.wav_bytes(b"\x00\x10\x00\x20" * 400, 8000)
        # Build a 16 kHz stereo WAV cleanly.
        stereo_frames = b"".join(struct.pack("<hh", 1000, 3000) for _ in range(160))
        stereo_header = struct.pack("<4sI4s4sIHHIIHH4sI", b"RIFF", 36 + len(stereo_frames), b"WAVE",
                                    b"fmt ", 16, 1, 2, 16000, 16000 * 4, 4, 16, b"data", len(stereo_frames))
        orig_np = builtins.__import__
        try:
            builtins.__import__ = fake_import
            pcm_m, sec_m = wav_mod.pcm16_from_wav(mono_8k)
            pcm_s, sec_s = wav_mod.pcm16_from_wav(stereo_header + stereo_frames)
        finally:
            builtins.__import__ = orig_np
        self.assertGreater(len(pcm_m), 1600)
        self.assertAlmostEqual(sec_m, 0.1, places=2)
        self.assertEqual(len(pcm_s), 320)
        self.assertAlmostEqual(sec_s, 0.01, places=3)

    def test_named_cloud_accounts_validate_and_speech_uses_call_language(self):
        from unittest.mock import patch
        from aipbx_ai.voice_requests import AppStore, ConfigError, validate_config

        cfg = validate_config({
            "type": "voice_requests",
            "tts_model": "cloud:openai_b",
            "tts_cloud": {"provider": "openai", "api_key": "sk-test-0123456789"},
            "stt_model": "cloud:deepgram_b",
            "stt_cloud": {"provider": "deepgram", "api_key": "dg-test-0123456789"},
            "greeting": "Merhaba",
            "intents": [{"id": "sales", "name": "Sales", "keywords": ["sales"]}],
        })
        self.assertEqual(cfg["stt_model"], "cloud:deepgram_b")
        with self.assertRaises(ConfigError):
            validate_config({
                "type": "voice_requests",
                "tts_model": "cloud:openai",
                "tts_cloud": {"provider": "openai", "api_key": "sk-test-0123456789"},
                "stt_model": "cloud:openai_b",
                "stt_cloud": {"provider": "deepgram", "api_key": "dg-test-0123456789"},
                "greeting": "Merhaba",
                "intents": [{"id": "sales", "name": "Sales", "keywords": ["sales"]}],
            })

        store = AppStore(manager=None)
        seen = []
        with patch("aipbx_ai.cloud_tts.synthesize", side_effect=lambda tc, text, lang, speed: seen.append(lang) or (b"\x00\x01" * 160)):
            store.speech(1, {**cfg, "language": "tr", "call_language": "en"}, "Hello")
        self.assertEqual(seen, ["en"])

    def test_endpointing_adapts_gradually_and_ignores_single_click(self):
        from aipbx_ai.voice_requests import END_FRAMES, Endpointing, Listener

        ep = Endpointing()
        self.assertEqual(ep.frames, END_FRAMES)
        ep.pause(10)
        self.assertEqual(ep.frames, 35)  # 700 ms, not snapped down to 500 ms

        ep2 = Endpointing()
        lst = Listener(7, endpointing=ep2)
        # Speech (15), 25 frames quiet (500 ms), single 20 ms loud click, then silence again: no pause recorded
        audio = self.frames(50, 15) + self.frames(6000, 10) + self.frames(50, 25) + self.frames(6000, 1) + self.frames(50, 50)
        lst.feed(audio)
        self.assertEqual(ep2.pauses, 0)


if __name__ == "__main__":
    unittest.main()
