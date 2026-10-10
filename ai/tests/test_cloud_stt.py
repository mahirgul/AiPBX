"""Cloud speech to text for voice requests: request shapes, validation, key hiding."""
import json
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import cloud_stt  # noqa: E402
from aipbx_ai import voice_requests  # noqa: E402

KEY = "sk-test-0123456789"


def hotel(**over):
    cfg = {"type": "voice_requests", "tts_model": "ema-lightning", "stt_model": "cloud:deepgram",
           "stt_cloud": {"provider": "deepgram", "api_key": KEY, "lang": "tr"},
           "greeting": "Merhaba", "intents": [{"id": "towels", "name": "Havlu", "keywords": ["havlu"], "reply": "Tamam"}]}
    cfg.update(over)
    return cfg


class CloudSttTest(unittest.TestCase):
    def test_validate(self):
        self.assertEqual(cloud_stt.validate({"provider": "groq", "api_key": KEY + " ", "lang": "de"}),
                         {"provider": "groq", "api_key": KEY, "lang": "de", "region": "westeurope"})
        for bad in (None, {"provider": "x", "api_key": KEY}, {"provider": "groq", "api_key": "short"},
                    {"provider": "groq", "api_key": KEY, "lang": "fr"}, {"provider": "groq", "api_key": KEY + "\nX"},
                    {"provider": "azure", "api_key": KEY, "region": "../x"}):
            with self.assertRaises(ValueError):
                cloud_stt.validate(bad)

    def test_request_shapes(self):
        calls = []

        def fake(answer):
            def req(url, headers, body):
                calls.append((url, headers, body))
                return answer
            return req
        wav = b"RIFF0000WAVEfmt "
        self.assertEqual(cloud_stt.transcribe({"provider": "deepgram", "api_key": KEY, "lang": "tr"}, wav,
                         fake({"results": {"channels": [{"alternatives": [{"transcript": " iki havlu "}]}]}})), "iki havlu")
        self.assertIn("language=tr", calls[-1][0])
        self.assertEqual(calls[-1][1]["Authorization"], "Token " + KEY)
        self.assertEqual(calls[-1][2], wav)
        self.assertEqual(cloud_stt.transcribe({"provider": "groq", "api_key": KEY, "lang": "de"}, wav, fake({"text": "Hallo"})), "Hallo")
        self.assertIn(b'name="language"\r\n\r\nde', calls[-1][2])
        self.assertIn(b"whisper-large-v3-turbo", calls[-1][2])
        self.assertEqual(cloud_stt.transcribe({"provider": "azure", "api_key": KEY, "lang": "en", "region": "westeurope"}, wav,
                         fake({"DisplayText": "Hello."})), "Hello.")
        self.assertIn("language=en-US", calls[-1][0])
        out = cloud_stt.transcribe({"provider": "google_ai", "api_key": KEY, "lang": "tr"}, wav,
                                   fake({"candidates": [{"content": {"parts": [{"text": "klima çalışmıyor"}]}}]}))
        self.assertEqual(out, "klima çalışmıyor")
        self.assertIn("Turkish", json.loads(calls[-1][2])["contents"][0]["parts"][0]["text"])
        self.assertEqual(cloud_stt.transcribe({"provider": "deepgram", "api_key": KEY, "lang": "tr"}, wav, fake({})), "")

    def test_config_with_cloud_stt_and_key_hidden(self):
        cfg = voice_requests.validate_config(hotel(), None)
        self.assertEqual(cfg["stt_cloud"]["api_key"], KEY)
        shown = voice_requests.public(cfg)
        self.assertNotIn("api_key", shown["stt_cloud"])
        self.assertTrue(shown["stt_cloud"]["api_key_set"])
        self.assertNotIn(KEY, json.dumps(shown))
        with self.assertRaises(voice_requests.ConfigError):
            voice_requests.validate_config(hotel(stt_cloud={"provider": "groq", "api_key": KEY}), None)
        with self.assertRaises(voice_requests.ConfigError):
            voice_requests.validate_config(hotel(stt_model="cloud:nope"), None)
        with self.assertRaises(voice_requests.ConfigError):
            voice_requests.validate_config(hotel(stt_cloud=None), None)

    def test_stored_config_is_private(self):
        with tempfile.TemporaryDirectory() as tmp:
            store = voice_requests.AppStore(None, tmp)
            store.put(5, hotel())
            path = Path(tmp) / "apps" / "5.json"
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            self.assertNotIn(KEY, json.dumps(store.list()))
            again = voice_requests.AppStore(None, tmp)
            self.assertEqual(again.get(5)[0]["stt_cloud"]["api_key"], KEY)


if __name__ == "__main__":
    unittest.main()
