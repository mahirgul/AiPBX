"""Conversation mode: settings, the model's steps, lookups (no network)."""
import json
import threading
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from aipbx_ai import calls, cloud_llm, conversation, voice_requests

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

    def test_language_added_by_the_administrator(self):
        self.assertIn("Speak French", conversation.prompt(dict(CFG, language_name="French"), [], "fr"))
        self.assertIn("Speak fr,", conversation.prompt(CFG, [], "fr"), "an unknown code is not turned into Turkish")

    def test_database_lookup_requires_registered_id_and_exact_parameters(self):
        cfg = dict(CFG, db_queries=[{"id": "query1", "description": "Payment status", "params": ["invoice_no"]}])
        step = {"action": "lookup", "tool": "get_db_value", "query_id": "query1", "params": {"invoice_no": "0042"}}
        parsed = conversation.parse_step(json.dumps(step), cfg)
        self.assertEqual(parsed["query"]["params"], {"invoice_no": "0042"})
        for bad in (dict(step, query_id="query2"), dict(step, params={"invoice_no": 42}),
                    dict(step, params={"invoice_no": "0042", "verified_customer_id": "42"})):
            with self.assertRaises(cloud_llm.CloudLlmError):
                conversation.parse_step(json.dumps(bad), cfg)

    def test_caller_name_in_greeting_and_model_context(self):
        config = dict(CFG, greeting="Merhaba $name, size nasıl yardımcı olabilirim?")
        self.assertEqual(voice_requests.AppStore.greeting_text(config, "Çağla"),
                         "Merhaba Çağla, size nasıl yardımcı olabilirim?")
        self.assertEqual(voice_requests.AppStore.greeting_text(config),
                         "Merhaba, size nasıl yardımcı olabilirim?")
        prompt = conversation.prompt(dict(config, caller_name="Çağla"), [], "tr")
        self.assertIn('Caller display name: "Çağla"', prompt)
        self.assertNotIn("Çağla", conversation.prompt(config, [], "tr"))

    def test_phonebook_names_keep_unicode_orthography(self):
        self.assertEqual(calls.display_name("  C\u0327ağla  Şen\n"), "Çağla Şen")
        self.assertEqual(calls.display_name("Jörg Müller‑Łukasz D’Angelo"), "Jörg Müller‑Łukasz D’Angelo")

    def test_builtin_agent_uses_knowledge_then_transfers_through_an_allowed_tool(self):
        cfg = dict(CFG, builtin_agent=True, knowledge=True)
        completions = iter([
            {"action": "lookup", "tool": "knowledge", "query": "return desk"},
            {"action": "call_transfer", "target_id": "sales", "say": "One moment."},
        ])
        prompts = []

        def complete(prompt):
            prompts.append(prompt)
            return json.dumps(next(completions))

        step = conversation.next_step(cfg, [("caller", "Where can I return a product?")], "en",
                                      lambda tool, query: "Sales handles returns.", complete=complete)
        self.assertEqual(step["intent"], "sales")
        self.assertIn("Sales handles returns.", prompts[1])
        self.assertIn('"action": "end_call"', prompts[0])
        with self.assertRaises(cloud_llm.CloudLlmError):
            conversation.parse_step('{"action":"call_transfer","target_id":"321"}', cfg)

    def test_automatic_announcements_use_tone_name_and_existing_waiting_audio(self):
        call = calls.VoiceRequestsCall.__new__(calls.VoiceRequestsCall)
        call.session = SimpleNamespace(app=1, pcm=b"prepared greeting")
        call.speak = lambda text: text.encode()
        cfg = {"automatic_announcements": True, "announcement_tone": "Resmi konuş.", "caller_name": "Ada",
               "greeting": "Merhaba.", "waiting_phrases": ["Bir saniye."]}

        def complete(prompt):
            self.assertIn("Resmi konuş.", prompt)
            self.assertIn('"caller_display_name": "Ada"', prompt)
            return '{"greeting":"Merhaba Ada.", "waiting":"Bir saniye."}'

        self.assertEqual(call.opening_audio(cfg, complete), b"Merhaba Ada.")
        self.assertEqual(call.automatic_waiting_key, "waiting:0")
        self.assertEqual(call.opening_audio(dict(cfg, automatic_announcements=False), complete), b"prepared greeting")

    def test_slow_lookup_announces_waiting_and_can_be_interrupted(self):
        call = calls.VoiceRequestsCall.__new__(calls.VoiceRequestsCall)
        call.session = SimpleNamespace(app=1, version=1)
        call.config = {"waiting_phrases": ["Bir saniye."]}
        call.registry = SimpleNamespace(apps=SimpleNamespace(cached=lambda *_: b"please wait"))
        call.waiting_spoken = False
        call.automatic_waiting_pcm, call.automatic_waiting_key = b"", ""
        finished, speech = threading.Event(), object()
        call.resolve_lookup = lambda *_: finished.wait(1) and "found"

        def interrupt(pcm):
            self.assertEqual(pcm, b"please wait")
            finished.set()
            return True, speech

        call.play_interruptible = interrupt
        with patch.object(calls, "LOOKUP_PROMPT_DELAY", 0.01):
            with self.assertRaises(calls.LookupInterrupted) as raised:
                call.lookup("knowledge", "payment")
        self.assertIs(raised.exception.pre, speech)
        self.assertTrue(call.waiting_spoken)

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
