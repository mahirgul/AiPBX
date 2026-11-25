import threading
import unittest
from unittest.mock import MagicMock

from aipbx_ai.calls import CallRegistry
from aipbx_ai.outbound_navigator import OutboundNavigator, decide_ivr_action, parse_ivr_decision


class _FakeAriCli:
    def __init__(self) -> None:
        self.calls: list[tuple] = []
        self.bridge_seq = 0

    def originate(self, **kwargs):
        self.calls.append(("originate", kwargs))
        return {"id": f"orig-{len(self.calls)}"}

    def create_bridge(self, bridge_type="mixing"):
        self.bridge_seq += 1
        bid = f"br-{self.bridge_seq}"
        self.calls.append(("create_bridge", bid))
        return {"id": bid}

    def add_channel(self, bridge_id, channel_id):
        self.calls.append(("add_channel", bridge_id, channel_id))

    def remove_channel(self, bridge_id, channel_id):
        self.calls.append(("remove_channel", bridge_id, channel_id))

    def destroy_bridge(self, bridge_id):
        self.calls.append(("destroy_bridge", bridge_id))

    def external_media(self, **kwargs):
        self.calls.append(("external_media", kwargs))
        return {"id": kwargs.get("channel_id") or "ext-1"}

    def hangup(self, channel_id, reason="normal"):
        self.calls.append(("hangup", channel_id))

    def send_dtmf(self, channel_id, dtmf, between=100, duration=100):
        self.calls.append(("send_dtmf", channel_id, dtmf))


class _FakeAriMgr:
    def __init__(self) -> None:
        self.client = _FakeAriCli()
        self.app_name = "aipbx-ai"
        self.audiosocket_host = "127.0.0.1"
        self.audiosocket_port = 8791
        self.ext: set[str] = set()
        self.rebridged: list[tuple[str, str]] = []
        self.caller_channels: dict[str, str] = {"caller-uuid-1": "caller-ch-1"}

    def get_caller_channel_id(self, call_uuid: str) -> str:
        return self.caller_channels.get(call_uuid, "")

    def register_ext_channel(self, cid):
        self.ext.add(cid)

    def unregister_ext_channel(self, cid):
        self.ext.discard(cid)

    def rebridge_caller(self, call_uuid, bridge_id):
        self.rebridged.append((call_uuid, bridge_id))
        return True


class OutboundNavigatorTest(unittest.TestCase):
    def test_parse_and_fallback_ivr_decision(self) -> None:
        self.assertEqual({"action": "dtmf", "digits": "0"}, parse_ivr_decision('{"action": "dtmf", "digits": "0"}'))
        self.assertEqual({"action": "connected", "digits": ""}, parse_ivr_decision('{"action": "connected"}'))
        self.assertEqual(
            {"action": "dtmf", "digits": "1000"},
            decide_ivr_action(None, "AFM Şirketine hoş geldiniz, dahili numarayı tuşlayınız", "", "1000", []),
        )
        self.assertEqual(
            {"action": "connected", "digits": ""},
            decide_ivr_action(None, "Merhaba efendim, nasıl yardımcı olabilirim?", "müşteri temsilcisi", "", []),
        )

    def test_outbound_navigation_sub_extension_and_hot_bridge(self) -> None:
        mgr = _FakeAriMgr()
        reg = CallRegistry(MagicMock(), "a" * 32)
        nav = OutboundNavigator(mgr, reg)
        try:
            res = nav.start_navigation(
                target_number="02125550000",
                requester_ext="1001",
                target_dtmf="1000",
                caller_call_uuid="caller-uuid-1",
                mode="wait",
            )
            self.assertTrue(res["ok"])
            job_id = res["job_id"]

            # Simulate remote channel answering via StasisStart, then hanging up right after bridge
            def drive_ari():
                import time
                time.sleep(0.05)
                nav.on_ari_event({
                    "type": "StasisStart",
                    "args": ["outbound_nav", job_id],
                    "channel": {"id": "out-ch-1"},
                })
                for _ in range(80):
                    st = (nav.get_job(job_id) or {}).get("state")
                    if st == "bridged":
                        break
                    time.sleep(0.1)
                nav.on_ari_event({
                    "type": "ChannelDestroyed",
                    "channel": {"id": "out-ch-1"},
                })

            dt = threading.Thread(target=drive_ari, daemon=True)
            dt.start()
            dt.join(timeout=8.0)

            self.assertIn(("send_dtmf", "out-ch-1", "1000"), mgr.client.calls)
            self.assertIn(("caller-uuid-1", "br-1"), mgr.rebridged)
            self.assertIn(("hangup", "caller-ch-1"), mgr.client.calls)
        finally:
            reg.close()

    def test_wait_mode_caller_hangup_cleans_up_remote_channel(self) -> None:
        mgr = _FakeAriMgr()
        reg = CallRegistry(MagicMock(), "a" * 32)
        nav = OutboundNavigator(mgr, reg)
        try:
            res = nav.start_navigation(
                target_number="02125550000",
                requester_ext="1001",
                caller_call_uuid="caller-uuid-1",
                mode="wait",
            )
            self.assertTrue(res["ok"])
            job_id = res["job_id"]

            def drive_ari():
                import time
                time.sleep(0.05)
                nav.on_ari_event({
                    "type": "StasisStart",
                    "args": ["outbound_nav", job_id],
                    "channel": {"id": "out-ch-wait"},
                })
                for _ in range(80):
                    if (nav.get_job(job_id) or {}).get("state") == "bridged":
                        break
                    time.sleep(0.1)
                nav.on_ari_event({
                    "type": "ChannelDestroyed",
                    "channel": {"id": "caller-ch-1"},
                })
                time.sleep(0.1)

            dt = threading.Thread(target=drive_ari, daemon=True)
            dt.start()
            dt.join(timeout=8.0)

            self.assertIn(("hangup", "out-ch-wait"), mgr.client.calls)
            self.assertNotIn(("hangup", "caller-ch-1"), mgr.client.calls)
        finally:
            reg.close()

    def test_callback_hangup_cleans_up_surviving_channel(self) -> None:
        mgr = _FakeAriMgr()
        reg = CallRegistry(MagicMock(), "a" * 32)
        nav = OutboundNavigator(mgr, reg)
        try:
            res = nav.start_navigation(
                target_number="02125550000",
                requester_ext="1001",
                mode="callback",
            )
            self.assertTrue(res["ok"])
            job_id = res["job_id"]

            def drive_ari():
                import time
                time.sleep(0.05)
                nav.on_ari_event({
                    "type": "StasisStart",
                    "args": ["outbound_nav", job_id],
                    "channel": {"id": "out-ch-2"},
                })
                for _ in range(50):
                    if any(c[0] == "originate" and "outbound_bridge" in c[1].get("app_args", "") for c in mgr.client.calls):
                        break
                    time.sleep(0.05)
                nav.on_ari_event({
                    "type": "StasisStart",
                    "args": ["outbound_bridge", job_id],
                    "channel": {"id": "cb-ch-2"},
                })
                for _ in range(50):
                    if (nav.get_job(job_id) or {}).get("state") == "bridged":
                        break
                    time.sleep(0.05)
                nav.on_ari_event({
                    "type": "ChannelDestroyed",
                    "channel": {"id": "out-ch-2"},
                })
                time.sleep(0.1)

            dt = threading.Thread(target=drive_ari, daemon=True)
            dt.start()
            dt.join(timeout=6.0)
            self.assertIn(("hangup", "cb-ch-2"), mgr.client.calls)
        finally:
            reg.close()


if __name__ == "__main__":
    unittest.main()
