"""Conversation mode: settings, the model's steps, lookups (no network)."""
import json
import unittest

from aipbx_ai import cloud_llm, conversation

INTENTS = [
    {"id": "sales", "name": "Sales", "keywords": ["price"], "examples": ["I want to buy"]},
    {"id": "support", "name": "Support", "keywords": ["broken"], "examples": []},
]
CFG = {"llm_cloud": {"provider": "openai", "api_key": "sk-test-1234567890", "model": "gpt-4.1-mini"},
       "intents": INTENTS, "instructions": "We are open 9-18.",
       "tools": [{"name": "order", "description": "order status by order number", "url": "https://crm.example.com/o"}]}


def scripted(*answers, seen=None):
    answers = list(answers)

    def post(url, headers, body):
        if seen is not None:
            seen.append(body["messages"][0]["content"])
        return {"choices": [{"message": {"content": json.dumps(answers.pop(0))}}]}
    return post


def ok(url):
    cloud_llm.assert_public_url(url, resolve=False)


class SettingsTest(unittest.TestCase):
    def test_valid(self):
        c = conversation.validate({"instructions": " Be kind. ", "max_turns": 4,
                                   "tools": [{"name": "order", "description": "d", "url": "https://crm.example.com/o"}]}, ok)
        self.assertEqual((c["instructions"], c["max_turns"], c["tools"][0]["name"]), ("Be kind.", 4, "order"))
        self.assertEqual(conversation.validate({}, ok)["max_turns"], conversation.MAX_TURNS_DEFAULT)

    def test_refused(self):
        for bad in ({"max_turns": 1}, {"max_turns": True}, {"instructions": "x" * 4001},
                    {"tools": [{"name": "Bad Name", "description": "d", "url": "https://h.example.com"}]},
                    {"tools": [{"name": "a", "description": "", "url": "https://h.example.com"}]},
                    {"tools": [{"name": "a", "description": "d", "url": "ftp://h.example.com"}]},
                    {"tools": [{"name": "a", "description": "d", "url": "http://127.0.0.1/x"}]},     # no own network
                    {"tools": [{"name": "a", "description": "d", "url": "http://10.0.0.5/x"}]},
                    {"tools": [{"name": "a", "description": "d", "url": "https://h.example.com"}] * 2},
                    {"tools": [{"name": f"t{i}", "description": "d", "url": "https://h.example.com"} for i in range(6)]}):
            with self.assertRaises(conversation.ConversationError, msg=json.dumps(bad)[:80]):
                conversation.validate(bad, ok)


class StepTest(unittest.TestCase):
    def test_parse(self):
        p = lambda d: conversation.parse_step(json.dumps(d), CFG)  # noqa: E731
        self.assertEqual(p({"action": "reply", "say": "Hello"}), {"action": "reply", "say": "Hello"})
        self.assertEqual(p({"action": "transfer", "intent": "none", "say": ""})["intent"], None)
        self.assertEqual(p({"action": "transfer", "intent": "sales"})["intent"], "sales")
        self.assertEqual(p({"action": "transfer", "intent": "sales", "summary": "x" * 500})["summary"], "x" * conversation.SUMMARY_CHARS)
        self.assertEqual(p({"action": "transfer", "intent": "sales", "summary": 5})["summary"], "")
        self.assertEqual(p({"action": "lookup", "tool": "order", "query": "A12"})["query"], "A12")
        self.assertEqual(len(p({"action": "reply", "say": "x" * 900})["say"]), conversation.SAY_CHARS)
        for bad in ({"action": "dial", "number": "0900"}, {"action": "transfer", "intent": "boss"},
                    {"action": "lookup", "tool": "shell"}, {"action": "reply", "say": ""}, "not json"):
            with self.assertRaises(cloud_llm.CloudLlmError):
                conversation.parse_step(bad if isinstance(bad, str) else json.dumps(bad), CFG)

    def test_lookup_then_reply(self):
        seen, asked = [], []
        history = [("caller", "where is my order A12")]
        step = conversation.next_step(CFG, history, "en", lambda t, q: asked.append((t, q)) or '{"status": "shipped"}',
                                      post=scripted({"action": "lookup", "tool": "order", "query": "A12"},
                                                    {"action": "reply", "say": "It has shipped."}, seen=seen))
        self.assertEqual(step, {"action": "reply", "say": "It has shipped."})
        self.assertEqual(asked, [("order", "A12")])
        self.assertIn('order("A12") -> {"status": "shipped"}', seen[1])
        self.assertIn("We are open 9-18.", seen[0])
        self.assertIn("Speak English", seen[0])

    def test_failed_lookup_is_told_to_the_model(self):
        seen = []

        def broken(t, q):
            raise ValueError("timed out")
        conversation.next_step(CFG, [("caller", "x")], "tr", broken,
                               post=scripted({"action": "lookup", "tool": "order", "query": "1"},
                                             {"action": "transfer", "intent": "none"}, seen=seen))
        self.assertIn("(the lookup failed: timed out)", seen[1])

    def test_lookups_are_limited(self):
        loop = {"action": "lookup", "tool": "order", "query": "1"}
        with self.assertRaises(cloud_llm.CloudLlmError):
            conversation.next_step(CFG, [("caller", "x")], "tr", lambda t, q: "{}", post=scripted(loop, loop, loop))


if __name__ == "__main__":
    unittest.main()
