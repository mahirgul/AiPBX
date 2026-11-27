import threading
import unittest
from unittest.mock import patch

from aipbx_ai.ari import AriError, AriSessionManager


class FakeAriClient:
    def __init__(self) -> None:
        self.calls: list[tuple] = []
        self.bridge_seq = 0

    def answer(self, channel_id: str) -> None:
        self.calls.append(("answer", channel_id))

    def create_bridge(self, bridge_type: str = "mixing") -> dict:
        self.bridge_seq += 1
        bid = f"br-{self.bridge_seq}"
        self.calls.append(("create_bridge", bridge_type, bid))
        return {"id": bid}

    def add_channel(self, bridge_id: str, channel_id: str) -> None:
        self.calls.append(("add_channel", bridge_id, channel_id))

    def remove_channel(self, bridge_id: str, channel_id: str) -> None:
        self.calls.append(("remove_channel", bridge_id, channel_id))

    def destroy_bridge(self, bridge_id: str) -> None:
        self.calls.append(("destroy_bridge", bridge_id))

    def external_media(self, **kwargs) -> dict:
        self.calls.append(("external_media", kwargs))
        return {"id": kwargs.get("channel_id") or "ext-1"}

    def continue_in_dialplan(self, channel_id: str, context: str = "", extension: str = "", priority: int = 1) -> None:
        self.calls.append(("continue", channel_id, context, extension, priority))

    def hangup(self, channel_id: str, reason: str = "normal") -> None:
        self.calls.append(("hangup", channel_id, reason))

    def originate(self, **kwargs) -> dict:
        self.calls.append(("originate", kwargs))
        return {"id": "orig-1"}

    def play(self, channel_id: str, media: str) -> dict:
        self.calls.append(("play", channel_id, media))
        return {}

    def send_dtmf(self, channel_id: str, dtmf: str, between: int = 100, duration: int = 100) -> None:
        self.calls.append(("send_dtmf", channel_id, dtmf, between, duration))


class AriSessionManagerTest(unittest.TestCase):
    def test_stasis_start_attaches_bridge_and_external_media(self) -> None:
        cli = FakeAriClient()
        mgr = AriSessionManager(cli, app_name="aipbx-ai", audiosocket_port=8791)
        mgr.handle_event({
            "type": "StasisStart",
            "args": ["7", "11111111-2222-3333-4444-555555555555"],
            "channel": {"id": "PJSIP/100-0001"},
        })

        self.assertIn(("answer", "PJSIP/100-0001"), cli.calls)
        self.assertIn(("create_bridge", "mixing", "br-1"), cli.calls)
        self.assertIn(("add_channel", "br-1", "PJSIP/100-0001"), cli.calls)
        self.assertIn(("add_channel", "br-1", "11111111-2222-3333-4444-555555555555"), cli.calls)

        ext_call = [c for c in cli.calls if c[0] == "external_media"][0][1]
        self.assertEqual("audiosocket", ext_call["encapsulation"])
        self.assertEqual("tcp", ext_call["transport"])
        self.assertEqual("127.0.0.1:8791", ext_call["external_host"])

        # Re-entering Stasis for the externalMedia leg must be ignored.
        before = len(cli.calls)
        mgr.handle_event({
            "type": "StasisStart",
            "args": [],
            "channel": {"id": "11111111-2222-3333-4444-555555555555"},
        })
        self.assertEqual(before, len(cli.calls))

        # Releasing returns caller to dialplan and destroys bridge + extMedia.
        self.assertTrue(mgr.release_call("11111111-2222-3333-4444-555555555555", continue_dialplan=True))
        self.assertIn(("destroy_bridge", "br-1"), cli.calls)
        self.assertIn(("continue", "PJSIP/100-0001", "", "", 1), cli.calls)

    UUID = "11111111-2222-3333-4444-555555555555"

    def not_yet_in_stasis(self, cli: "FakeAriClient") -> dict:
        """addChannel of the media channel answers 422 until the test lets it in."""
        gate = {"open": False}
        plain_add = cli.add_channel

        def add(bridge_id: str, channel_id: str) -> None:
            if channel_id == self.UUID and not gate["open"]:
                raise AriError('ARI POST /bridges/x/addChannel -> 422: {"message":"Channel not in Stasis application"}')
            plain_add(bridge_id, channel_id)

        cli.add_channel = add
        return gate

    def test_media_channel_joins_on_its_own_stasis_start_without_holding_the_event_thread(self) -> None:
        cli = FakeAriClient()
        gate = self.not_yet_in_stasis(cli)
        mgr = AriSessionManager(cli, app_name="aipbx-ai", audiosocket_port=8791)
        with patch("aipbx_ai.ari.time.sleep") as sleep:
            mgr.handle_event({"type": "StasisStart", "args": ["7", self.UUID], "channel": {"id": "PJSIP/100-0001"}})
        sleep.assert_not_called()                                   # no waiting on the event thread
        self.assertNotIn(("add_channel", "br-1", self.UUID), cli.calls)
        self.assertIn(self.UUID, mgr._pending_join)
        gate["open"] = True
        mgr.handle_event({"type": "StasisStart", "args": [], "channel": {"id": self.UUID}})
        self.assertIn(("add_channel", "br-1", self.UUID), cli.calls)
        self.assertEqual({}, mgr._pending_join)
        self.assertNotIn(("continue", "PJSIP/100-0001", "", "", 1), cli.calls)
        self.assertTrue(mgr.release_call(self.UUID))

    def test_media_channel_that_never_enters_stasis_sends_the_caller_back(self) -> None:
        cli = FakeAriClient()
        self.not_yet_in_stasis(cli)
        mgr = AriSessionManager(cli)
        errors: list[tuple] = []
        mgr.on_error = lambda uuid, text: errors.append((uuid, text))
        with patch("aipbx_ai.ari.JOIN_TIMEOUT", 0.05), patch("aipbx_ai.ari.log.warning"):
            mgr.handle_event({"type": "StasisStart", "args": ["7", self.UUID], "channel": {"id": "caller"}})
            for _ in range(100):
                if ("continue", "caller", "", "", 1) in cli.calls:
                    break
                threading.Event().wait(0.02)
        self.assertIn(("continue", "caller", "", "", 1), cli.calls)
        self.assertIn(("destroy_bridge", "br-1"), cli.calls)
        self.assertEqual(self.UUID, errors[0][0])
        self.assertIn("did not enter Stasis", errors[0][1])
        self.assertFalse(mgr.release_call(self.UUID))

    def test_caller_hanging_up_before_the_join_stops_the_timer(self) -> None:
        cli = FakeAriClient()
        self.not_yet_in_stasis(cli)
        mgr = AriSessionManager(cli)
        mgr.handle_event({"type": "StasisStart", "args": ["7", self.UUID], "channel": {"id": "caller"}})
        timer = mgr._pending_join[self.UUID][3]
        mgr.handle_event({"type": "ChannelDestroyed", "channel": {"id": "caller"}})
        self.assertEqual({}, mgr._pending_join)
        self.assertFalse(timer.is_alive() and not timer.finished.is_set())
        self.assertNotIn(("continue", "caller", "", "", 1), cli.calls)

    def test_media_setup_failure_releases_bridge_and_resumes_dialplan(self) -> None:
        cli = FakeAriClient()
        mgr = AriSessionManager(cli)
        with patch.object(cli, "external_media", side_effect=AriError("media unavailable")), patch("aipbx_ai.ari.log.exception"):
            mgr.handle_event({"type": "StasisStart", "args": ["7", "call-id"], "channel": {"id": "caller"}})
        self.assertIn(("destroy_bridge", "br-1"), cli.calls)
        self.assertIn(("continue", "caller", "", "", 1), cli.calls)
        self.assertFalse(mgr.release_call("call-id"))

    def test_warm_transfer_success_and_no_answer_fallback(self) -> None:
        cli = FakeAriClient()
        mgr = AriSessionManager(cli)
        uuid1 = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"
        mgr.handle_event({"type": "StasisStart", "args": ["1", uuid1], "channel": {"id": "caller-1"}})

        def answer_xfer():
            mgr.handle_event({"type": "StasisStart", "args": ["xfer", uuid1], "channel": {"id": "target-1"}})

        t = threading.Timer(0.05, answer_xfer)
        t.start()
        ok = mgr.transfer_with_announce(uuid1, "PJSIP/200", announce_media="sound:custom/xfer", timeout=2)
        t.join()
        self.assertTrue(ok)
        self.assertIn(("play", "target-1", "sound:custom/xfer"), cli.calls)
        self.assertIn(("add_channel", "br-1", "target-1"), cli.calls)
        self.assertIn(("remove_channel", "br-1", uuid1), cli.calls)
        # Simulated AudioSocket close / on_finish after transfer must NOT tear down br-1 or pull caller-1 to dialplan.
        self.assertFalse(mgr.release_call(uuid1, continue_dialplan=True))
        self.assertNotIn(("destroy_bridge", "br-1"), cli.calls)
        self.assertNotIn(("continue", "caller-1", "", "", 1), cli.calls)

        # Unanswered transfer returns False so AI stays on the call.
        uuid2 = "ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee"
        mgr.handle_event({"type": "StasisStart", "args": ["1", uuid2], "channel": {"id": "caller-2"}})

        def fail_xfer():
            mgr.handle_event({"type": "ChannelDestroyed", "channel": {"id": "orig-1"}})

        t2 = threading.Timer(0.05, fail_xfer)
        t2.start()
        ok2 = mgr.transfer_with_announce(uuid2, "PJSIP/201", timeout=2)
        t2.join()
        self.assertFalse(ok2)

        # release_all cleanly continues remaining callers.
        mgr.release_all()
        self.assertIn(("continue", "caller-2", "", "", 1), cli.calls)

    def test_call_registry_on_finish_releases_ari_call(self) -> None:
        from unittest.mock import MagicMock
        from aipbx_ai.call_registry import CallRegistry
        from aipbx_ai.call_session import CallSession

        cli = FakeAriClient()
        mgr = AriSessionManager(cli)
        reg = CallRegistry(MagicMock(), "a" * 32)
        try:
            reg.on_finish = lambda call_uuid: mgr.release_call(call_uuid, continue_dialplan=True)
            sess = CallSession(5, "piper-tr-fahrettin", "100", "200", "1.1")
            sess.uuid = "99999999-1111-2222-3333-444444444444"
            mgr.handle_event({"type": "StasisStart", "args": ["5", sess.uuid], "channel": {"id": "caller-5"}})
            reg.finish(sess, "done")
            self.assertIn(("continue", "caller-5", "", "", 1), cli.calls)
        finally:
            reg.close()

    def test_dtmf_send_receive_and_rebridge(self) -> None:
        cli = FakeAriClient()
        mgr = AriSessionManager(cli)
        dtmf_seen: list[tuple[str, str]] = []
        mgr.on_dtmf = lambda u, d: dtmf_seen.append((u, d))
        uuid1 = "12345678-1111-2222-3333-444455556666"
        mgr.handle_event({"type": "StasisStart", "args": ["1", uuid1], "channel": {"id": "caller-9"}})

        mgr.handle_event({"type": "ChannelDtmfReceived", "digit": "5", "channel": {"id": "caller-9"}})
        self.assertEqual([(uuid1, "5")], dtmf_seen)

        self.assertTrue(mgr.send_dtmf_for_call(uuid1, "1000"))
        self.assertIn(("send_dtmf", "caller-9", "1000", 100, 100), cli.calls)

        self.assertTrue(mgr.rebridge_caller(uuid1, "br-outbound"))
        self.assertIn(("add_channel", "br-outbound", "caller-9"), cli.calls)
        self.assertFalse(mgr.release_call(uuid1, continue_dialplan=True))


if __name__ == "__main__":
    unittest.main()
