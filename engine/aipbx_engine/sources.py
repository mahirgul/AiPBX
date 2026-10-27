"""Where the engine looks for phones.

Four sources, three of which only read a file:

===========================  ==========  ==========================================
source                       packets?    what it sees
===========================  ==========  ==========================================
``lease``  (dnsmasq leases)  no          phones that took an address from AiPBX's DHCP
``arp``    (/proc/net/arp)   no          anything on the local segment that talked
``journal``(dnsmasq log)     no          phones that asked for DHCP, even after the
                                         lease file was rotated or dnsmasq restarted
``sweep``  (ARP scan)        **yes**     the same as arp, but for every address, so it
                                         also finds a phone that has been idle
===========================  ==========  ==========================================

The first three are free and always on (when discovery is on); the sweep is off
until an administrator switches it on and names the networks, because it is the
only part that puts traffic on the wire.

Nothing here decides anything. Each reader returns the same shape — a list of
``{'mac', 'ip', 'hostname', 'source'}`` — and the sweep's result is filtered by
the vendor table before it leaves, so an idle printer or a laptop never becomes a
"phone" just because it answered. That filter is not applied to the lease and
journal readers: a lease file only ever contains machines that asked our DHCP,
and `NetworkServicesService::syncLeasesToWaiting()` already treats a known vendor
prefix as the requirement there too.
"""

from __future__ import annotations

import ipaddress
import itertools
import os
import re
import socket
import subprocess
import time

from . import config, oui

#: dnsmasq lease file: ``<expiry> <mac> <ip> <hostname> <client-id>``.
_LEASE_RE = re.compile(
    r"^\s*\d+\s+(?P<mac>[0-9a-fA-F:.-]{11,17})\s+(?P<ip>[0-9a-fA-F:.]+)(?:\s+(?P<name>\S+))?"
)
#: /proc/net/arp: ``IP address  HW type  Flags  HW address  Mask  Device``.
_ARP_RE = re.compile(
    r"^\s*(?P<ip>\d+\.\d+\.\d+\.\d+)\s+0x[0-9a-f]+\s+(?P<flags>0x[0-9a-f]+)\s+"
    r"(?P<mac>[0-9a-fA-F:]{17})\s+(?P<mask>\S+)\s+(?P<dev>\S+)"
)
#: dnsmasq's own log: ``DHCPACK(br0) 192.168.1.7 00:15:65:aa:bb:cc hostname``.
_JOURNAL_RE = re.compile(
    r"DHCP(?:ACK|OFFER)\((?P<iface>[^)]+)\)\s+(?P<ip>\d+\.\d+\.\d+\.\d+)\s+"
    r"(?P<mac>[0-9a-fA-F:]{17})(?:\s+(?P<name>\S+))?"
)

#: The sweep's probe target: port 9 (discard) on each address. Nothing listens;
#: the reply we want is the ARP exchange the kernel performs before it can even
#: send the datagram, which is what tells us the machine is there.
_PROBE_PORT = 9


def _device(mac: str, ip: str = "", hostname: str = "", source: str = "") -> dict | None:
    mac = oui.normalize_mac(mac)
    if mac == "":
        return None
    return {"mac": mac, "ip": (ip or "").strip(), "hostname": (hostname or "").strip(),
            "source": source}


def read_leases(path: str | None = None) -> list[dict]:
    """Every address AiPBX's own DHCP server handed out (dnsmasq.leases)."""
    path = path or config.lease_file()
    out: list[dict] = []
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            for line in handle:
                m = _LEASE_RE.match(line)
                if m:
                    dev = _device(m.group("mac"), m.group("ip"), m.group("name") or "", "lease")
                    if dev:
                        out.append(dev)
    except OSError:
        return []
    return out


def read_arp(path: str | None = None) -> list[dict]:
    """The kernel's neighbour table: MACs it has talked to recently.

    Rows in an incomplete or failed state have no MAC and are skipped, which is
    what keeps a router or an unreachable address out of the list.
    """
    path = path or config.arp_file()
    out: list[dict] = []
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            for line in handle:
                m = _ARP_RE.match(line)
                if m and int(m.group("flags"), 16) & 0x2:  # ATF_COM: the entry is complete
                    dev = _device(m.group("mac"), m.group("ip"), "", "arp")
                    if dev:
                        out.append(dev)
    except OSError:
        return []
    return out


def read_journal(minutes: int | None = None) -> list[dict]:
    """dnsmasq's DHCP replies from the system journal.

    The lease file survives a dnsmasq restart, so this is not a fallback for a
    missing file: it is the source that catches a phone whose lease expired
    (a short lease, a phone that was off all night) while the machine still
    reported a handheld to the network this morning.
    """
    minutes = config.JOURNAL_MINUTES if minutes is None else minutes
    try:
        result = subprocess.run(
            ["journalctl", "--since", f"{minutes} min ago", "--no-pager", "-u", "aipbx-dnsmasq", "-o", "cat"],
            capture_output=True, text=True, timeout=15,
        )
    except (OSError, subprocess.SubprocessError):
        return []
    if result.returncode != 0:
        return []
    out: list[dict] = []
    for line in result.stdout.splitlines():
        m = _JOURNAL_RE.search(line)
        if m:
            dev = _device(m.group("mac"), m.group("ip"), m.group("name") or "", "journal")
            if dev:
                out.append(dev)
    return out


def local_networks() -> list[str]:
    """CIDRs of this machine's own IPv4 addresses, as a sweep suggestion.

    Reported by ``python3 -m aipbx_engine status`` so an administrator filling in
    ``provision_discovery_networks`` can see what the PBX itself is on. The sweep
    still runs on nothing until that setting is filled in: the engine does not
    decide which networks are a phone network.
    """
    try:
        result = subprocess.run(["ip", "-4", "-o", "addr", "show"],
                                capture_output=True, text=True, timeout=10)
    except (OSError, subprocess.SubprocessError):
        return []
    if result.returncode != 0:
        return []
    out: list[str] = []
    for line in result.stdout.splitlines():
        parts = line.split()
        if len(parts) >= 4 and parts[2] == "inet":  # "2: eth0 inet 192.168.1.5/24 ..."
            try:
                out.append(str(ipaddress.ip_interface(parts[3]).network))
            except ValueError:
                continue
    return out


def _sweep_targets(network: str) -> list[str]:
    """The addresses of one CIDR, bounded, minus the network and broadcast ones."""
    try:
        net = ipaddress.ip_network(network, strict=False)
    except ValueError:
        return []
    if net.version != 4:
        return []
    # islice, not a slice of the whole list: a /8 has 16 million addresses and
    # building them all to keep the first 1024 would cost a minute of memory.
    return [str(ip) for ip in itertools.islice(net.hosts(), config.MAX_HOSTS_PER_NETWORK)]


def sweep(networks: list[str], budget: float | None = None, gap: float | None = None) -> tuple[list[dict], bool]:
    """ARP-scans the named networks and reads the neighbour table afterwards.

    Returns the devices and whether the budget ran out (so the log line can say
    the rest of the network is left for the next run instead of pretending the
    scan was complete).

    A UDP datagram to a closed port is all it takes: the kernel must resolve the
    address to a MAC before it can send anything, and that ARP exchange is what
    puts the machine in ``/proc/net/arp``. No raw socket, no root, no ICMP.
    """
    budget = config.SWEEP_BUDGET_SECONDS if budget is None else budget
    gap = config.SWEEP_GAP_SECONDS if gap is None else gap
    started = time.monotonic()
    truncated = False

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.settimeout(0.2)
        for network in networks[: config.MAX_NETWORKS_PER_RUN]:
            for address in _sweep_targets(network):
                if time.monotonic() - started > budget:
                    truncated = True
                    break
                try:
                    sock.sendto(b"\x00", (address, _PROBE_PORT))
                except OSError:
                    pass
                time.sleep(gap)
            if truncated:
                break
    finally:
        sock.close()

    return [dict(d, source="sweep") for d in read_arp()], truncated


def collect(settings: dict, notes: list[str] | None = None) -> list[dict]:
    """Everything seen this run, one entry per MAC, first source wins.

    ``settings`` is what the portal stores (``Store.settings()``); the sweep runs
    only when it is switched on *and* at least one network is named.
    """
    notes = notes if notes is not None else []

    found: dict[str, dict] = {}

    def add(devices: list[dict]) -> None:
        for dev in devices:
            found.setdefault(dev["mac"], dev)

    add(read_leases())
    add(read_arp())
    add(read_journal())

    networks = [n.strip() for n in str(settings.get("provision_discovery_networks", "")).replace("\n", ",").split(",") if n.strip()]
    if not networks:
        notes.append("sweep: no networks configured")
    elif str(settings.get("provision_discovery_sweep", "0")).strip() not in ("1", "yes", "true", "on"):
        notes.append("sweep: off")
    else:
        swept, truncated = sweep(networks)
        add(swept)
        notes.append(
            f"sweep: {len(networks)} network(s), "
            + ("budget reached, the rest is left for the next run" if truncated else "complete")
        )

    # Only a known vendor prefix is a phone: the ARP sources see everything that
    # talks on the segment, and this is the line that keeps laptops out.
    out = []
    for dev in found.values():
        vendor = oui.vendor_of(dev["mac"])
        if vendor == "":
            continue
        out.append(dict(dev, vendor=vendor))
    out.sort(key=lambda d: d["mac"])
    return out
