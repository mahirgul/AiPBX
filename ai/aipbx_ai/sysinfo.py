"""Host and process figures for /v1/health, read from /proc (Linux)."""
import os
import platform
import threading
import time
from importlib import metadata


def _read(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as f:
            return f.read()
    except OSError:
        return ""


def cpu_model():
    for line in _read("/proc/cpuinfo").splitlines():
        key, _, value = line.partition(":")
        if key.strip() == "model name":
            return value.strip()
    return platform.processor() or platform.machine()


def ram_mb():
    """(total, available) in MiB."""
    values = {}
    for line in _read("/proc/meminfo").splitlines():
        key, _, rest = line.partition(":")
        parts = rest.split()
        if parts and parts[0].isdigit():
            values[key] = int(parts[0])  # kB
    return values.get("MemTotal", 0) // 1024, values.get("MemAvailable", 0) // 1024


def rss_mb():
    for line in _read("/proc/self/status").splitlines():
        if line.startswith("VmRSS:"):
            parts = line.split()
            if len(parts) > 1 and parts[1].isdigit():
                return int(parts[1]) // 1024
    return 0


def runtime_version():
    """The installed ONNX Runtime version without importing it."""
    try:
        return metadata.version("onnxruntime")
    except metadata.PackageNotFoundError:
        return None


class CpuMeter:
    """This process's CPU use since the previous call (first call: since start).

    100.0 is one fully used core, as in top; a busy multi-core synthesis can
    exceed it.
    """

    def __init__(self):
        self._lock = threading.Lock()
        self._wall = time.monotonic()
        self._cpu = self._cpu_seconds()

    @staticmethod
    def _cpu_seconds():
        t = os.times()
        return t.user + t.system

    def percent(self):
        with self._lock:
            wall, cpu = time.monotonic(), self._cpu_seconds()
            span = wall - self._wall
            used = cpu - self._cpu
            self._wall, self._cpu = wall, cpu
        if span <= 0:
            return 0.0
        return round(100.0 * used / span, 1)
