"""SIP traffic files: every SIP message Asterisk reports over HEP, written as
pcap (Wireshark reads it) into DIR, one file per at most MAX_BYTES.

HEP gives the message as Asterisk sent or received it, so SIP over TLS and the
WebSocket of browsers and apps is here in clear text, which a capture of the
network cannot show.

A packet is the SIP message in an IPv4/IPv6 + UDP header carrying the real
addresses and ports (LINKTYPE_RAW); the transport it really used (UDP, TCP,
TLS, WS) is not kept in the file, Wireshark shows every message as UDP.

Files: sip-YYYYMMDD-HHMMSS.pcap while being written, renamed to
sip-YYYYMMDD-HHMMSS_YYYYMMDD-HHMMSS.pcap (first and last message) when full,
at midnight, or when the service stops. Older than `days` days, or beyond
`max_total` bytes in all, the oldest go first.
"""

from __future__ import annotations

import glob
import ipaddress
import logging
import os
import struct
import time
from typing import Optional

LOG = logging.getLogger("aipbx-signaling")

DIR = "/var/lib/aipbx/sip-capture"
MAX_BYTES = 10 * 1024 * 1024
LINKTYPE_RAW = 101
GLOBAL_HEADER = struct.pack("<IHHiIII", 0xA1B2C3D4, 2, 4, 0, 0, 65535, LINKTYPE_RAW)


def _stamp(ts: float) -> str:
    return time.strftime("%Y%m%d-%H%M%S", time.localtime(ts))


def ip_udp_packet(src: tuple, dst: tuple, payload: bytes) -> bytes:
    """An IPv4 or IPv6 + UDP packet around `payload` (checksums left 0, as Wireshark allows)."""
    payload = payload[:65000]
    udp = struct.pack("!HHHH", int(src[1]) & 0xFFFF, int(dst[1]) & 0xFFFF, 8 + len(payload), 0) + payload
    try:
        s_ip, d_ip = ipaddress.ip_address(src[0]), ipaddress.ip_address(dst[0])
    except ValueError:
        s_ip, d_ip = ipaddress.ip_address("0.0.0.0"), ipaddress.ip_address("0.0.0.0")
    if s_ip.version == 6 or d_ip.version == 6:
        s6 = s_ip if s_ip.version == 6 else ipaddress.IPv6Address("::ffff:" + str(s_ip))
        d6 = d_ip if d_ip.version == 6 else ipaddress.IPv6Address("::ffff:" + str(d_ip))
        return struct.pack("!IHBB", 6 << 28, len(udp), 17, 64) + s6.packed + d6.packed + udp
    header = struct.pack("!BBHHHBBH4s4s", 0x45, 0, 20 + len(udp), 0, 0, 64, 17, 0, s_ip.packed, d_ip.packed)
    return header + udp


class PcapWriter:
    def __init__(self, directory: str = DIR, max_bytes: int = MAX_BYTES):
        self.dir = directory
        self.max_bytes = max_bytes
        self.enabled = False
        self.days = 10
        self.max_total = 2048 * 1024 * 1024
        self.fh = None
        self.path = ""
        self.first_ts = 0.0
        self.last_ts = 0.0
        self.size = 0

    def configure(self, enabled: bool, days: int, max_total_mb: int) -> None:
        self.days = max(1, min(10, int(days)))
        self.max_total = max(100, int(max_total_mb)) * 1024 * 1024
        if self.enabled and not enabled:
            self.close()
        self.enabled = enabled

    def write(self, event: dict) -> None:
        payload = event.get("_payload")
        if not self.enabled or not payload:
            return
        ts = float(event.get("ts") or 0) or time.time()
        usec = int(event.get("_ts_us") or 0) % 1_000_000
        if self.fh is not None and (self.size >= self.max_bytes or _stamp(ts)[:8] != _stamp(self.first_ts)[:8]):
            self.close()
        if self.fh is None and not self._open(ts):
            return
        packet = ip_udp_packet(event.get("_src", ("0.0.0.0", 0)), event.get("_dst", ("0.0.0.0", 0)), payload)
        try:
            self.fh.write(struct.pack("<IIII", int(ts), usec, len(packet), len(packet)) + packet)
            self.fh.flush()
        except OSError as exc:
            LOG.warning("sip capture file %s: %s", self.path, exc)
            self.close()
            return
        self.size += 16 + len(packet)
        self.last_ts = ts

    def _open(self, ts: float) -> bool:
        try:
            os.makedirs(self.dir, mode=0o750, exist_ok=True)
            self.path = os.path.join(self.dir, "sip-%s.pcap" % _stamp(ts))
            old_umask = os.umask(0o027)
            try:
                self.fh = open(self.path, "ab")
            finally:
                os.umask(old_umask)
            if self.fh.tell() == 0:
                self.fh.write(GLOBAL_HEADER)
            self.size = self.fh.tell()
            self.first_ts = self.last_ts = ts
            return True
        except OSError as exc:
            LOG.warning("cannot open a sip capture file in %s: %s", self.dir, exc)
            self.fh = None
            return False

    def close(self) -> None:
        if self.fh is None:
            return
        try:
            self.fh.close()
            final = os.path.join(self.dir, "sip-%s_%s.pcap" % (_stamp(self.first_ts), _stamp(self.last_ts)))
            os.replace(self.path, final)
        except OSError as exc:
            LOG.warning("closing %s: %s", self.path, exc)
        self.fh = None
        self.prune()

    def prune(self, now: Optional[float] = None) -> int:
        """Removes files older than `days` and, oldest first, beyond `max_total`."""
        now = now or time.time()
        files = sorted(glob.glob(os.path.join(self.dir, "sip-*.pcap")))
        removed = 0
        keep = []
        for f in files:
            if f == self.path and self.fh is not None:
                continue
            try:
                if os.path.getmtime(f) < now - self.days * 86400:
                    os.unlink(f)
                    removed += 1
                else:
                    keep.append(f)
            except OSError:
                pass
        total = sum(os.path.getsize(f) for f in keep if os.path.exists(f)) + (self.size if self.fh else 0)
        for f in keep:
            if total <= self.max_total:
                break
            try:
                total -= os.path.getsize(f)
                os.unlink(f)
                removed += 1
            except OSError:
                pass
        return removed
