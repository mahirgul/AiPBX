import http.client
import json
import tempfile
import threading
import time
import unittest

from aipbx_ai.calllog import CallLog, redact
from aipbx_ai.calls import CallRegistry, CallSession
from aipbx_ai.models import ModelManager
from aipbx_ai.server import AiServer

TOKEN = "t" * 64


class RedactTest(unittest.TestCase):
    def test_keys_and_tokens_never_stay(self):
        s = redact("HTTP 503 https://x/v1?key=AIzaSyA1234567890abcdefghij&a=1 Bearer abcdef1234567890 sk-" + "a" * 40)
        self.assertNotIn("AIzaSyA1234567890", s)
        self.assertNotIn("abcdef1234567890", s)
        self.assertNotIn("a" * 40, s)
        self.assertIn("503", s)

    def test_cut_short(self):
        self.assertEqual(200, len(redact("x " * 500)))


class CallLogTest(unittest.TestCase):
    def test_newest_first_and_old_ones_dropped(self):
        d = tempfile.mkdtemp()
        log = CallLog(d, keep=3, max_age_days=1)
        now = int(time.time())
        log.add({"uuid": "old", "ended": now - 3 * 86400})
        for i in range(5):
            log.add({"uuid": str(i), "ended": now})
        got = [r["uuid"] for r in log.recent(10)]
        self.assertEqual(["4", "3", "2"], got)         # the newest 3, "old" is past its age


class SessionRecordTest(unittest.TestCase):
    def make(self):
        d = tempfile.mkdtemp()
        reg = CallRegistry(ModelManager((), d), TOKEN, data_dir=d)
        s = CallSession(7, "ema-lightning", "1000", "", "u1", kind="voice_requests")
        return reg, s

    def test_finish_writes_one_record_with_the_notes_and_the_pacing(self):
        reg, s = self.make()
        s.note("conversation model failed (HTTP 503 ?key=AIzaSyA1234567890abcdef)")
        s.late(0.5)
        s.resyncs += 1
        reg._live[s.uuid] = s
        reg.finish(s, "expired")                        # the failure of the customer's first day is recorded too
        rec = reg.log.recent(5)[0]
        self.assertEqual("expired", rec["result"])
        self.assertEqual(500, rec["max_late_ms"])
        self.assertEqual(1, rec["resyncs"])
        self.assertIn("503", rec["notes"][0][1])
        self.assertNotIn("AIzaSyA1234567890", json.dumps(rec))
        reg.close()

    def test_registry_note_reaches_a_live_call_only(self):
        reg, s = self.make()
        reg._live[s.uuid] = s
        reg.note(s.uuid, "ARI could not join")
        reg.note("nobody", "x")                         # unknown call: no error
        self.assertEqual(1, len(s.notes))
        reg.close()


class EndpointTest(unittest.TestCase):
    def test_bearer_only_never_the_application_key(self):
        d = tempfile.mkdtemp()
        reg = CallRegistry(ModelManager((), d), TOKEN, data_dir=d)
        srv = AiServer(("127.0.0.1", 0), TOKEN, ModelManager((), d), calls=reg)
        threading.Thread(target=srv.serve_forever, daemon=True).start()

        def get(path, headers=None):
            c = http.client.HTTPConnection("127.0.0.1", srv.server_address[1], timeout=5)
            c.request("GET", path, headers=headers or {})
            r = c.getresponse()
            return r.status, r.read()

        self.assertEqual(401, get("/v1/calls/log")[0])
        self.assertEqual(401, get("/v1/calls/log?key=" + "a" * 64)[0])
        status, body = get("/v1/calls/log?limit=5", {"Authorization": "Bearer " + TOKEN})
        self.assertEqual(200, status)
        self.assertEqual({"calls": []}, json.loads(body))
        srv.shutdown(); srv.server_close(); reg.close()


if __name__ == "__main__":
    unittest.main()
