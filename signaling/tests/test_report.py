"""Tests for the optional reporting to a monitoring endpoint.

The endpoint is a real local HTTP server, so the request that leaves the
process is inspected as it arrives — headers, path and body — rather than a
mock's idea of it.
"""

from __future__ import annotations

import base64
import json
import os
import sys
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from aipbx_signaling import report  # noqa: E402
from aipbx_signaling.__main__ import Capture, supervise_reporting  # noqa: E402

from test_hep import INVITE, hep_packet  # noqa: E402


class Recorder(BaseHTTPRequestHandler):
    received: list = []
    status = 200
    lock = threading.Lock()

    def do_POST(self):  # noqa: N802 - BaseHTTPRequestHandler's name
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length).decode("utf-8", errors="replace")
        with self.lock:
            self.received.append({
                "path": self.path,
                "body": body,
                "node_key": self.headers.get("X-Echo-Node-Key"),
                "auth": self.headers.get("Authorization"),
                "user_agent": self.headers.get("User-Agent"),
            })
        self.send_response(self.status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"ok":true}')

    def log_message(self, *_args):
        """Silent: the test output is the assertion, not the access log."""


class RecorderServer:
    def __init__(self, status: int = 200):
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), Recorder)
        self.httpd.RequestHandlerClass.received = []
        self.httpd.RequestHandlerClass.status = status
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()

    @property
    def url(self) -> str:
        return "http://127.0.0.1:%d" % self.httpd.server_address[1]

    @property
    def received(self) -> list:
        return self.httpd.RequestHandlerClass.received

    def close(self) -> None:
        self.httpd.shutdown()
        self.httpd.server_close()


class TestReporter(unittest.TestCase):
    def setUp(self):
        self.server = RecorderServer()
        self.addCleanup(self.server.close)

    def test_packets_are_posted_as_base64_with_both_auth_headers(self):
        reporter = report.Reporter(self.server.url, "node-key-1", version="1.2.3")
        packet = hep_packet(INVITE.encode())
        reporter.enqueue(packet)
        self.assertTrue(reporter.send_batch(reporter.take_batch()))

        self.assertEqual(1, len(self.server.received))
        request = self.server.received[0]
        self.assertEqual("/api/hep", request["path"])
        self.assertEqual("node-key-1", request["node_key"])
        self.assertEqual("Bearer node-key-1", request["auth"])
        self.assertIn("aipbx-signaling/1.2.3", request["user_agent"])
        posted = json.loads(request["body"])["packets"]
        self.assertEqual(1, len(posted))
        # The endpoint receives the datagram itself, byte for byte.
        self.assertEqual(packet, base64.b64decode(posted[0]))
        self.assertEqual(1, reporter.sent_total)

    def test_heartbeat_names_the_platform(self):
        reporter = report.Reporter(self.server.url, "k", version="1.2.3")
        self.assertTrue(reporter.beat())
        request = self.server.received[0]
        self.assertEqual("/api/nodes/heartbeat", request["path"])
        payload = json.loads(request["body"])
        self.assertEqual("aipbx", payload["platform"])
        self.assertEqual("1.2.3", payload["version"])

    def test_trailing_slash_on_the_endpoint_is_tolerated(self):
        reporter = report.Reporter(self.server.url + "/", "k")
        reporter.beat()
        self.assertEqual("/api/nodes/heartbeat", self.server.received[0]["path"])

    def test_no_traffic_ever_goes_without_being_asked_to(self):
        """A reporter that was never started posts nothing."""
        report.Reporter(self.server.url, "k")
        time.sleep(0.1)
        self.assertEqual([], self.server.received)


class TestFailureHandling(unittest.TestCase):
    def setUp(self):
        self.server = RecorderServer(status=500)
        self.addCleanup(self.server.close)

    def test_a_refused_batch_is_kept_for_the_next_attempt(self):
        reporter = report.Reporter(self.server.url, "k")
        reporter.enqueue(b"packet-one")
        batch = reporter.take_batch()
        self.assertFalse(reporter.send_batch(batch))
        self.assertEqual(1, reporter.errors)
        self.assertIn("500", reporter.last_error)
        # Nothing is lost: the batch is back at the FRONT of the queue.
        self.assertEqual(1, len(reporter.queue))

    def test_an_unreachable_endpoint_is_reported_not_raised(self):
        reporter = report.Reporter("http://127.0.0.1:1", "k", timeout=2)
        reporter.enqueue(b"packet")
        self.assertFalse(reporter.send_batch(reporter.take_batch()))
        self.assertEqual(1, reporter.errors)
        self.assertTrue(reporter.last_error)

    def test_the_queue_is_bounded_and_drops_the_oldest(self):
        original = report.MAX_QUEUE
        report.MAX_QUEUE = 3
        try:
            reporter = report.Reporter(self.server.url, "k")
            for index in range(5):
                reporter.enqueue(b"packet-%d" % index)
            self.assertEqual(3, len(reporter.queue))
            self.assertEqual(2, reporter.dropped)
            # The three that survived are the newest ones.
            decoded = [base64.b64decode(p) for p in reporter.queue]
            self.assertEqual([b"packet-2", b"packet-3", b"packet-4"], decoded)
        finally:
            report.MAX_QUEUE = original


class TestRateLimitBackoff(unittest.TestCase):
    def test_a_rate_limited_endpoint_is_left_alone_for_a_while(self):
        """Echo answers 429 per node; sending again at once makes it worse."""
        server = RecorderServer(status=429)
        self.addCleanup(server.close)
        original = report.RATE_LIMIT_PAUSE
        report.RATE_LIMIT_PAUSE = 0.4
        try:
            reporter = report.Reporter(server.url, "k")
            reporter.enqueue(b"packet-one")
            self.assertFalse(reporter.send_batch(reporter.take_batch()))
            self.assertGreater(reporter.pause_until, time.monotonic())
            self.assertIn("429", reporter.last_error)

            def hep_calls() -> int:
                # Only the signal feed is rate limited: a heartbeat may still
                # go out while packets wait (Echo limits /api/hep alone).
                return len([r for r in server.received if r["path"] == "/api/hep"])

            # While paused no packets are posted, even with work waiting.
            reporter.enqueue(b"packet-two")
            thread = reporter.start()
            time.sleep(0.2)
            self.assertEqual(1, hep_calls(), "posted packets during the pause")
            # After the pause it resumes on its own.
            time.sleep(0.8)
            reporter.stop.set()
            thread.join(timeout=2)
            self.assertGreaterEqual(hep_calls(), 2, "never resumed")
        finally:
            report.RATE_LIMIT_PAUSE = original

    def test_a_plain_failure_does_not_pause(self):
        server = RecorderServer(status=500)
        self.addCleanup(server.close)
        reporter = report.Reporter(server.url, "k")
        reporter.enqueue(b"packet")
        reporter.send_batch(reporter.take_batch())
        self.assertEqual(0.0, reporter.pause_until)


class FakeSettingsStore:
    def __init__(self, values=None, explode=False):
        self.values = values or {}
        self.explode = explode

    def setting(self, key, default=""):
        if self.explode:
            raise RuntimeError("settings unavailable")
        return self.values.get(key, default)


class TestSettings(unittest.TestCase):
    def test_off_by_default(self):
        self.assertEqual((False, "", ""), report.settings_from(FakeSettingsStore()))

    def test_enabled_only_with_all_three_values(self):
        store = FakeSettingsStore({
            report.SETTING_ENABLED: "1",
            report.SETTING_ENDPOINT: "https://example.test/",
            report.SETTING_KEY: "abc",
        })
        enabled, endpoint, key = report.settings_from(store)
        self.assertTrue(enabled)
        self.assertEqual("https://example.test/", endpoint)
        self.assertEqual("abc", key)

    def test_a_half_configured_endpoint_stays_off(self):
        for missing in (report.SETTING_KEY, report.SETTING_ENDPOINT):
            values = {
                report.SETTING_ENABLED: "1",
                report.SETTING_ENDPOINT: "https://example.test/",
                report.SETTING_KEY: "abc",
            }
            del values[missing]
            self.assertEqual((False, "", ""),
                             report.settings_from(FakeSettingsStore(values)),
                             "missing %s must not send" % missing)

    def test_an_unreadable_setting_stays_off_and_does_not_raise(self):
        store = FakeSettingsStore(explode=True)
        self.assertEqual((False, "", ""), report.settings_from(store))


class TestSupervisor(unittest.TestCase):
    def setUp(self):
        self.server = RecorderServer()
        self.addCleanup(self.server.close)

    def test_reporting_starts_and_stops_with_the_portal_setting(self):
        values = {
            report.SETTING_ENABLED: "1",
            report.SETTING_ENDPOINT: self.server.url,
            report.SETTING_KEY: "k",
        }
        store = FakeSettingsStore(values)
        capture = Capture(store, source_check=False)
        supervise_reporting(capture, version="test", interval=0.05)
        try:
            capture.handle(hep_packet(INVITE.encode()), ("127.0.0.1", 1))
            deadline = time.time() + 5
            while not self.server.received and time.time() < deadline:
                time.sleep(0.05)
            self.assertTrue(self.server.received, "the enabled reporter sent nothing")
            self.assertIsNotNone(capture.reporter)

            # The administrator turns it off: sending must stop.
            store.values[report.SETTING_ENABLED] = "0"
            deadline = time.time() + 5
            while capture.reporter is not None and time.time() < deadline:
                time.sleep(0.05)
            self.assertIsNone(capture.reporter)
            sent = len(self.server.received)
            capture.handle(hep_packet(INVITE.encode()), ("127.0.0.1", 1))
            time.sleep(0.3)
            self.assertEqual(sent, len(self.server.received),
                             "something was sent after reporting was turned off")
        finally:
            capture.stop.set()

    def test_nothing_leaves_the_server_when_it_is_off(self):
        store = FakeSettingsStore()  # empty settings: off
        capture = Capture(store, source_check=False)
        supervise_reporting(capture, interval=0.05)
        try:
            capture.handle(hep_packet(INVITE.encode()), ("127.0.0.1", 1))
            time.sleep(0.3)
            self.assertIsNone(capture.reporter)
            self.assertEqual([], self.server.received)
        finally:
            capture.stop.set()


class TestSendProbe(unittest.TestCase):
    def test_a_working_endpoint_reports_success_in_words(self):
        server = RecorderServer()
        self.addCleanup(server.close)
        ok, sentence = report.test_send(server.url, "k")
        self.assertTrue(ok)
        self.assertIn("accepted", sentence)
        paths = [r["path"] for r in server.received]
        self.assertEqual(["/api/hep", "/api/nodes/heartbeat"], paths)

    def test_a_refusing_endpoint_explains_itself(self):
        server = RecorderServer(status=403)
        self.addCleanup(server.close)
        ok, sentence = report.test_send(server.url, "k")
        self.assertFalse(ok)
        self.assertIn("refused", sentence)
        self.assertIn("403", sentence)


if __name__ == "__main__":
    unittest.main()
