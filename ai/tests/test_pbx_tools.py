import http.server
import json
import threading
import unittest

from aipbx_ai.pbx_tools import PbxToolsClient, as_openai_tools, format_for_prompt


class PbxToolsTest(unittest.TestCase):
    def test_schema_formatters(self) -> None:
        schemas = [
            {
                "name": "find_person",
                "description": "Find person",
                "confirm": False,
                "parameters": {"type": "object", "properties": {"query": {"type": "string"}}, "required": ["query"]},
            },
            {
                "name": "leave_message",
                "description": "Leave message",
                "confirm": True,
                "parameters": {"type": "object", "properties": {"recipient": {"type": "string"}, "message": {"type": "string"}}},
            },
        ]
        oa = as_openai_tools(schemas)
        self.assertEqual(2, len(oa))
        self.assertEqual("function", oa[0]["type"])
        self.assertEqual("find_person", oa[0]["name"])

        prompt = format_for_prompt(schemas)
        self.assertIn("find_person(query)", prompt)
        self.assertIn("requires caller confirmation", prompt)

    def test_client_execute_over_http(self) -> None:
        captured: dict = {}

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                length = int(self.headers.get("Content-Length", "0"))
                captured["path"] = self.path
                captured["body"] = json.loads(self.rfile.read(length).decode("utf-8"))
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps({"ok": True, "kind": "lookup", "text": "Ahmet (ext 7891)"}).encode("utf-8"))

            def log_message(self, format, *args):
                pass

        srv = http.server.HTTPServer(("127.0.0.1", 0), Handler)
        t = threading.Thread(target=srv.serve_forever, daemon=True)
        t.start()
        try:
            port = srv.server_address[1]
            client = PbxToolsClient(url=f"http://127.0.0.1:{port}/api/internal_ai_tools.php", key="secret123")
            res = client.execute("find_person", {"query": "Ahmet"}, caller={"caller": "100"})
            self.assertTrue(res["ok"])
            self.assertEqual("Ahmet (ext 7891)", res["text"])
            self.assertIn("key=secret123", captured["path"])
            self.assertEqual("find_person", captured["body"]["tool"])
        finally:
            srv.shutdown()
            srv.server_close()
            t.join(timeout=2.0)

    def test_conversation_pbx_tools_prompt_and_parse_step(self) -> None:
        from aipbx_ai import conversation

        cfg = {
            "instructions": "Help callers.",
            "intents": [{"id": "sales", "name": "Sales"}],
            "tools": [],
            "pbx_tools": [
                {
                    "name": "set_dnd",
                    "description": "Enable or disable DND",
                    "confirm": True,
                    "parameters": {"type": "object", "properties": {"extension": {"type": "string"}, "enabled": {"type": "boolean"}, "confirmed": {"type": "boolean"}}},
                }
            ],
        }
        p = conversation.prompt(cfg, [("caller", "Rahatsız etmeyini aç")], "tr")
        self.assertIn('"set_dnd": Enable or disable DND', p)
        self.assertIn("requires verbal caller confirmation: confirmed=true", p)

        step1 = conversation.parse_step(
            json.dumps({"action": "lookup", "tool": "set_dnd", "args": {"extension": "101", "enabled": True, "confirmed": True}}),
            cfg,
        )
        self.assertEqual("lookup", step1["action"])
        self.assertEqual("set_dnd", step1["tool"])
        self.assertEqual({"extension": "101", "enabled": True, "confirmed": True}, json.loads(step1["query"]))

        step2 = conversation.parse_step(
            json.dumps({"action": "lookup", "tool": "set_dnd", "query": "101"}),
            cfg,
        )
        self.assertEqual("101", step2["query"])

    def test_resolve_lookup_passes_did_verified_customer_and_handles_action(self) -> None:
        from types import SimpleNamespace
        from unittest.mock import Mock, patch
        import queue
        from aipbx_ai.calls import CallSession, VoiceRequestsCall

        session = CallSession(1, "test", "100", "200", "uuid-pbx-1")
        session.did = "02129998877"
        session.verified_customer = "CUST-42"
        session.config = {
            "conversation": True,
            "intents": [],
            "pbx_tools": [
                {
                    "name": "transfer_call",
                    "description": "Transfer",
                    "parameters": {"type": "object", "properties": {"target": {"type": "string"}}},
                }
            ],
            "pbx_tools_key": "k2",
        }
        call = VoiceRequestsCall(SimpleNamespace(results=Mock()), Mock(), session, SimpleNamespace(audio=queue.Queue(), gone=threading.Event()))
        with patch("aipbx_ai.pbx_tools.PbxToolsClient.execute", return_value={
            "ok": True,
            "kind": "action",
            "text": "Transferring to 7891.",
            "action": {"type": "transfer", "target": "7891"},
        }) as mock_exec:
            txt = call.resolve_lookup("transfer_call", {"target": "7891"})

        self.assertEqual("Transferring to 7891.", txt)
        caller_arg = mock_exec.call_args.kwargs["caller"]
        self.assertEqual("02129998877", caller_arg["did"])
        self.assertEqual("CUST-42", caller_arg["verified_customer"])
        self.assertEqual("7891", session.intent)
        self.assertEqual({"id": "7891", "name": "7891"}, call.matched)

        with patch("aipbx_ai.pbx_tools.PbxToolsClient.execute", return_value={
            "ok": True,
            "kind": "action",
            "text": "Goodbye.",
            "action": {"type": "hangup"},
        }):
            txt2 = call.resolve_lookup("transfer_call", {"target": "7891"})
        self.assertEqual("Goodbye.", txt2)
        self.assertEqual("_end", session.intent)


if __name__ == "__main__":
    unittest.main()
