"""The engine's own log file and state file.

Both live in ``/var/lib/aipbx/engine`` (``0600``, owned by the user the timer
runs as) and both are optional: if the directory cannot be created — a test run,
a read-only box — the engine still works, it just has nowhere to write notes.
The log is truncated to ``config.LOG_MAX_BYTES`` when it grows past it, keeping
the newer half, so a long-running installation needs no logrotate rule of its
own. The portal never writes here; ``web/bin/aipbx_phone_discovery.php``
(``status``) reads the tail to show the last run on the Phones page.
"""

from __future__ import annotations

import json
import os
import time

from . import config


def _ensure_dir() -> bool:
    directory = config.state_dir()
    try:
        os.makedirs(directory, mode=0o750, exist_ok=True)
        return True
    except OSError:
        return False


def _read(path: str) -> str:
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            return handle.read()
    except OSError:
        return ""


def _write(path: str, text: str) -> bool:
    try:
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text)
        os.chmod(path, 0o600)
        return True
    except OSError:
        return False


def append(line: str, when: float | None = None) -> str:
    """Adds one timestamped line and returns it (with the timestamp)."""
    stamp = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(when if when is not None else time.time()))
    entry = f"{stamp} {line}\n"
    if not _ensure_dir():
        return entry.rstrip("\n")
    path = config.log_file()
    try:
        oversized = os.path.getsize(path) > config.LOG_MAX_BYTES
    except OSError:
        oversized = False
    if oversized:
        previous = _read(path)
        _write(path, previous[len(previous) // 2:])
    try:
        with open(path, "a", encoding="utf-8") as handle:
            handle.write(entry)
        os.chmod(path, 0o600)
    except OSError:
        pass
    return entry.rstrip("\n")


def tail(lines: int = 20) -> list[str]:
    """The last lines of the log, newest last (what the Phones page shows)."""
    if not _ensure_dir():
        return []
    text = _read(config.log_file())
    return [line for line in text.splitlines() if line.strip()][-max(1, lines):]


def state() -> dict:
    """The last run's counters, or {} when the engine has not run yet."""
    if not _ensure_dir():
        return {}
    try:
        with open(config.state_file(), encoding="utf-8") as handle:
            data = json.load(handle)
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def save_state(data: dict) -> None:
    if not _ensure_dir():
        return
    _write(config.state_file(), json.dumps(data, indent=2, sort_keys=True))
