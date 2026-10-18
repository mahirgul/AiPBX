"""Optional forwarding of captured signaling to a monitoring endpoint.

OFF unless an administrator turns it on (PBX settings -> Signaling -> "Report
to a monitoring endpoint"). That default is deliberate and must stay: enabling
it sends call metadata OFF this server — the SIP/RTCP messages themselves,
which carry phone numbers, SIP addresses, Call-IDs and the timing of every
call. A PBX may not do that silently.

The contract is the one the Echo project's connector defines, and it is what
docs/signaling.md documents for any endpoint:

    POST <endpoint>/api/hep              {"packets": ["<base64 HEPv3>", ...]}
    POST <endpoint>/api/nodes/heartbeat  {"platform": "aipbx", "version": "..."}

The node key authenticates the sender. It is sent both as `X-Echo-Node-Key`
(required by Echo's ingest) and as a standard `Authorization: Bearer`, so an
endpoint that prefers the conventional header works unchanged.

Nothing here can slow down a call: the capture loop only appends to a bounded
queue. A thread does the sending, retries one failed batch, and drops the
oldest events when the endpoint stays unreachable.
"""

from __future__ import annotations

import base64
import json
import logging
import threading
import time
import urllib.error
import urllib.request

LOG = logging.getLogger("aipbx-signaling")

#: Heartbeat cadence. Echo shows a node as connected from this signal alone.
HEARTBEAT_SECONDS = 60

#: Longest a batch posted to the endpoint may grow.
BATCH_MAX = 200

#: How many unsent datagrams are held. Past this the OLDEST are dropped, so a
#: monitoring endpoint that is down cannot grow this process without bound.
MAX_QUEUE = 2000

DEFAULT_TIMEOUT = 10

#: Silence after the endpoint answers "too many requests". Echo rate-limits
#: per node (120 requests a minute) and retrying immediately only deepens the
#: hole — events wait in the queue instead, which is bounded anyway.
RATE_LIMIT_PAUSE = 30

#: Settings the portal writes (sys_settings). The daemon re-reads them, so
#: turning reporting off in the portal stops it within one refresh interval.
SETTING_ENABLED = "signaling_report_enabled"
SETTING_ENDPOINT = "signaling_report_endpoint"
SETTING_KEY = "signaling_report_key"
REFRESH_SECONDS = 30


class Reporter:
    """Posts captured datagrams and heartbeats to a monitoring endpoint."""

    def __init__(self, endpoint: str, node_key: str, *, platform: str = "aipbx",
                 version: str = "", timeout: int = DEFAULT_TIMEOUT):
        self.endpoint = endpoint.rstrip("/")
        self.node_key = node_key
        self.platform = platform
        self.version = version
        self.timeout = timeout
        self.queue: list = []
        self.lock = threading.Lock()
        self.stop = threading.Event()
        self.sent_total = 0
        self.dropped = 0
        self.errors = 0
        self.last_error = ""
        self.last_ok = 0.0
        self.pause_until = 0.0

    # ---------------------------------------------------------------- queue

    def enqueue(self, packet: bytes) -> None:
        """Adds one raw HEPv3 datagram. Cheap: never does network I/O."""
        with self.lock:
            self.queue.append(base64.b64encode(packet).decode("ascii"))
            if len(self.queue) > MAX_QUEUE:
                self.queue.pop(0)
                self.dropped += 1

    def take_batch(self) -> list:
        with self.lock:
            batch, self.queue = self.queue[:BATCH_MAX], self.queue[BATCH_MAX:]
        return batch

    def requeue(self, batch: list) -> None:
        """Puts a failed batch back at the FRONT (it is older than the rest)."""
        with self.lock:
            self.queue = (batch + self.queue)[:MAX_QUEUE]

    # ------------------------------------------------------------ transport

    def post(self, path: str, payload: dict) -> tuple:
        """Returns (ok, detail). Never raises."""
        url = self.endpoint + path
        body = json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(url, data=body, method="POST")
        request.add_header("Content-Type", "application/json")
        # Echo's ingest requires its own header; the conventional one is sent
        # too so a generic endpoint needs no special casing.
        request.add_header("X-Echo-Node-Key", self.node_key)
        request.add_header("Authorization", "Bearer " + self.node_key)
        request.add_header("User-Agent", "aipbx-signaling/%s" % (self.version or "dev"))
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                detail = response.read(200).decode("utf-8", errors="replace")
                return 200 <= response.status < 300, detail
        except urllib.error.HTTPError as exc:
            return False, "HTTP %s %s" % (exc.code, exc.reason)
        except Exception as exc:  # noqa: BLE001 - reported, never raised
            return False, str(exc)[:200]

    def send_batch(self, batch: list) -> bool:
        """Posts one batch; a failure puts it back for the next attempt."""
        if not batch:
            return True
        ok, detail = self.post("/api/hep", {"packets": batch})
        if ok:
            self.sent_total += len(batch)
            self.last_ok = time.time()
            return True
        self.errors += 1
        self.last_error = detail
        self.requeue(batch)
        if "429" in detail:
            self.pause_until = time.monotonic() + RATE_LIMIT_PAUSE
            LOG.warning("reporting to %s is rate limited; pausing %ds (%d held)",
                        self.endpoint, RATE_LIMIT_PAUSE, len(batch))
        else:
            LOG.warning("reporting to %s failed: %s (%d held)",
                        self.endpoint, detail, len(batch))
        return False

    def beat(self) -> bool:
        ok, detail = self.post("/api/nodes/heartbeat",
                               {"platform": self.platform, "version": self.version})
        if not ok:
            self.errors += 1
            self.last_error = detail
        return ok

    # ----------------------------------------------------------------- loop

    def run(self) -> None:
        """Sends until stopped. One thread; owns no sockets of its own."""
        next_beat = 0.0
        while not self.stop.is_set():
            if time.monotonic() >= self.pause_until:
                batch = self.take_batch()
                if batch:
                    self.send_batch(batch)
            now = time.monotonic()
            if now >= next_beat:
                self.beat()
                next_beat = now + HEARTBEAT_SECONDS
            self.stop.wait(1.0)

    def start(self) -> threading.Thread:
        thread = threading.Thread(target=self.run, name="signaling-report",
                                  daemon=True)
        thread.start()
        return thread


def settings_from(store) -> tuple:
    """(enabled, endpoint, key) from the portal's settings.

    Fails CLOSED: if the settings cannot be read, reporting stays off. The one
    thing this must never do is start sending call metadata because a lookup
    went wrong.
    """
    try:
        enabled = store.setting(SETTING_ENABLED, "0") in ("1", "yes", "on", "true")
        endpoint = store.setting(SETTING_ENDPOINT, "").strip()
        key = store.setting(SETTING_KEY, "").strip()
    except Exception as exc:  # noqa: BLE001
        LOG.debug("cannot read reporting settings (%s); staying off", exc)
        return False, "", ""
    if not enabled or not endpoint or not key:
        # Half-configured must not send anything: an endpoint without a key
        # would be rejected, and a key without an endpoint goes nowhere.
        return False, "", ""
    return True, endpoint, key


def test_send(endpoint: str, node_key: str, platform: str = "aipbx",
              version: str = "", timeout: int = DEFAULT_TIMEOUT) -> tuple:
    """One probe packet, for the portal's "send a test" button.

    Returns (ok, sentence) in plain words — the portal shows the sentence
    as-is, so it never shows a stack trace or an error code alone.
    """
    reporter = Reporter(endpoint, node_key, platform=platform, version=version,
                        timeout=timeout)
    # A minimal HEPv3 datagram: an OPTIONS that never came from a real call,
    # so an operator can confirm the path without sending call metadata.
    payload = ("OPTIONS sip:monitoring-test SIP/2.0\r\n"
               "From: <sip:test@aipbx>;tag=aipbx\r\n"
               "To: <sip:test@aipbx>\r\n"
               "Call-ID: aipbx-reporting-test\r\n"
               "CSeq: 1 OPTIONS\r\n\r\n").encode()
    packet = _probe_datagram(payload)
    ok, detail = reporter.post("/api/hep",
                               {"packets": [base64.b64encode(packet).decode("ascii")]})
    if ok:
        beat_ok, beat_detail = reporter.post(
            "/api/nodes/heartbeat",
            {"platform": platform, "version": version})
        if beat_ok:
            return True, "The endpoint accepted the test packet and the heartbeat."
        return False, "The test packet was accepted, but the heartbeat was refused: %s" % beat_detail
    return False, "The endpoint refused the test packet: %s" % detail


def _probe_datagram(payload: bytes) -> bytes:
    """A HEPv3 datagram shaped like res_hep's, for the test button only."""
    import socket
    import struct

    def tlv(tlv_type: int, value: bytes) -> bytes:
        return struct.pack(">HH", tlv_type, len(value) + 4) + value

    body = b""
    body += tlv(0x0001, bytes([1]))                                    # SIP
    body += tlv(0x0002, bytes([0]))                                    # UDP
    body += tlv(0x0003, socket.inet_aton("127.0.0.1") + b"\x00" * 4)
    body += tlv(0x0004, socket.inet_aton("127.0.0.1") + b"\x00" * 4)
    body += tlv(0x0007, struct.pack(">H", 5060) + b"\x00\x00")
    body += tlv(0x0008, struct.pack(">H", 5060) + b"\x00\x00")
    body += tlv(0x0009, struct.pack(">I", int(time.time())) + b"\x00" * 4)
    body += tlv(0x000F, payload)
    declared = len(body) + 8
    return b"HEP3" + struct.pack(">HH", declared, 0) + body
