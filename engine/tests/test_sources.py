"""The readers, against fixture files: no PBX, no root, no network.

Every test here is about what the engine *refuses* to see as much as what it
sees: a row without a complete ARP entry, a zero MAC, a MAC from a vendor that
is not in the table. Those are the cases that would otherwise put a printer on
the Phones page.
"""

import os
import tempfile
import unittest
from unittest import mock

from aipbx_engine import oui, sources

LEASE_FILE = """\
1712345678 00:15:65:aa:bb:cc 192.168.10.21 yealink-t46 01:00:15:65:aa:bb:cc
1712345679 000b82112233 192.168.10.22 *
1712345680 0c:38:3e:11:22:33 192.168.10.23 fanvil-x4
1712345681 3c:52:82:99:88:77 192.168.10.30 laptop
# a lease file ends with a blank line
"""

ARP_FILE = """\
IP address       HW type     Flags       HW address            Mask     Device
192.168.10.21    0x1         0x2         00:15:65:aa:bb:cc     *        eth0
192.168.10.22    0x1         0x2         00:0b:82:11:22:33     *        eth0
192.168.10.30    0x1         0x2         3c:52:82:99:88:77     *        eth0
192.168.10.31    0x1         0x0         00:00:00:00:00:00     *        eth0
192.168.10.40    0x1         0x2         aa:bb:cc:dd:ee:ff     *        eth0
"""

JOURNAL = """\
DHCPACK(br0) 192.168.10.21 00:15:65:aa:bb:cc yealink-t46
DHCPDISCOVER(br0) 00:0b:82:11:22:33
DHCPOFFER(br0) 192.168.10.23 0c:38:3e:11:22:33 fanvil-x4
"""


def fixture(text):
    handle = tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False, encoding="utf-8")
    handle.write(text)
    handle.close()
    return handle.name


class ReadsLeases(unittest.TestCase):
    def test_reads_mac_ip_and_hostname(self):
        rows = {r["mac"]: r for r in sources.read_leases(fixture(LEASE_FILE))}
        self.assertIn("001565aabbcc", rows)
        self.assertEqual("192.168.10.21", rows["001565aabbcc"]["ip"])
        self.assertEqual("yealink-t46", rows["001565aabbcc"]["hostname"])
        self.assertEqual("lease", rows["001565aabbcc"]["source"])

    def test_a_star_hostname_is_not_a_name(self):
        rows = {r["mac"]: r for r in sources.read_leases(fixture(LEASE_FILE))}
        self.assertEqual("*", rows["000b82112233"]["hostname"])

    def test_a_missing_file_is_empty_not_an_error(self):
        self.assertEqual([], sources.read_leases("/nonexistent/dnsmasq.leases"))


class ReadsArp(unittest.TestCase):
    def test_only_complete_entries(self):
        macs = {r["mac"] for r in sources.read_arp(fixture(ARP_FILE))}
        self.assertIn("001565aabbcc", macs)
        # Flags 0x0: the entry exists but the MAC is not known yet.
        self.assertNotIn("000000000000", macs)

    def test_a_missing_file_is_empty_not_an_error(self):
        self.assertEqual([], sources.read_arp("/nonexistent/arp"))


class ReadsJournal(unittest.TestCase):
    def test_reads_dnsmasq_replies(self):
        with mock.patch("subprocess.run") as run:
            run.return_value = mock.Mock(returncode=0, stdout=JOURNAL)
            macs = {r["mac"] for r in sources.read_journal(15)}
        self.assertEqual({"001565aabbcc", "0c383e112233"}, macs)

    def test_journalctl_unavailable_is_empty_not_an_error(self):
        with mock.patch("subprocess.run", side_effect=OSError("no journalctl")):
            self.assertEqual([], sources.read_journal())


class Collects(unittest.TestCase):
    def collect(self, settings, leases=LEASE_FILE, arp=ARP_FILE, journal=""):
        with mock.patch.object(sources, "read_leases", return_value=sources.read_leases(fixture(leases))), \
             mock.patch.object(sources, "read_arp", return_value=sources.read_arp(fixture(arp))), \
             mock.patch.object(sources, "read_journal", return_value=[]):
            return sources.collect(settings)

    def test_keeps_only_known_vendors(self):
        devices = self.collect({"provision_discovery_sweep": "0"})
        macs = {d["mac"] for d in devices}
        self.assertIn("001565aabbcc", macs)          # Yealink
        self.assertIn("000b82112233", macs)          # Grandstream
        self.assertIn("0c383e112233", macs)          # Fanvil
        self.assertNotIn("3c5282998877", macs)       # a laptop (Apple)
        self.assertNotIn("aabbccddeeff", macs)       # not in any vendor list

    def test_every_device_carries_its_vendor_key(self):
        for device in self.collect({"provision_discovery_sweep": "0"}):
            self.assertIn(device["vendor"], oui.VENDORS)

    def test_no_sweep_without_networks(self):
        notes = []
        with mock.patch.object(sources, "sweep") as sweep:
            sources.collect({"provision_discovery_sweep": "1", "provision_discovery_networks": ""}, notes)
        sweep.assert_not_called()
        self.assertTrue(any("no networks" in n for n in notes))

    def test_no_sweep_when_it_is_off(self):
        notes = []
        with mock.patch.object(sources, "sweep") as sweep:
            sources.collect({"provision_discovery_sweep": "0", "provision_discovery_networks": "10.0.0.0/24"}, notes)
        sweep.assert_not_called()
        self.assertTrue(any("off" in n for n in notes))

    def test_sweep_runs_when_switched_on_and_named(self):
        notes = []
        with mock.patch.object(sources, "sweep", return_value=([], False)) as sweep:
            sources.collect({"provision_discovery_sweep": "1", "provision_discovery_networks": "10.0.0.0/24"}, notes)
        sweep.assert_called_once()
        self.assertTrue(any("complete" in n for n in notes))


class SweepBounds(unittest.TestCase):
    def test_targets_are_bounded_and_exclude_network_and_broadcast(self):
        targets = sources._sweep_targets("10.0.0.0/24")
        self.assertEqual(254, len(targets))
        self.assertNotIn("10.0.0.0", targets)
        self.assertNotIn("10.0.0.255", targets)
        self.assertEqual(len(sources._sweep_targets("10.0.0.0/8")), sources.config.MAX_HOSTS_PER_NETWORK)

    def test_ipv6_and_nonsense_are_skipped(self):
        self.assertEqual([], sources._sweep_targets("2001:db8::/120"))
        self.assertEqual([], sources._sweep_targets("not-a-network"))

    def test_a_spent_budget_stops_the_sweep_and_says_so(self):
        sent = []

        def fake_create(*_args, **_kwargs):
            sock = mock.Mock()
            sock.sendto.side_effect = lambda payload, target: sent.append(target)
            return sock

        with mock.patch("socket.socket", side_effect=fake_create), \
             mock.patch.object(sources, "read_arp", return_value=[]), \
             mock.patch("time.monotonic", side_effect=[0, 0, 100, 100]):
            devices, truncated = sources.sweep(["10.0.0.0/24"], budget=30.0, gap=0.0)
        self.assertTrue(truncated)
        self.assertEqual([], devices)
        self.assertEqual(1, len(sent))  # the second address was never tried


class NormalizesAddresses(unittest.TestCase):
    def test_mac_forms(self):
        self.assertEqual("001565aabbcc", oui.normalize_mac("00:15:65:AA:BB:CC"))
        self.assertEqual("001565aabbcc", oui.normalize_mac("001565-aabbcc"))
        self.assertEqual("", oui.normalize_mac("ffffffffffff"))
        self.assertEqual("", oui.normalize_mac(""))
        self.assertEqual("", oui.normalize_mac("00:15:65:AA:BB"))

    def test_display_form_matches_the_portal(self):
        self.assertEqual("00:15:65:AA:BB:CC", oui.format_mac("001565aabbcc"))

    def test_vendor_lookup(self):
        self.assertEqual("yealink", oui.vendor_of("001565aabbcc"))
        self.assertEqual("", oui.vendor_of("3c5282998877"))


if __name__ == "__main__":
    unittest.main()
