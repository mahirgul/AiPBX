"""Cloud language model as the understanding of voice requests (no network)."""
import json
import unittest

from aipbx_ai import cloud_llm

INTENTS = [
    {"id": "muhasebe", "name": "Muhasebe", "keywords": ["fatura"], "examples": ["ödeme yapmak istiyorum"]},
    {"id": "destek", "name": "Teknik destek", "keywords": ["arıza"], "examples": ["cihazım çalışmıyor"]},
]
CFG = {"provider": "openai", "api_key": "sk-test-1234567890", "model": "gpt-4.1-mini"}


def fake(answer, seen=None):
    def post(url, headers, body):
        if seen is not None:
            seen.append((url, headers, body))
        if "generativelanguage" in url:
            return {"candidates": [{"content": {"parts": [{"text": answer}]}}]}
        return {"choices": [{"message": {"content": answer}}]}
    return post


class CloudLlmTest(unittest.TestCase):
    def test_picks_intent(self):
        r = cloud_llm.classify(CFG, "kredi kartıyla ödeyebilir miyim", INTENTS,
                               post=fake('{"intent": "muhasebe", "confidence": 0.9}'))
        self.assertEqual(r, {"intent": "muhasebe", "confidence": 0.9})

    def test_none_for_other_wishes(self):
        r = cloud_llm.classify(CFG, "bir pizza istiyorum", INTENTS, post=fake('{"intent": "none", "confidence": 0.95}'))
        self.assertIsNone(r["intent"])

    def test_unknown_id_and_garbage_raise(self):
        with self.assertRaises(cloud_llm.CloudLlmError):
            cloud_llm.classify(CFG, "x", INTENTS, post=fake('{"intent": "pizza"}'))
        with self.assertRaises(cloud_llm.CloudLlmError):
            cloud_llm.classify(CFG, "x", INTENTS, post=fake("I think accounting"))

    def test_json_inside_text_is_found(self):
        r = cloud_llm.classify(CFG, "x", INTENTS, post=fake('Sure: {"intent": "destek", "confidence": 0.7} done'))
        self.assertEqual(r["intent"], "destek")

    def test_request_shapes(self):
        seen = []
        cloud_llm.classify(CFG, "arıza var", INTENTS, post=fake('{"intent": "destek"}', seen))
        url, headers, body = seen[0]
        self.assertTrue(url.endswith("/chat/completions"))
        self.assertEqual(headers["Authorization"], "Bearer sk-test-1234567890")
        self.assertEqual((body["temperature"], body["response_format"]["type"]), (0, "json_object"))
        self.assertIn('"destek"', body["messages"][0]["content"])
        seen = []
        g = cloud_llm.validate({"provider": "google_ai", "api_key": "AIza-test-123456"})
        cloud_llm.classify(g, "arıza var", INTENTS, post=fake('{"intent": "destek"}', seen))
        url, headers, body = seen[0]
        self.assertIn("gemini-2.5-flash:generateContent", url)
        self.assertEqual(headers["x-goog-api-key"], "AIza-test-123456")
        self.assertEqual(body["generationConfig"]["responseMimeType"], "application/json")

    def test_validate(self):
        self.assertEqual(cloud_llm.validate({"provider": "groq", "api_key": " gsk_1234567890 "})["model"],
                         cloud_llm.DEFAULT_MODELS["groq"])
        for bad in ({"provider": "azure", "api_key": "x" * 20}, {"provider": "openai", "api_key": "short"},
                    {"provider": "openai", "api_key": "x" * 20, "model": "bad model name"}):
            with self.assertRaises(ValueError):
                cloud_llm.validate(bad)

    def test_prompt_lists_requests(self):
        p = cloud_llm.prompt("merhaba", INTENTS)
        self.assertIn('id "muhasebe"', p)
        self.assertIn("examples: cihazım çalışmıyor", p)
        self.assertIn('"none"', p)
        json.dumps(p)


if __name__ == "__main__":
    unittest.main()
