import time
import unittest

from aipbx_ai.calls import CallRegistry, CallSession, PLAYING, Refused


class _FakeManager:
    def describe(self, model):
        return {"id": model, "kind": "tts", "state": "ready"}


class CallsPhase5Test(unittest.TestCase):
    def test_drain_refuses_new_calls_and_preserves_active(self):
        reg = CallRegistry(_FakeManager(), "secret-token")
        try:
            s = CallSession("tts", 1, "m1", "100", "200", "u1")
            reg._reserve(s, 4)
            self.assertEqual(reg.live_count(), 1)

            state = reg.drain(True)
            self.assertEqual(state, {"draining": True, "active": 1})
            self.assertTrue(reg.snapshot()["draining"])

            s2 = CallSession("tts", 1, "m1", "101", "200", "u2")
            with self.assertRaises(Refused) as ctx:
                reg._reserve(s2, 4)
            self.assertIn("draining", str(ctx.exception))
            self.assertEqual(reg.live_count(), 1)
        finally:
            reg.close()

    def test_sweep_enforces_max_call_seconds_timeout(self):
        reg = CallRegistry(_FakeManager(), "secret-token", max_call_seconds=5.0)
        try:
            s = CallSession("tts", 1, "m1", "100", "200", "u1")
            reg._reserve(s, 4)
            claimed = reg.claim(s.uuid)
            self.assertIsNotNone(claimed)
            self.assertEqual(s.state, PLAYING)
            s.connected = time.monotonic() - 10.0

            reg.sweep()
            self.assertEqual(reg.live_count(), 0)
            self.assertEqual(s.result, "timeout")
        finally:
            reg.close()


if __name__ == "__main__":
    unittest.main()
