"""Entry point: python -m aipbx_signaling (aipbx-signaling.service).

Receives the HEPv3 datagrams Asterisk's res_hep* send (PBX settings ->
Signaling -> "capture address", default 127.0.0.1:9061), parses each one and
writes batched rows to `pbx_signaling_events`.

Environment:
    AIPBX_SIGNALING_LISTEN   address to listen on (default 127.0.0.1:9061)
    AIPBX_SIGNALING_SOURCE   set to '0' to accept datagrams from any address;
                             anything else (the default) accepts only the
                             configured capture address's host, so a port
                             opened by mistake cannot be fed from outside
    AIPBX_SIGNALING_ENV      environment file (default /etc/ai-pbx.env)

Asterisk never waits for this service: res_hep sends over UDP, and a capture
server that is slow or down only loses captures. Nothing here can affect a
call.
"""

from __future__ import annotations

import logging
import os
import signal
import socket
import sys
import threading
import time

from . import hep
from .db import BATCH_MAX, Store

LOG = logging.getLogger("aipbx-signaling")

#: Datagrams are written at most this often, so a quiet PBX still shows up.
FLUSH_SECONDS = 1.0

#: A single datagram larger than this is refused (HEP packets are small; a
#: huge one is a sign of something else talking to the port).
MAX_DATAGRAM = 65535

#: Retention runs this often. Daily would be enough; the cost of running it
#: more often is one DELETE that normally matches nothing.
PRUNE_SECONDS = 6 * 3600

#: Longest a parsed batch may grow before it is written regardless.
MAX_QUEUE = BATCH_MAX * 4


def parse_listen(value: str) -> tuple:
    """"host:port" -> (host, port). A bare port means the loopback address."""
    if ":" not in value:
        if value.isdigit():
            return "127.0.0.1", int(value)
        return value, 9061
    host, _, port = value.rpartition(":")
    return (host or "127.0.0.1"), int(port)


class Capture:
    def __init__(self, store: Store, source_check: bool = True):
        self.store = store
        self.source_check = source_check
        self.queue: list = []
        self.lock = threading.Lock()
        self.stop = threading.Event()
        self.stats = {"received": 0, "parsed": 0, "dropped": 0, "wrong_source": 0}
        self.allowed_source = "127.0.0.1"

    def handle(self, data: bytes, addr) -> None:
        self.stats["received"] += 1
        if self.source_check and addr[0] != self.allowed_source:
            # Second fence: the firewall (and, by default, the loopback bind)
            # is the first. A datagram from anywhere else is counted, not read.
            self.stats["wrong_source"] += 1
            return
        event = hep.parse_event(data)
        if event is None:
            self.stats["dropped"] += 1
            return
        self.stats["parsed"] += 1
        with self.lock:
            self.queue.append(event)
            if len(self.queue) > MAX_QUEUE:
                # The database is behind: drop the oldest rather than grow
                # without bound. Reported in the periodic stats line.
                self.queue.pop(0)
                self.stats["dropped"] += 1

    def flush(self, now: float) -> None:
        with self.lock:
            if not self.queue:
                return
            batch, self.queue = self.queue, []
        written = self.store.write(batch, int(now))
        if written:
            LOG.debug("wrote %d events", written)
        else:
            # The rows are gone: saying so is the only option (fail-open).
            LOG.warning("could not write %d events: %s", len(batch),
                        self.store.last_error or "unknown error")

    def report(self) -> None:
        line = ("received=%d parsed=%d dropped=%d wrong_source=%d written=%d errors=%d"
                % (self.stats["received"], self.stats["parsed"],
                   self.stats["dropped"], self.stats["wrong_source"],
                   self.store.rows_written, self.store.write_errors))
        LOG.info("%s", line)


def serve(listen: str = "", source_check: bool = True, store: Store = None,
          capture: Capture = None) -> int:
    """The capture loop. `capture` is injectable so a test can drive the real
    socket path and stop it, instead of signalling the whole process."""
    listen = listen or os.environ.get("AIPBX_SIGNALING_LISTEN",
                                      "127.0.0.1:9061")
    host, port = parse_listen(listen)
    capture = capture or Capture(store or Store(), source_check=source_check)
    if source_check:
        capture.allowed_source = host

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        sock.bind((host, port))
    except OSError as exc:
        LOG.error("cannot listen on %s:%d: %s", host, port, exc)
        return 1
    sock.settimeout(0.5)

    def stop(signum, _frame):
        LOG.info("signal %d: stopping", signum)
        capture.stop.set()

    # Only the main thread may install signal handlers; when serve() is
    # embedded (a test, or another service) the caller owns stopping it.
    if threading.current_thread() is threading.main_thread():
        signal.signal(signal.SIGTERM, stop)
        signal.signal(signal.SIGINT, stop)

    LOG.info("listening for HEPv3 on %s:%d (source check: %s)",
             host, port, "on" if source_check else "off")

    last_flush = time.monotonic()
    last_prune = time.monotonic()
    last_report = time.monotonic()
    while not capture.stop.is_set():
        try:
            data, addr = sock.recvfrom(MAX_DATAGRAM)
        except socket.timeout:
            data = None
        except OSError as exc:
            LOG.error("receive failed: %s", exc)
            break
        if data:
            capture.handle(data, addr)

        now = time.monotonic()
        if capture.queue and now - last_flush >= FLUSH_SECONDS:
            capture.flush(time.time())
            last_flush = now
        elif len(capture.queue) >= BATCH_MAX:
            capture.flush(time.time())
            last_flush = now

        if now - last_prune >= PRUNE_SECONDS:
            deleted = capture.store.prune()
            if deleted:
                LOG.info("retention: removed %d old events", deleted)
            last_prune = now

        if now - last_report >= 300:
            capture.report()
            last_report = now

    capture.flush(time.time())
    sock.close()
    capture.report()
    return 0


def main() -> int:
    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="%(levelname)s %(name)s: %(message)s")
    check = os.environ.get("AIPBX_SIGNALING_SOURCE", "1") != "0"
    return serve(source_check=check)


if __name__ == "__main__":
    sys.exit(main())
