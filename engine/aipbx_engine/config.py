"""Where the engine reads its settings and its inputs from.

There is no engine configuration file. Everything comes from the two places the
rest of AiPBX uses:

* ``/etc/ai-pbx.env`` — the same file the portal and the signaling daemon read
  (`chown root:asterisk`, ``chmod 640``), for the database credentials, and
* the portal's ``sys_settings`` table, for the settings an administrator edits
  on PBX -> Phones.

So there is nothing to keep in step: turning discovery off in the portal turns
this engine off on its next run.

The paths carry ``AIPBX_*`` overrides because the tests (and an operator
debugging on a live box) point them at a copy instead of the real file. The
override is read on every call, not at import, so setting it works either way.
"""

from __future__ import annotations

import os

#: The portal's environment file (database credentials).
ENV_FILE = "/etc/ai-pbx.env"

#: Settings keys and their defaults. These are the defaults of
#: ``PhoneProvisionService::SETTINGS`` in the portal;
#: ``engine/tests/test_parity.py`` reads that file and fails when the two
#: drift apart, so a new default cannot land in only one of them.
SETTINGS: dict[str, str] = {
    # Discovery: read the network and record what is on it.
    "provision_discovery": "1",
    # The ARP sweep: the one source that sends packets. Off until asked for.
    "provision_discovery_sweep": "0",
    # Networks to sweep, comma separated CIDRs; empty = sweep nothing.
    "provision_discovery_networks": "",
    # Extensions a discovered phone may be provisioned on with no human in the
    # loop (a range like "5000-5099"); empty = never provision automatically.
    "provision_discovery_pool": "",
    # vendor=model per line, e.g. "yealink=yealink-t46u". A vendor that is not
    # listed is never provisioned automatically: a MAC says who made the phone,
    # never which model it is. Read by the portal, not by this engine, and here
    # so the parity test can see that both sides know the same five keys.
    "provision_discovery_models": "",
}

#: The AiPBX DHCP server's lease file (PBX -> Network services -> DHCP).
DEFAULT_LEASE_FILE = "/var/lib/aipbx-netsvc/dnsmasq.leases"
#: The kernel's neighbour table: what talked on the local segment recently.
DEFAULT_ARP_FILE = "/proc/net/arp"
#: State and log of the engine itself.
DEFAULT_STATE_DIR = "/var/lib/aipbx/engine"

# --- limits -----------------------------------------------------------------
# The sweep is the only part of the engine that touches the network, so it is
# bounded on all four axes: how many networks, how many addresses, how fast, and
# for how long. The bound is deliberately not a setting: an administrator who
# wants a wider scan can run the engine twice.

#: Addresses swept per network, at most.
MAX_HOSTS_PER_NETWORK = 1024
#: Seconds between two ARP requests (100/s, far below anything a switch minds).
SWEEP_GAP_SECONDS = 0.01
#: Wall-clock budget of one run's sweep; the rest of the network is left for the
#: next run, and the log line says so.
SWEEP_BUDGET_SECONDS = 30.0
#: Networks swept per run.
MAX_NETWORKS_PER_RUN = 2
#: How far back dnsmasq's DHCP journal is read.
JOURNAL_MINUTES = 15
#: The engine's log file is truncated to this size when it grows past it, so a
#: long-running installation needs no logrotate rule.
LOG_MAX_BYTES = 512 * 1024


def read_env(path: str = ENV_FILE) -> dict:
    """Parses the portal's environment file (key=value, # comments)."""
    env: dict = {}
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            for line in handle:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                env[key.strip()] = value.strip().strip("'\"")
    except OSError:
        return {}
    return env


def db_config(env: dict | None = None) -> dict:
    """Connection settings, with the same names the portal uses."""
    env = read_env() if env is None else env
    return {
        "host": env.get("DB_HOST", "localhost"),
        "user": env.get("DB_USER", ""),
        "password": env.get("DB_PASS", ""),
        "database": env.get("DB_NAME", "asterisk"),
        "charset": "utf8mb4",
    }


def lease_file() -> str:
    return os.environ.get("AIPBX_DHCP_LEASE_FILE") or DEFAULT_LEASE_FILE


def arp_file() -> str:
    return os.environ.get("AIPBX_ARP_FILE") or DEFAULT_ARP_FILE


def state_dir() -> str:
    return os.environ.get("AIPBX_ENGINE_STATE") or DEFAULT_STATE_DIR


def log_file() -> str:
    return os.path.join(state_dir(), "engine.log")


def state_file() -> str:
    return os.path.join(state_dir(), "state.json")


def sys_class_net() -> str:
    return os.environ.get("AIPBX_SYS_CLASS_NET") or "/sys/class/net"
