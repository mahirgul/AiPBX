"""The SIP traffic files: a pcap Wireshark reads, cut at 10 MB, kept for N days."""

from __future__ import annotations

import os
import struct
import sys
import tempfile
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from aipbx_signaling import hep, pcap  # noqa: E402

from test_hep import INVITE, hep_packet  # noqa: E402


def read_pcap(path):
    with open(path, "rb") as f:
        data = f.read()
    magic, _, _, _, _, snap, link = struct.unpack("<IHHiIII", data[:24])
    packets, pos = [], 24
    while pos < len(data):
        sec, usec, incl, _orig = struct.unpack("<IIII", data[pos:pos + 16])
        packets.append((sec, usec, data[pos + 16:pos + 16 + incl]))
        pos += 16 + incl
    return magic, link, packets


def event(ts=1794700000, payload=b"OPTIONS sip:pbx SIP/2.0\r\n\r\n", src=("10.0.0.5", 5060), dst=("10.0.0.1", 5060)):
    return {"ts": ts, "_ts_us": 250000, "_payload": payload, "_src": src, "_dst": dst}


class PcapTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.w = pcap.PcapWriter(self.tmp.name)
        self.w.configure(True, 10, 2048)

    def tearDown(self):
        self.tmp.cleanup()

    def files(self):
        return sorted(os.listdir(self.tmp.name))

    def test_a_hep_sip_message_becomes_a_udp_packet_with_its_addresses(self):
        raw = INVITE.encode() if isinstance(INVITE, str) else INVITE
        ev = hep.parse_event(hep_packet(raw))
        self.assertIn("_payload", ev)
        self.w.write(ev)
        self.w.close()
        [name] = self.files()
        self.assertRegex(name, r"^sip-\d{8}-\d{6}_\d{8}-\d{6}\.pcap$")
        magic, link, packets = read_pcap(os.path.join(self.tmp.name, name))
        self.assertEqual((magic, link), (0xA1B2C3D4, 101))
        [(_, _, pkt)] = packets
        self.assertEqual(pkt[0] >> 4, 4)
        self.assertEqual(pkt[9], 17)
        sport, dport = struct.unpack("!HH", pkt[20:24])
        self.assertEqual((sport, dport), ev["_src"][1:] + ev["_dst"][1:])
        self.assertEqual(pkt[28:], raw)

    def test_microseconds_and_ipv6(self):
        self.w.write(event(src=("2001:db8::5", 5061), dst=("10.0.0.1", 5061)))
        self.w.close()
        _, _, [(sec, usec, pkt)] = read_pcap(os.path.join(self.tmp.name, self.files()[0]))
        self.assertEqual((sec, usec), (1794700000, 250000))
        self.assertEqual(pkt[0] >> 4, 6)
        self.assertEqual(pkt[6], 17)

    def test_a_new_file_after_the_size_limit(self):
        self.w.max_bytes = 2000
        for i in range(20):
            self.w.write(event(ts=1794700000 + i, payload=b"X" * 300))
        self.w.close()
        self.assertGreater(len(self.files()), 2)
        total = sum(len(read_pcap(os.path.join(self.tmp.name, f))[2]) for f in self.files())
        self.assertEqual(total, 20)

    def test_nothing_is_written_when_off_and_non_sip_is_skipped(self):
        self.w.configure(False, 10, 2048)
        self.w.write(event())
        self.w.configure(True, 10, 2048)
        self.w.write({"ts": 1794700000})
        self.assertEqual(self.files(), [])

    def test_old_files_and_files_beyond_the_disk_limit_go(self):
        old = os.path.join(self.tmp.name, "sip-20200101-000000_20200101-010000.pcap")
        with open(old, "wb") as f:
            f.write(pcap.GLOBAL_HEADER)
        os.utime(old, (time.time() - 11 * 86400, time.time() - 11 * 86400))
        keep = []
        for i in range(3):
            path = os.path.join(self.tmp.name, "sip-2026111%d-000000_2026111%d-010000.pcap" % (i, i))
            with open(path, "wb") as f:
                f.write(b"\0" * (60 * 1024 * 1024))
            keep.append(os.path.basename(path))
        self.w.configure(True, 10, 100)
        removed = self.w.prune()
        self.assertEqual(removed, 3)
        self.assertEqual(self.files(), [keep[2]])


if __name__ == "__main__":
    unittest.main()
