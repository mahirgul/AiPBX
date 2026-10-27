"""One detection cycle: look, record, report.

The order matters and is the whole safety argument:

1. read the settings the administrator edits on the Phones page;
2. if discovery is off, stop before reading anything (one line in the log);
3. collect what is on the network (``sources.collect``);
4. split it into *new* MACs, MACs already recorded, and MACs that are **already a
   phone** — the last group is listed and never written to ``pbx_phones``;
5. record every sighting in ``pbx_phone_discoveries`` and write the summary.

Provisioning is not in this module and not in this process. ``PhoneDiscoveryService``
(in the portal) is the only code that creates a phone, it only ever creates one
for a MAC an administrator approved or one that falls inside the extension pool,
and the systemd unit runs it as a separate step after this one. That is what makes
"an already provisioned phone is never provisioned again" a property of the
design rather than of the order two scripts happen to run in.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field

from . import config, log, oui, sources
from .db import Store


@dataclass
class Result:
    """What one cycle did, for the log line and for ``--json``."""

    ran: bool = False
    reason: str = ""
    seen: int = 0
    new: int = 0
    known: int = 0
    provisioned: int = 0
    written: int = 0
    notes: list = field(default_factory=list)
    error: str = ""

    @property
    def line(self) -> str:
        if not self.ran:
            return f"skipped: {self.reason}"
        parts = [
            f"seen={self.seen}",
            f"new={self.new}",
            f"known={self.known}",
            f"already provisioned={self.provisioned}",
            f"recorded={self.written}",
        ]
        text = " ".join(parts)
        if self.notes:
            text += " | " + "; ".join(self.notes)
        if self.error:
            text += f" | error: {self.error}"
        return text

    def as_dict(self) -> dict:
        return {
            "ran": self.ran,
            "reason": self.reason,
            "seen": self.seen,
            "new": self.new,
            "known": self.known,
            "provisioned": self.provisioned,
            "written": self.written,
            "notes": list(self.notes),
            "error": self.error,
        }


def enabled(store: Store, settings: dict) -> bool:
    """Is discovery on at all? An unreadable settings row keeps the last answer
    (the default is on); an explicit 0 turns the engine off here rather than in
    three places inside it."""
    return Store.is_on(settings.get("provision_discovery", config.SETTINGS["provision_discovery"]))


def run(store: Store | None = None) -> Result:
    """Runs one cycle. Never raises: a failure is the result's ``error``."""
    store = store if store is not None else Store()
    result = Result()

    try:
        settings = store.settings()
    except Exception as exc:  # noqa: BLE001 - a bad run beats no engine
        result.error = f"settings: {exc}"[:200]
        return result

    if not enabled(store, settings):
        result.reason = "provision_discovery is off"
        return result

    try:
        devices = sources.collect(settings, result.notes)
    except Exception as exc:  # noqa: BLE001 - a bad run beats no engine
        result.error = f"collect: {exc}"[:200]
        return result

    known_macs = store.discovered_macs()
    phone_macs = store.provisioned_macs()
    result.error = store.last_error

    for device in devices:
        if device["mac"] in phone_macs:
            device["state"] = "provisioned"
            result.provisioned += 1
        elif device["mac"] in known_macs:
            device["state"] = "known"
            result.known += 1
        else:
            device["state"] = "new"
            result.new += 1

    result.seen = len(devices)
    result.written = store.upsert(devices)
    if store.last_error:
        result.error = store.last_error
    result.ran = True

    log.append(f"detect {result.line} vendor-table={len(oui.VENDORS)}")
    log.save_state({
        "at": time.time(),
        "devices": [{"mac": d["mac"], "ip": d["ip"], "vendor": d["vendor"],
                     "hostname": d.get("hostname", ""), "source": d["source"],
                     "state": d["state"]} for d in devices],
        **result.as_dict(),
    })
    return result
