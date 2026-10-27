"""The cycle and the two rules that keep an already provisioned phone safe.

`Store` is replaced by a fake that records what was written, so these tests run
without a database. They are here because the safety of "never provision a phone
that is already provisioned" starts at this layer: the engine must report a MAC
that is a phone as `provisioned` and it must never rewrite the portal's status
for a row the portal has already decided on.
"""

import json
import os
import tempfile
import unittest
from unittest import mock

from aipbx_engine import config, db, detect, log, sources
from aipbx_engine.db import Store


class FakeStore:
    """Stands in for Store: settings, the two MAC sets, and the upsert."""

    def __init__(self, settings=None, discovered=(), provisioned=(), fail_settings=False):
        self._settings = dict(config.SETTINGS)
        self._settings.update(settings or {})
        self._discovered = set(discovered)
        self._provisioned = set(provisioned)
        self.written = []
        self.last_error = ""
        self.fail_settings = fail_settings

    def settings(self):
        if self.fail_settings:
            raise RuntimeError("database is down")
        return self._settings

    def discovered_macs(self):
        return set(self._discovered)

    def provisioned_macs(self):
        return set(self._provisioned)

    def upsert(self, devices):
        self.written = list(devices)
        return len(self.written)


def device(mac, vendor="yealink", source="arp"):
    return {"mac": mac, "ip": "192.168.10.21", "hostname": "", "source": source, "vendor": vendor}


class Cycle(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.env = mock.patch.dict(os.environ, {"AIPBX_ENGINE_STATE": self.tmp})
        self.env.start()

    def tearDown(self):
        self.env.stop()

    def test_records_every_sighting_once(self):
        store = FakeStore()
        rows = [device("001565aabbcc"), device("000b82112233", "grandstream")]
        with mock.patch.object(sources, "collect", return_value=rows):
            result = detect.run(store)
        self.assertTrue(result.ran)
        self.assertEqual(2, result.seen)
        self.assertEqual(2, result.new)
        self.assertEqual(2, result.written)
        self.assertEqual(2, len(store.written))

    def test_a_phone_that_already_exists_is_reported_and_still_written(self):
        """It is recorded (so the page can show it is on the network) but the
        engine marks it `provisioned` — it is not a candidate for anything."""
        store = FakeStore(provisioned=["001565aabbcc"], discovered=["001565aabbcc"])
        with mock.patch.object(sources, "collect", return_value=[device("001565aabbcc")]):
            result = detect.run(store)
        self.assertEqual(1, result.provisioned)
        self.assertEqual(0, result.new)
        self.assertEqual("provisioned", store.written[0]["state"])

    def test_an_already_recorded_phone_is_not_new(self):
        store = FakeStore(discovered=["001565aabbcc"])
        with mock.patch.object(sources, "collect", return_value=[device("001565aabbcc")]):
            result = detect.run(store)
        self.assertEqual(1, result.known)
        self.assertEqual(0, result.new)

    def test_nothing_is_read_when_discovery_is_off(self):
        store = FakeStore(settings={"provision_discovery": "0"})
        with mock.patch.object(sources, "collect") as collect:
            result = detect.run(store)
        collect.assert_not_called()
        self.assertFalse(result.ran)
        self.assertIn("off", result.reason)

    def test_a_broken_database_does_not_raise(self):
        result = detect.run(FakeStore(fail_settings=True))
        self.assertFalse(result.ran)
        self.assertTrue(result.error.startswith("settings:"))

    def test_the_run_is_recorded_in_the_log_and_the_state_file(self):
        store = FakeStore()
        with mock.patch.object(sources, "collect", return_value=[device("001565aabbcc")]):
            detect.run(store)
        with open(config.state_file(), encoding="utf-8") as handle:
            state = json.load(handle)
        self.assertEqual(1, state["seen"])
        self.assertEqual("001565aabbcc", state["devices"][0]["mac"])
        self.assertTrue(any("detect seen=1" in line for line in log.tail(5)))


class UpsertSql(unittest.TestCase):
    """The statement itself: what it may and may not overwrite."""

    def test_the_upsert_never_touches_the_portal_columns(self):
        self.assertIn("ON DUPLICATE KEY UPDATE", db.INSERT_SQL)
        # A sighting refreshes where the phone is, never what the portal decided.
        for column in ("status = ", "phone_id = ", "approved_at = ", "approved_by = ", "first_seen_at = "):
            self.assertNotIn(column, db.INSERT_SQL)
        # ... and it does refresh the facts that are the engine's own.
        self.assertIn("last_seen_at = NOW()", db.INSERT_SQL)
        self.assertIn("seen_count = seen_count + 1", db.INSERT_SQL)

    def test_a_row_without_a_mac_is_never_written(self):
        store = Store.__new__(Store)          # no connection: upsert returns early
        store.rows_written = 0
        store.last_error = ""
        self.assertEqual(0, store.upsert([{"mac": ""}, {"ip": "1.2.3.4"}]))
        self.assertEqual(0, store.upsert([]))

    def test_an_unknown_vendor_is_still_filtered_before_this_layer(self):
        # Belongs to sources.collect, asserted here too so a future refactor of
        # either file cannot move the filter away without failing something.
        with mock.patch.object(sources, "read_leases", return_value=[{"mac": "3c5282998877", "ip": "1.2.3.4", "hostname": "", "source": "lease"}]), \
             mock.patch.object(sources, "read_arp", return_value=[]), \
             mock.patch.object(sources, "read_journal", return_value=[]):
            self.assertEqual([], sources.collect({"provision_discovery_sweep": "0"}))


if __name__ == "__main__":
    unittest.main()
