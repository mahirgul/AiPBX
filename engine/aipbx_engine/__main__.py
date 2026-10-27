"""The engine's command line: ``python3 -m aipbx_engine <command>``.

    once      one detection cycle (what the timer runs every five minutes)
    status    what the last run saw, plus the settings and log tail — the same
              facts the Phones page shows, for an operator on a shell
    check     exits 0 when the engine could run: the environment file, the
              database, the vendor table and the input files are all readable

``once`` is the only command that writes; ``status`` and ``check`` are read-only
on purpose, so an operator can look without changing what the next run sees.
"""

from __future__ import annotations

import argparse
import json
import sys

from . import __version__, config, detect, log, oui, sources
from .db import Store


def cmd_once(args) -> int:
    result = detect.run(Store())
    print(result.line)
    if args.json:
        print(json.dumps(result.as_dict(), indent=2, sort_keys=True))
    return 0 if not result.error else 1


def cmd_status(args) -> int:
    store = Store()
    settings = store.settings()
    state = log.state()
    print(f"aipbx-engine {__version__}")
    print(f"settings file     : {config.ENV_FILE}"
          + ("" if config.read_env() else "  (unreadable — is the engine running as its own user?)"))
    print(f"discovery         : {'on' if detect.enabled(store, settings) else 'off'}")
    print(f"sweep             : {'on' if Store.is_on(settings.get('provision_discovery_sweep', '0')) else 'off'}"
          f"  networks: {settings.get('provision_discovery_networks') or '(none)'}")
    print(f"extension pool    : {settings.get('provision_discovery_pool') or '(none — nothing is provisioned automatically)'}")
    print(f"lease file        : {config.lease_file()}  ({len(sources.read_leases())} leases)")
    print(f"arp file          : {config.arp_file()}  ({len(sources.read_arp())} neighbours)")
    print(f"this host's networks: {', '.join(sources.local_networks()) or '(unknown)'}")
    print(f"vendor table      : {len(oui.VENDORS)} vendors, "
          f"{sum(len(p) for p in oui.OUI.values())} prefixes")
    if store.last_error:
        print(f"database          : {store.last_error}")
    if state:
        print(f"last run          : {state.get('seen', 0)} seen, {state.get('new', 0)} new, "
              f"{state.get('known', 0)} known, {state.get('provisioned', 0)} already provisioned")
    else:
        print("last run          : (none recorded)")
    tail = log.tail(args.lines)
    if tail:
        print("\nlog:")
        for line in tail:
            print(f"  {line}")
    return 0


def cmd_check(args) -> int:
    problems: list[str] = []

    if not config.read_env():
        problems.append(f"{config.ENV_FILE} is missing or unreadable (database credentials live there)")

    store = Store()
    store.settings()
    if store.last_error:
        problems.append(f"database: {store.last_error}")

    if not oui.OUI:
        problems.append("the vendor table is empty, so nothing can be recognised as a phone")

    if not sources.read_leases() and not sources.read_arp() and not sources.read_journal():
        problems.append("no source produced a single device: check the DHCP lease file and /proc/net/arp")

    if not log.tail(1):
        problems.append(f"{config.state_dir()} is not writable, so the engine can keep no log or state")

    for problem in problems:
        print(f"PROBLEM: {problem}")
    if problems:
        return 1
    print("engine check: ok")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="aipbx_engine", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--version", action="version", version=__version__)
    sub = parser.add_subparsers(dest="command")

    once = sub.add_parser("once", help="one detection cycle")
    once.add_argument("--json", action="store_true", help="also print the counters as JSON")
    once.set_defaults(func=cmd_once)

    status = sub.add_parser("status", help="what the last run saw (read-only)")
    status.add_argument("--lines", type=int, default=10, help="log lines to show (default 10)")
    status.set_defaults(func=cmd_status)

    check = sub.add_parser("check", help="exit 0 when the engine could run")
    check.set_defaults(func=cmd_check)

    args = parser.parse_args(argv)
    if not getattr(args, "func", None):
        parser.print_help()
        return 2
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
