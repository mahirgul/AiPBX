"""Per-account settings of the cloud services: model, temperature, voice, format ... (defaults = the old constants)."""
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import cloud_llm, cloud_stt, cloud_tts  # noqa: E402

KEY = "sk-test-0123456789"


class LlmSettingsTest(unittest.TestCase):
    def test_validation(self):
        out = cloud_llm.validate({"provider": "openai", "api_key": KEY, "temperature": 0.7, "max_tokens": 300})
        self.assertEqual((out["temperature"], out["max_tokens"]), (0.7, 300))
        plain = cloud_llm.validate({"provider": "openai", "api_key": KEY})
        self.assertNotIn("temperature", plain)
        ref = cloud_llm.validate({"provider": "openai", "secret_ref": "app:1:llm:openai", "model": "gpt-x", "temperature": 1})
        self.assertEqual((ref["model"], ref["temperature"]), ("gpt-x", 1.0))
        for bad in ({"temperature": 2.5}, {"temperature": True}, {"temperature": "hot"}, {"max_tokens": 8},
                    {"max_tokens": 5000}, {"max_tokens": 20.5}, {"model": "bad model"}):
            with self.assertRaises(ValueError, msg=str(bad)):
                cloud_llm.validate({"provider": "openai", "api_key": KEY, **bad})

    def test_request_uses_the_settings_and_the_old_defaults(self):
        seen = []

        def post(url, headers, body):
            seen.append(body)
            if "generativelanguage" in url:
                return {"candidates": [{"content": {"parts": [{"text": "{}"}]}}]}
            if "anthropic" in url:
                return {"content": [{"text": "{}"}]}
            return {"choices": [{"message": {"content": "{}"}}]}

        for provider in ("openai", "google_ai", "claude"):
            cloud_llm.answer_text({"provider": provider, "api_key": KEY}, "x", post=post)
        self.assertEqual(seen[0]["temperature"], 0)
        self.assertNotIn("max_tokens", seen[0])
        self.assertEqual(seen[1]["generationConfig"]["temperature"], 0)
        self.assertEqual((seen[2]["temperature"], seen[2]["max_tokens"]), (0, 512))
        seen.clear()
        for provider in ("openai", "google_ai", "claude"):
            cloud_llm.answer_text({"provider": provider, "api_key": KEY, "temperature": 0.4, "max_tokens": 100}, "x", post=post)
        self.assertEqual((seen[0]["temperature"], seen[0]["max_tokens"]), (0.4, 100))
        self.assertEqual(seen[1]["generationConfig"]["temperature"], 0.4)
        self.assertEqual(seen[1]["generationConfig"]["maxOutputTokens"], 100)
        self.assertEqual((seen[2]["temperature"], seen[2]["max_tokens"]), (0.4, 100))


class SttSettingsTest(unittest.TestCase):
    def _urls(self, provider, **extra):
        seen = []

        def request(url, headers, body):
            seen.append((url, body))
            return {}
        cfg = {"provider": provider, "api_key": KEY, "lang": "tr", **extra}
        cloud_stt.transcribe(cloud_stt.validate(cfg), b"RIFF", request=request)
        return seen[0]

    def test_models_default_to_the_old_constants_and_follow_the_account(self):
        self.assertIn("model=nova-3&", self._urls("deepgram")[0])
        self.assertIn("model=nova-2&", self._urls("deepgram", model="nova-2")[0])
        self.assertIn(b"gpt-4o-mini-transcribe", self._urls("openai")[1])
        self.assertIn(b"gpt-4o-transcribe", self._urls("openai", model="gpt-4o-transcribe")[1])
        self.assertIn(b"whisper-large-v3-turbo", self._urls("groq")[1])
        self.assertIn(b"scribe_v1", self._urls("elevenlabs")[1])
        self.assertIn(b"scribe_v2", self._urls("elevenlabs", model="scribe_v2")[1])

    def test_unsafe_model_is_refused(self):
        with self.assertRaises(ValueError):
            cloud_stt.validate({"provider": "deepgram", "api_key": KEY, "model": "nova&x=1"})
        ref = cloud_stt.validate({"provider": "deepgram", "secret_ref": "app:2:stt:deepgram", "model": "nova-2"})
        self.assertEqual(ref["model"], "nova-2")
        with self.assertRaises(ValueError):
            cloud_stt.validate({"provider": "deepgram", "secret_ref": "app:2:stt:deepgram", "model": "a b"})


class TtsSettingsTest(unittest.TestCase):
    def test_format_validation_and_request(self):
        self.assertEqual(cloud_tts.validate({"provider": "openai", "api_key": KEY, "format": "wav"})["format"], "wav")
        with self.assertRaises(ValueError):
            cloud_tts.validate({"provider": "openai", "api_key": KEY, "format": "mp3"})
        ref = cloud_tts.validate({"provider": "openai", "secret_ref": "app:3:tts:openai", "voice": "nova", "format": "pcm"})
        self.assertEqual((ref["voice"], ref["format"]), ("nova", "pcm"))
        bodies = []

        def post(url, headers, body):
            bodies.append(json.loads(body))
            return b"\x00\x00" * 240
        cloud_tts.synthesize({"provider": "openai", "api_key": KEY}, "hi", request=post)
        cloud_tts.synthesize({"provider": "openai_compat", "base_url": "http://10.0.0.5/v1", "api_key": ""}, "hi", request=post)
        cloud_tts.synthesize({"provider": "openai", "api_key": KEY, "format": "wav", "model": "tts-x"}, "hi", request=post)
        self.assertEqual([b["response_format"] for b in bodies], ["pcm", "wav", "wav"])
        self.assertEqual(bodies[2]["model"], "tts-x")


if __name__ == "__main__":
    unittest.main()
