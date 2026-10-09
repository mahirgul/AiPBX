"""Tests for the aipbx-ai HTTP API with a fake model backend (no model runtime needed).

    python3 -m unittest discover ai/tests
"""
import http.client
import json
import logging
import os
import struct
import sys
import threading
import time
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import models  # noqa: E402
from aipbx_ai.models import ModelManager, ModelSpec  # noqa: E402
from aipbx_ai.server import AiServer  # noqa: E402

TOKEN = "a" * 64
logging.disable(logging.CRITICAL)   # expected failures would log tracebacks


class FakeBackend:
    """Files are a flag; download/load/synthesize can be held with events."""

    def __init__(self, spec, data_dir=None):
        self.spec = spec
        self.files = False
        self.loaded = False
        self.fail_load = False
        self.download_gate = threading.Event()
        self.download_gate.set()
        self.synth_gate = threading.Event()
        self.synth_gate.set()
        self.synth_started = threading.Event()
        self.calls = []

    def installed(self):
        return self.files

    def disk_bytes(self):
        return 3 * 1024 * 1024 if self.files else 0

    def download(self, progress):
        progress(50)
        self.download_gate.wait(5)
        self.files = True
        progress(100)

    def load(self):
        if self.fail_load:
            raise RuntimeError("broken model file")
        self.loaded = True

    def unload(self):
        self.loaded = False

    def delete_files(self):
        self.files = False

    def synthesize(self, text, speed, sample_rate, on_first_audio=None):
        self.calls.append((text, speed, sample_rate))
        self.synth_started.set()
        self.synth_gate.wait(10)
        if on_first_audio:
            on_first_audio()
        return b"\x00\x00" * (sample_rate // 10)   # 0.1 s of silence


SPEC = ModelSpec(id="ema-lightning", title="EMA Lightning", kind="tts", languages=("tr",),
                 license="Apache-2.0", license_url="https://github.com/mahirgul/AiPBX/releases/tag/models-ema-lightning-1",
                 homepage="https://github.com/canberk7/ema-lightning", download_mb=36, backend=FakeBackend)
EMB = ModelSpec(id="fake-embed", title="Fake embedding", kind="embedding", languages=("tr",),
                license="Apache-2.0", license_url="https://example.invalid", homepage="https://example.invalid",
                download_mb=1, backend=FakeBackend)


class ApiTest(unittest.TestCase):
    def setUp(self):
        self.backends = {}

        def factory(spec):
            self.backends[spec.id] = FakeBackend(spec)
            return self.backends[spec.id]

        self.manager = ModelManager((SPEC, EMB), "/nonexistent", backend_factory=factory)
        self.fake = self.backends["ema-lightning"]
        self.server = AiServer(("127.0.0.1", 0), TOKEN, self.manager)
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.fake.synth_gate.set()
        self.fake.download_gate.set()
        self.server.shutdown()
        self.server.server_close()

    def request(self, method, path, body=None, token=TOKEN, headers=None):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=20)
        h = dict(headers or {})
        if token is not None:
            h["Authorization"] = "Bearer " + token
        data = None
        if body is not None:
            data = body if isinstance(body, bytes) else json.dumps(body).encode()
            h.setdefault("Content-Type", "application/json")
        conn.request(method, path, body=data, headers=h)
        resp = conn.getresponse()
        raw = resp.read()
        conn.close()
        ctype = resp.getheader("Content-Type", "")
        return resp.status, (json.loads(raw) if ctype.startswith("application/json") else raw), ctype

    def wait_state(self, want, timeout=5):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if self.manager.state("ema-lightning") == want:
                return
            time.sleep(0.02)
        self.fail(f"state never became {want}: {self.manager.state('ema-lightning')}")

    def make_ready(self):
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})
        self.assertEqual(status, 202)
        self.wait_state("ready")

    # ---- auth ------------------------------------------------------------

    def test_missing_token(self):
        status, body, _ = self.request("GET", "/v1/health", token=None)
        self.assertEqual(status, 401)
        self.assertEqual(body, {"error": "missing or invalid token"})

    def test_wrong_token(self):
        self.assertEqual(self.request("GET", "/v1/models", token="b" * 64)[0], 401)
        self.assertEqual(self.request("GET", "/v1/models", token="")[0], 401)
        self.assertEqual(self.request("GET", "/v1/models", token=None,
                                      headers={"Authorization": "Basic " + TOKEN})[0], 401)

    def test_forwarded_refused_even_with_token(self):
        status, body, _ = self.request("GET", "/v1/health", headers={"X-Forwarded-For": "10.0.0.1"})
        self.assertEqual(status, 403)
        self.assertIn("error", body)
        self.assertEqual(self.request("GET", "/v1/health", headers={"Forwarded": "for=1.2.3.4"})[0], 403)

    def test_auth_before_routing(self):
        self.assertEqual(self.request("GET", "/nope", token=None)[0], 401)
        self.assertEqual(self.request("GET", "/nope")[0], 404)
        self.assertEqual(self.request("GET", "/v1/tts")[0], 405)

    # ---- health / models -------------------------------------------------

    def test_health(self):
        status, body, _ = self.request("GET", "/v1/health")
        self.assertEqual(status, 200)
        self.assertIs(body["ok"], True)
        self.assertEqual(body["version"], "1")
        for key in ("python", "onnxruntime", "cpu", "ram", "process"):
            self.assertIn(key, body)
        self.assertGreaterEqual(body["cpu"]["cores"], 1)
        self.assertIsInstance(body["process"]["cpu_percent"], float)
        self.assertIsInstance(body["ram"]["total_mb"], int)

    def test_models_list(self):
        status, body, _ = self.request("GET", "/v1/models")
        self.assertEqual(status, 200)
        m = body["models"][0]
        self.assertEqual(m["id"], "ema-lightning")
        self.assertEqual(m["state"], "absent")
        self.assertEqual(m["languages"], ["tr"])
        self.assertEqual(m["license"], "Apache-2.0")
        self.assertEqual(m["progress"], 0)
        self.assertEqual(m["disk_mb"], 0)
        self.assertIsNone(m["error"])

    def test_registry_entry(self):
        (spec,) = [s for s in models.REGISTRY if s.id == "ema-lightning"]
        self.assertEqual(spec.kind, "tts")
        self.assertEqual(spec.download_mb, 34)

    # ---- state machine ---------------------------------------------------

    def test_install_needs_license(self):
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/install", {})[0], 400)
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": "yes"})[0], 400)
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/install")[0], 400)
        self.assertEqual(self.manager.state("ema-lightning"), "absent")

    def test_unknown_model(self):
        status, body, _ = self.request("POST", "/v1/models/nope/install", {"accept_license": True})
        self.assertEqual(status, 404)
        self.assertEqual(body["error"], "unknown model")

    def test_install_ready_remove(self):
        self.fake.download_gate.clear()
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})
        self.assertEqual((status, body), (202, {"state": "downloading"}))
        m = self.request("GET", "/v1/models")[1]["models"][0]
        self.assertEqual(m["state"], "downloading")
        # idempotent while downloading
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})[1],
                         {"state": "downloading"})
        # cannot remove while downloading
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/remove")[0], 409)
        self.fake.download_gate.set()
        self.wait_state("ready")
        m = self.request("GET", "/v1/models")[1]["models"][0]
        self.assertEqual((m["state"], m["progress"], m["disk_mb"]), ("ready", 100, 3))
        self.assertTrue(self.fake.loaded)
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})[1],
                         {"state": "ready"})

        status, body, _ = self.request("POST", "/v1/models/ema-lightning/remove")
        self.assertEqual((status, body), (200, {"state": "absent"}))
        self.assertFalse(self.fake.loaded)
        self.assertFalse(self.fake.files)

    def test_stop_and_run(self):
        self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})
        self.wait_state("ready")
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/stop", {})
        self.assertEqual((status, body["state"]), (200, "installed"))
        self.assertFalse(self.fake.loaded)
        self.assertTrue(self.fake.files)
        status, _, _ = self.request("POST", "/v1/tts", {"model": "ema-lightning", "text": "Merhaba"})
        self.assertEqual(status, 409)
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/run", {})
        self.assertEqual(status, 202)
        self.wait_state("ready")
        self.assertTrue(self.fake.loaded)

    def test_run_needs_files(self):
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/run", {})
        self.assertEqual((status, body["error"]), (409, "the model is not downloaded"))

    def test_models_report_disk(self):
        status, body, _ = self.request("GET", "/v1/models")
        self.assertEqual(status, 200)
        self.assertEqual(set(body["disk"]), {"used_mb", "limit_mb"})
        m = next(x for x in body["models"] if x["id"] == "ema-lightning")
        for key in ("engine", "commercial", "measured", "memory_mb"):
            self.assertIn(key, m)

    def test_disk_limit(self):
        self.manager.disk_limit_mb = 10   # the fake model is 36 MB
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})
        self.assertEqual(status, 507)
        self.assertEqual(self.manager.state("ema-lightning"), "absent")

    def test_stt_endpoint(self):
        status, body, _ = self.request("POST", "/v1/stt?model=ema-lightning", b"RIFF....", headers={"Content-Type": "audio/wav"})
        self.assertEqual((status, body["error"]), (400, "model is not a speech-to-text model"))
        status, body, _ = self.request("POST", "/v1/stt", b"x", headers={"Content-Type": "audio/wav"})
        self.assertEqual(status, 400)
        status, body, _ = self.request("POST", "/v1/stt?model=ema-lightning", b"x", headers={"Content-Type": "text/plain"})
        self.assertEqual((status, body["error"]), (400, "send the audio as audio/wav"))

    def test_start_loads_installed_models(self):
        self.fake.files = True
        self.assertEqual(self.manager.state("ema-lightning"), "installed")
        self.manager.start()
        self.wait_state("ready")

    def test_load_error_state(self):
        self.fake.fail_load = True
        self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})
        self.wait_state("error")
        m = self.request("GET", "/v1/models")[1]["models"][0]
        self.assertIn("broken model file", m["error"])
        self.fake.fail_load = False
        self.assertEqual(self.request("POST", "/v1/models/ema-lightning/install", {"accept_license": True})[1],
                         {"state": "loading"})   # files are there: load only
        self.wait_state("ready")

    # ---- tts -------------------------------------------------------------

    def tts(self, **kw):
        body = {"model": "ema-lightning", "text": "Merhaba", "speed": 1.0, "sample_rate": 8000}
        body.update(kw)
        return self.request("POST", "/v1/tts", body)

    def test_tts_not_ready(self):
        status, body, _ = self.tts()
        self.assertEqual((status, body), (409, {"error": "model not ready"}))

    def test_tts_validation(self):
        self.make_ready()
        for kw in ({"text": "   "}, {"text": "x" * 5001}, {"text": 5}, {"speed": 0.4}, {"speed": 2.1},
                   {"speed": "1"}, {"speed": True}, {"sample_rate": 22050}, {"sample_rate": "8000"},
                   {"model": ""}):
            status, body, _ = self.tts(**kw)
            self.assertEqual(status, 400, kw)
            self.assertIn("error", body)
        self.assertEqual(self.tts(model="nope")[0], 404)
        self.assertEqual(self.tts(model="fake-embed")[0], 400)  # not a tts model
        self.assertEqual(self.request("POST", "/v1/tts", b"{not json")[0], 400)
        self.assertEqual(self.request("POST", "/v1/tts", b"[1]")[0], 400)
        self.assertEqual(self.request("POST", "/v1/tts", b"x" * (200 * 1024))[0], 413)
        self.assertEqual(self.fake.calls, [])

    def test_tts_wav(self):
        self.make_ready()
        for rate in (8000, 16000, 24000, 48000):
            status, wav, ctype = self.tts(text="  " + "ş" * 5000 + "  ", sample_rate=rate, speed=0.5)
            self.assertEqual((status, ctype), (200, "audio/wav"))
            riff, size, wave, fmt, fmt_len, pcm, ch, sr, byte_rate, block, bits, data, data_len = \
                struct.unpack("<4sI4s4sIHHIIHH4sI", wav[:44])
            self.assertEqual((riff, wave, fmt, data), (b"RIFF", b"WAVE", b"fmt ", b"data"))
            self.assertEqual((pcm, ch, sr, bits, block, byte_rate), (1, 1, rate, 16, 2, rate * 2))
            self.assertEqual(data_len, len(wav) - 44)
            self.assertEqual(size, len(wav) - 8)
        self.assertEqual(self.fake.calls[0], ("ş" * 5000, 0.5, 8000))   # trimmed

    def test_tts_queue_limit(self):
        self.make_ready()
        self.fake.synth_gate.clear()
        results = []

        def worker():
            results.append(self.tts()[0])

        first = threading.Thread(target=worker)
        first.start()
        self.assertTrue(self.fake.synth_started.wait(5))
        waiting = [threading.Thread(target=worker) for _ in range(models.MAX_WAITING)]
        for t in waiting:
            t.start()
        deadline = time.monotonic() + 5
        while self.manager._entries["ema-lightning"].pending < 1 + models.MAX_WAITING:
            self.assertLess(time.monotonic(), deadline)
            time.sleep(0.02)
        status, body, _ = self.tts()
        self.assertEqual((status, body), (503, {"error": "too many requests waiting"}))
        self.fake.synth_gate.set()
        first.join(10)
        for t in waiting:
            t.join(10)
        self.assertEqual(results, [200] * (1 + models.MAX_WAITING))

    # ---- benchmark -------------------------------------------------------

    def test_benchmark_not_ready(self):
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/benchmark")
        self.assertEqual((status, body), (409, {"error": "model not ready"}))

    def test_benchmark(self):
        self.make_ready()
        status, body, _ = self.request("POST", "/v1/models/ema-lightning/benchmark")
        self.assertEqual(status, 200)
        self.assertEqual(set(body), {"audio_seconds", "first_audio_ms", "seconds", "realtime_factor"})
        self.assertAlmostEqual(body["audio_seconds"], 0.1)
        self.assertIsInstance(body["first_audio_ms"], int)
        self.assertEqual(self.fake.calls[-1][2], 8000)


class WavTest(unittest.TestCase):
    def test_odd_bytes(self):
        from aipbx_ai.wav import wav_bytes
        with self.assertRaises(ValueError):
            wav_bytes(b"\x00", 8000)


if __name__ == "__main__":
    unittest.main()


class StoppedChoiceTest(unittest.TestCase):
    """A stopped model stays stopped after a restart; the others load again."""

    def test_restart_keeps_the_choice(self):
        import tempfile
        with tempfile.TemporaryDirectory() as data:
            backends = {}

            def factory(spec):
                b = backends.get(spec.id) or FakeBackend(spec)
                b.files = True
                backends[spec.id] = b
                return b

            first = ModelManager((SPEC,), data, backend_factory=factory)
            first.start()
            deadline = time.monotonic() + 5
            while first.state("ema-lightning") != "ready" and time.monotonic() < deadline:
                time.sleep(0.02)
            first.stop("ema-lightning")
            second = ModelManager((SPEC,), data, backend_factory=factory)
            second.start()
            time.sleep(0.2)
            self.assertEqual(second.state("ema-lightning"), "installed")
            second.install("ema-lightning")      # run again clears the choice
            third = ModelManager((SPEC,), data, backend_factory=factory)
            self.assertNotIn("ema-lightning", third._stopped)
