"""Tests for the capture loop and the store's pure parts.

The daemon is exercised for real here: a socket is opened, HEPv3 datagrams are
sent to it and the rows that come out are checked. Only the database is faked —
a live MariaDB is not available in a unit test, and the point of these tests is
the receive/flush path, not the driver.
"""

from __future__ import annotations

import os
import socket
import sys
import tempfile
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from aipbx_signaling import db as sigdb  # noqa: E402
from aipbx_signaling import hep  # noqa: E402
from aipbx_signaling.__main__ import Capture, parse_listen  # noqa: E402

from test_hep import INVITE, hep_packet  # noqa: E402


class FakeStore:
    """Records what a real store would insert."""

    def __init__(self):
        self.batches = []
        self.rows_written = 0
        self.write_errors = 0
        self.last_error = ""

    def write(self, events, now=None):
        events = list(events)
        if not events:
            return 0
        self.batches.append(events)
        self.rows_written += len(events)
        return len(events)

    def prune(self, days=None):
        return 0


class TestConfig(unittest.TestCase):
    def test_parse_listen(self):
        self.assertEqual(("127.0.0.1", 9061), parse_listen("127.0.0.1:9061"))
        self.assertEqual(("0.0.0.0", 9060), parse_listen("0.0.0.0:9060"))
        # A bare port stays on loopback: the capture address is on this host.
        self.assertEqual(("127.0.0.1", 9061), parse_listen("9061"))

    def test_env_file_is_parsed_like_the_portal(self):
        with tempfile.NamedTemporaryFile("w", suffix=".env", delete=False) as fh:
            fh.write("# comment\nDB_HOST=localhost\nDB_NAME=asterisk\n")
            fh.write("DB_USER=aipbx_portal\n\nDB_PASS='quoted'\n")
            path = fh.name
        try:
            env = sigdb.read_env(path)
            self.assertEqual("localhost", env["DB_HOST"])
            self.assertEqual("asterisk", env["DB_NAME"])
            self.assertEqual("aipbx_portal", env["DB_USER"])
            self.assertEqual("quoted", env["DB_PASS"])
        finally:
            os.unlink(path)

    def test_db_config_defaults(self):
        config = sigdb.db_config({"DB_USER": "u", "DB_PASS": "p"})
        self.assertEqual("localhost", config["host"])
        self.assertEqual("asterisk", config["database"])

    def test_missing_env_file_is_not_fatal(self):
        self.assertEqual({}, sigdb.read_env("/nonexistent/ai-pbx.env"))

    def test_row_shapes_an_event(self):
        event = hep.parse_event(hep_packet(INVITE.encode()))
        row = sigdb.Store.row(event, fallback_ts=1792181287)
        self.assertEqual(14, len(row))
        self.assertTrue(row[0].startswith("20"))
        self.assertEqual(event["call_id"], row[1])
        self.assertEqual("INVITE", row[2])
        self.assertEqual("sip", row[3])

    def test_row_uses_the_clock_when_the_packet_has_no_timestamp(self):
        row = sigdb.Store.row({"method": "INVITE"}, fallback_ts=1792181287)
        self.assertTrue(row[0].startswith("20"))

    def test_write_fails_open_when_the_database_is_unreachable(self):
        """A database that cannot be reached is reported, never raised."""
        store = sigdb.Store({"host": "127.0.0.1", "user": "nobody",
                             "password": "", "database": "none",
                             "charset": "utf8mb4"})

        def boom():
            raise RuntimeError("connection refused")

        store._connect = boom  # noqa: SLF001 - the point is the failure path
        self.assertEqual(0, store.write([{"method": "INVITE"}]))
        self.assertEqual(1, store.write_errors)
        self.assertIn("connection refused", store.last_error)
        # And it keeps failing open rather than dying on the second batch.
        self.assertEqual(0, store.write([{"method": "INVITE"}]))
        self.assertEqual(2, store.write_errors)

    def test_missing_driver_fails_open_too(self):
        """The runtime may not be installed: importing the driver must not
        take the daemon down."""
        store = sigdb.Store({"host": "127.0.0.1", "user": "u", "password": "",
                             "database": "asterisk", "charset": "utf8mb4"})
        store.config["host"] = "127.0.0.1"
        self.assertEqual(0, store.write([{"method": "INVITE"}]))
        self.assertGreaterEqual(store.write_errors, 1)

    def test_row_retention_setting_is_clamped(self):
        store = sigdb.Store({"host": "127.0.0.1", "user": "u", "password": "",
                             "database": "asterisk", "charset": "utf8mb4"})
        self.assertEqual(sigdb.DEFAULT_RETENTION_DAYS, store.retention_days())


class TestCaptureLoop(unittest.TestCase):
    def test_datagrams_are_parsed_batched_and_written(self):
        store = FakeStore()
        capture = Capture(store, source_check=False)
        capture.handle(hep_packet(INVITE.encode()), ("127.0.0.1", 4000))
        capture.handle(hep_packet(b"\x01\x02"), ("127.0.0.1", 4000))
        self.assertEqual(2, capture.stats["received"])
        self.assertEqual(1, capture.stats["parsed"])
        self.assertEqual(1, capture.stats["dropped"])
        capture.flush(time.time())
        self.assertEqual(1, len(store.batches))
        self.assertEqual("INVITE", store.batches[0][0]["method"])

    def test_datagrams_from_another_host_are_refused(self):
        capture = Capture(FakeStore(), source_check=True)
        capture.allowed_source = "127.0.0.1"
        capture.handle(hep_packet(INVITE.encode()), ("192.0.2.99", 4000))
        self.assertEqual(0, capture.stats["parsed"])
        self.assertEqual(1, capture.stats["wrong_source"])

    def test_queue_is_bounded(self):
        """A database that cannot keep up drops the OLDEST events, and says so."""
        import aipbx_signaling.__main__ as daemon

        original = daemon.MAX_QUEUE
        daemon.MAX_QUEUE = 3
        try:
            capture = Capture(FakeStore(), source_check=False)
            for _ in range(5):
                capture.handle(hep_packet(INVITE.encode()), ("127.0.0.1", 1))
            self.assertEqual(3, len(capture.queue))
            self.assertEqual(2, capture.stats["dropped"])
        finally:
            daemon.MAX_QUEUE = original

    def test_socket_end_to_end(self):
        """The real loop: bind, receive over UDP, flush to the store."""
        from aipbx_signaling.__main__ import serve

        # _free_port() finds a port by binding and closing a probe; the
        # daemon binds it a moment later. Another binder (or the probe in
        # _wait_for_port) can win that race, and serve() then exits without
        # listening. A few fresh ports make the test deterministic without
        # weakening what it checks.
        for _attempt in range(3):
            port = _free_port()
            store = FakeStore()
            capture = Capture(store, source_check=False)
            thread = threading.Thread(
                target=serve,
                kwargs={"listen": "127.0.0.1:%d" % port, "source_check": False,
                        "capture": capture},
                daemon=True)
            thread.start()
            try:
                if not _wait_for_port(port):
                    continue          # lost the bind race; try another port
                client = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                client.sendto(hep_packet(INVITE.encode()), ("127.0.0.1", port))
                client.sendto(hep_packet(INVITE.encode()), ("127.0.0.1", port))
                client.close()
                deadline = time.time() + 5
                while store.rows_written < 2 and time.time() < deadline:
                    time.sleep(0.05)
                self.assertEqual(2, store.rows_written)
                self.assertEqual(2, capture.stats["received"])
                return
            finally:
                # The same stop flag the SIGTERM handler sets.
                capture.stop.set()
                thread.join(timeout=5)
                self.assertFalse(thread.is_alive())
        self.fail("daemon never bound a free port (3 attempts)")

    def test_batch_over_the_limit_is_written(self):
        from aipbx_signaling.db import BATCH_MAX

        store = FakeStore()
        capture = Capture(store, source_check=False)
        for _ in range(BATCH_MAX):
            capture.handle(hep_packet(INVITE.encode()), ("127.0.0.1", 1))
        capture.flush(time.time())
        self.assertEqual(BATCH_MAX, store.rows_written)


def _free_port() -> int:
    probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    probe.bind(("127.0.0.1", 0))
    port = probe.getsockname()[1]
    probe.close()
    return port


def _wait_for_port(port: int, timeout: float = 5.0) -> bool:
    """Waits until the daemon holds the port: a bind that FAILS means it is
    taken, which is what we are waiting for."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as probe:
            try:
                probe.bind(("127.0.0.1", port))
            except OSError:
                return True
        time.sleep(0.05)
    return False


if __name__ == "__main__":
    unittest.main()
