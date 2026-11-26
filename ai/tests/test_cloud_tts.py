"""Cloud text to speech: validation, request shapes, resampling and fallback."""
import base64
import io
import json
import os
import struct
import sys
import time
import unittest
import urllib.parse
import urllib.request
import wave
from unittest.mock import patch

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import cloud_tts  # noqa: E402
from aipbx_ai.calls import AudioSocketServer, CallRegistry, dialplan_key  # noqa: E402
from aipbx_ai.models import ModelManager, ModelSpec  # noqa: E402
from aipbx_ai import voice_requests  # noqa: E402


class _FakeResponse:
    def __init__(self, data: bytes):
        self._data = data

    def read(self, n=-1):
        return self._data if n < 0 else self._data[:n]

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


def _pcm_samples(n: int, value: int = 1000) -> bytes:
    return struct.pack("<h", value) * n


def _wav_8k(n: int) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(8000)
        w.writeframes(_pcm_samples(n, 700))
    return buf.getvalue()


class _FakeLocalBackend:
    def __init__(self, spec, data_dir=None):
        self.spec = spec
        self.calls = []
        self.texts = []

    def installed(self):
        return True

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
        return _pcm_samples(int(sample_rate * 0.1), 555)

    def transcribe(self, wav):
        self.calls.append(wav)
        return {"text": self.texts.pop(0) if self.texts else "", "seconds": 1.0}


class CloudTtsTest(unittest.TestCase):
    def test_validate(self):
        self.assertEqual(
            cloud_tts.validate({"provider": "openai", "api_key": " sk-123 ", "model": "tts-1", "voice": "nova"}),
            {"provider": "openai", "api_key": "sk-123", "model": "tts-1", "voice": "nova"},
        )
        self.assertEqual(
            cloud_tts.validate({"provider": "azure", "key": "az-key", "region": "westeurope"}),
            {"provider": "azure", "api_key": "az-key", "region": "westeurope"},
        )
        self.assertEqual(
            cloud_tts.validate({"provider": "openai_compat", "base_url": "http://192.168.1.50:8880/v1/", "model": "kokoro", "voice": "af_heart"}),
            {"provider": "openai_compat", "api_key": "", "model": "kokoro", "voice": "af_heart", "base_url": "http://192.168.1.50:8880/v1"},
        )
        for bad in (
            None,
            {"provider": "unknown", "api_key": "sk-123"},
            {"provider": "openai", "api_key": ""},
            {"provider": "openai", "api_key": "sk\n123"},
            {"provider": "azure", "api_key": "az-key"},
            {"provider": "azure", "api_key": "az-key", "region": "../bad"},
            {"provider": "elevenlabs", "api_key": "el-key", "endpoint": "http://127.0.0.1:8080"},
            {"provider": "openai_compat", "base_url": ""},
            {"provider": "openai_compat", "base_url": "http://tts.example.com/v1"},
        ):
            with self.assertRaises(ValueError):
                cloud_tts.validate(bad)

    def test_synthesize_all_providers_with_mocked_opener(self):
        seen = []

        def fake_open(req, timeout=None):
            seen.append((req.full_url, dict(req.header_items()), req.data))
            url = req.full_url
            if "openai.com" in url:
                # 24 kHz 0.1s -> 2400 samples -> resampled to ~800 samples at 8 kHz
                return _FakeResponse(_pcm_samples(2400, 1200))
            if "speech.microsoft.com" in url:
                return _FakeResponse(_pcm_samples(800, 900))
            if "elevenlabs.io" in url:
                # 16 kHz 0.1s -> 1600 samples -> resampled to ~800 samples at 8 kHz
                return _FakeResponse(_pcm_samples(1600, 1500))
            if "texttospeech.googleapis.com" in url:
                payload = json.dumps({"audioContent": base64.b64encode(_wav_8k(800)).decode()}).encode()
                return _FakeResponse(payload)
            raise AssertionError(f"unexpected URL {url}")

        with patch("aipbx_ai.cloud_tts.assert_public_url", side_effect=lambda u, **kw: u), \
             patch.object(cloud_tts._OPENER, "open", side_effect=fake_open):
            pcm_oai = cloud_tts.synthesize({"provider": "openai", "api_key": "sk-oai"}, "Merhaba", lang="tr")
            self.assertEqual(len(pcm_oai) % 2, 0)
            self.assertAlmostEqual(len(pcm_oai), 1600, delta=64)
            req_oai = json.loads(seen[-1][2].decode())
            self.assertEqual(req_oai["response_format"], "pcm")

            pcm_az = cloud_tts.synthesize({"provider": "azure", "api_key": "az-1", "region": "westeurope"}, "A & B <test>", lang="tr")
            self.assertEqual(len(pcm_az), 1600)
            self.assertEqual(dict((k.lower(), v) for k, v in seen[-1][1].items())["x-microsoft-outputformat"], "raw-8khz-16bit-mono-pcm")
            self.assertIn(b"A &amp; B &lt;test&gt;", seen[-1][2])

            pcm_el = cloud_tts.synthesize({"provider": "elevenlabs", "api_key": "el-1"}, "Selam", lang="tr")
            self.assertEqual(len(pcm_el) % 2, 0)
            self.assertAlmostEqual(len(pcm_el), 1600, delta=64)
            self.assertIn("output_format=pcm_16000", seen[-1][0])

            pcm_g = cloud_tts.synthesize({"provider": "google", "api_key": "g-key", "voice": "tr-TR-Wavenet-A"}, "Tamam", lang="tr")
            self.assertEqual(len(pcm_g), 1600)
            g_body = json.loads(seen[-1][2].decode())
            self.assertEqual(g_body["voice"]["languageCode"], "tr-TR")
            self.assertEqual(g_body["audioConfig"]["audioEncoding"], "LINEAR16")
            self.assertEqual(g_body["audioConfig"]["sampleRateHertz"], 8000)

            pcm_compat = cloud_tts.synthesize(
                {"provider": "openai_compat", "base_url": "http://127.0.0.1:8880/v1", "model": "nefes-0.5", "voice": "tr_1"},
                "Merhaba",
                lang="tr",
                request=lambda u, h, b: seen.append((u, h, b)) or _wav_8k(800),
            )
            self.assertEqual(len(pcm_compat), 1600)
            self.assertEqual(seen[-1][0], "http://127.0.0.1:8880/v1/audio/speech")
            self.assertNotIn("Authorization", seen[-1][1])
            compat_body = json.loads(seen[-1][2].decode())
            self.assertEqual(compat_body["response_format"], "wav")
            self.assertEqual(compat_body["model"], "nefes-0.5")

    def test_appstore_prompt_uses_cloud_tts_and_falls_back_to_local(self):
        backends = {}

        def factory(spec):
            backends[spec.id] = _FakeLocalBackend(spec)
            return backends[spec.id]

        mgr = ModelManager(
            (
                ModelSpec(
                    id="ema-lightning", title="EMA", kind="tts", languages=("tr",),
                    license="Apache-2.0", license_url="https://example.invalid",
                    homepage="https://example.invalid", download_mb=1, backend=_FakeLocalBackend,
                ),
            ),
            "/nonexistent",
            backend_factory=factory,
        )
        mgr.install("ema-lightning")
        deadline = time.monotonic() + 5
        while mgr.state("ema-lightning") != "ready" and time.monotonic() < deadline:
            time.sleep(0.01)
        store = voice_requests.AppStore(mgr, None)
        cfg = voice_requests.validate_config({
            "type": "voice_requests",
            "tts_model": "cloud:openai",
            "tts_cloud": {"provider": "openai", "api_key": "sk-oai-123456"},
            "tts_fallback": "ema-lightning",
            "stt_model": "cloud:deepgram",
            "stt_cloud": {"provider": "deepgram", "api_key": "dg-key-123456", "lang": "tr"},
            "greeting": "Hoş geldiniz",
            "intents": [{"id": "sales", "name": "Satış", "keywords": ["satış"], "reply": "Aktarıyorum"}],
        }, mgr)
        self.assertNotIn("sk-oai-123456", json.dumps(voice_requests.public(cfg)))

        with patch("aipbx_ai.cloud_tts.synthesize", return_value=_pcm_samples(400, 321)) as m_syn:
            pcm = store.prompt(1, 1, cfg, "greeting")
            self.assertEqual(pcm, _pcm_samples(400, 321))
            self.assertEqual(m_syn.call_count, 1)
            self.assertEqual(backends["ema-lightning"].calls, [])

        # Cloud error triggers transparent fallback to local TTS model.
        with patch("aipbx_ai.cloud_tts.synthesize", side_effect=cloud_tts.CloudTtsError("HTTP 503")):
            pcm_fb = store.prompt(1, 1, cfg, "reply:sales")
            self.assertEqual(pcm_fb, _pcm_samples(800, 555))
            self.assertEqual(backends["ema-lightning"].calls[0][0], "Aktarıyorum")


if __name__ == "__main__":
    unittest.main()
