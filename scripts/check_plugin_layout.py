#!/usr/bin/env python3
"""Keep plugin-shaped code out of the core tree.

AiPBX 1.34+ splits into a core repo (telephony) and a plugins repo (AI,
telemetry, agents). Our fork has to look the same way, or the split only exists
in an email. This script knows which files belong to which plugin and reports
any that are still sitting in core paths.

usage:
  python3 scripts/check_plugin_layout.py                     # report
  python3 scripts/check_plugin_layout.py --strict            # exit 1 on any violation
  python3 scripts/check_plugin_layout.py --strict-manifests  # exit 1 on a bad plugin.json only
  python3 scripts/test_check_plugin_layout.py                # prove the checks work

The two gates are separate on purpose. Until the plugin loader lands, the
files below still live in core paths; that punch list is a report, not a
regression. A malformed `plugin.json` is a regression today, so CI runs with
`--strict-manifests` and `--strict` becomes the gate once the move is done.

The mapping below is the single place to edit when a plugin gains or loses a
file. `plugins/<slug>/plugin.json` manifests are validated too, so a plugin we
scaffold here matches the format Mahir's loader expects.
"""

from __future__ import annotations

import argparse
import fnmatch
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# slug -> paths (globs, relative to the repo root) that belong to that plugin
OWNERSHIP = {
    "ai-assistant": [
        "web/src/controllers/AssistantController.php",
        "web/src/services/ai/Assistant*.php",
        "web/api/assistant.php",
        "web/templates/views/assistant/*",
        "web/db/migrations/*create_ai_changes.php",
        "web/tests/unit/Assistant*.php",
        "docs/assistant.md",
        "plugins/ai-assistant/*",
    ],
    "signaling-capture": [
        "signaling/aipbx_signaling/*.py",
        "signaling/tests/*.py",
        "asterisk-config/hep.conf",
        "conf/systemd/aipbx-signaling.service",
        "docs/signaling.md",
        "plugins/signaling-capture/*",
    ],
    "call-quality": [
        "web/src/services/CallQualityService.php",
        "web/tests/unit/CallQualityServiceTest.php",
        "web/bin/aipbx_quality.php",
        "web/db/migrations/*rtcp_metrics*",
        "web/db/migrations/*quality_evidence*",
        "plugins/call-quality/*",
    ],
    "ai-traffic": [
        "web/src/services/ai/OpencodeTrafficService.php",
        "web/tests/unit/OpencodeTrafficServiceTest.php",
        "web/db/migrations/*opencode_exchanges*",
        "plugins/ai-traffic/*",
    ],
    "greg": [
        "web/src/services/Greg*.php",
        "web/src/services/ai/HepComputeService.php",
        "web/tests/unit/Greg*Test.php",
        "web/tests/unit/HepComputeTest.php",
        "web/bin/greg_watch.php",
        "web/templates/views/ai_echo/*",
        "plugins/greg/*",
    ],
}

MANIFEST_REQUIRED = ("slug", "name", "version", "author", "scopes")

# `author` names the plugin's maintainer, not the platform it plugs into. The
# official plugins live in the core repo's plugins/ folder and need no author at
# all; these five are ours and are published as community plugins, so a manifest
# that says "AiPBX" claims official status we do not have and sends bug reports
# to someone who did not write the code. One publisher, one value, everywhere.
PLATFORM_AUTHOR = "AiPBX"

# Settings keys a plugin's own code asks the loader for. If a key is read but
# not declared in settings_schema, the portal never offers it for editing and
# the plugin silently runs on defaults — the failure mode this catches.
# Both `getAllSettings('slug')['key']` and the usual assignment-then-read
# (`$cfg = PluginManager::getAllSettings('slug', false); ... $cfg['key']`) count.
SETTINGS_LOAD = re.compile(r"\$(\w+)\s*=\s*[^;]*?getAllSettings\(\s*'([^']+)'")
SETTINGS_CHAINED = re.compile(r"getAllSettings\(\s*'([^']+)'(?:\s*,\s*(?:true|false))?\s*\)\s*\[\s*'(\w+)'\s*\]")
HOOK_KEYS = ("admin_page", "sync_domain", "events")


def read_text(path: str, errors: str = "strict") -> str:
    with open(path, encoding="utf-8", errors=errors) as fh:
        return fh.read()


def manifest_settings_keys(schema) -> set[str]:
    """The keys a manifest offers for editing."""
    if not isinstance(schema, list):
        return set()
    return {entry["key"] for entry in schema
            if isinstance(entry, dict) and isinstance(entry.get("key"), str) and entry["key"]}


def manifest_detail_problems(slug: str, data: dict) -> list[str]:
    """The parts of the contract the loader reads: hooks and settings_schema."""
    where = f"plugins/{slug}/plugin.json"
    problems: list[str] = []

    hooks = data.get("hooks")
    if hooks is not None:
        if not isinstance(hooks, dict):
            problems.append(f"{where}: 'hooks' must be an object")
        else:
            for name in hooks:
                if name not in HOOK_KEYS:
                    problems.append(f"{where}: unknown hook '{name}'")
            page = hooks.get("admin_page")
            if page is not None and not isinstance(page, dict):
                problems.append(f"{where}: hooks.admin_page must be an object")
            elif isinstance(page, dict) and not (page.get("route") or page.get("group")):
                problems.append(f"{where}: hooks.admin_page needs a route or a group")
            events = hooks.get("events")
            if events is not None and not isinstance(events, list):
                problems.append(f"{where}: hooks.events must be a list")

    schema = data.get("settings_schema")
    if schema is not None:
        if not isinstance(schema, list):
            problems.append(f"{where}: settings_schema must be a list")
        else:
            seen = set()
            for entry in schema:
                if not isinstance(entry, dict) or not entry.get("key"):
                    problems.append(f"{where}: every settings_schema entry needs a key")
                    continue
                key = entry["key"]
                if key in seen:
                    problems.append(f"{where}: settings key '{key}' declared twice")
                seen.add(key)
                if not entry.get("type"):
                    problems.append(f"{where}: settings '{key}' has no type")
                if entry.get("secret") and entry.get("type") != "password":
                    problems.append(f"{where}: secret '{key}' must be type 'password'")
    return problems


def manifest_author_problems(slug: str, data: dict) -> list[str]:
    """Who maintains this plugin, and can a reader reach them?"""
    where = f"plugins/{slug}/plugin.json"
    problems: list[str] = []

    author = data.get("author")
    if not isinstance(author, str) or not author.strip():
        problems.append(f"{where}: 'author' must name who maintains the plugin")
    elif author.strip() == PLATFORM_AUTHOR:
        problems.append(
            f"{where}: 'author' is '{PLATFORM_AUTHOR}', the platform - it reads as "
            "an official plugin and routes bug reports to the wrong maintainer"
        )

    url = data.get("author_url")
    if url is not None and not (isinstance(url, str) and url.startswith("https://")):
        problems.append(f"{where}: 'author_url' must be an https:// URL")

    return problems


def repo_files() -> list[str]:
    out = []
    skip = ("/vendor/", "/node_modules/", "/.git/", "/__pycache__/",
            "/.phpstan-cache/", "/.pytest_cache/")
    for base, dirs, files in os.walk(ROOT):
        rel_base = os.path.relpath(base, ROOT).replace(os.sep, "/")
        if any(s.strip("/") in rel_base.split("/") for s in ("vendor", "node_modules", ".git", "__pycache__")):
            dirs[:] = []
            continue
        for name in files:
            rel = (rel_base + "/" + name) if rel_base != "." else name
            if any(s in "/" + rel for s in skip):
                continue
            out.append(rel)
    return sorted(out)


def main(argv: list[str] | None = None) -> int:
    global ROOT
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--strict", action="store_true", help="exit 1 when anything is misplaced")
    parser.add_argument("--strict-manifests", action="store_true",
                        help="exit 1 when a plugin.json breaks the loader contract, ignoring the core-path punch list")
    parser.add_argument("--root", help="scan this tree instead of the repo (used by the tests)")
    args = parser.parse_args(argv)
    if args.root:
        ROOT = os.path.abspath(args.root)

    files = repo_files()
    misplaced: dict[str, list[str]] = {}
    for slug, patterns in OWNERSHIP.items():
        for path in files:
            if not any(fnmatch.fnmatch(path, pat) for pat in patterns):
                continue
            if path.startswith(f"plugins/{slug}/"):
                continue
            misplaced.setdefault(slug, []).append(path)

    manifest_problems: list[str] = []
    by_slug: dict[str, set[str]] = {}
    authors: dict[str, str] = {}
    author_urls: dict[str, str] = {}
    plugins_dir = os.path.join(ROOT, "plugins")
    if os.path.isdir(plugins_dir):
        for slug in sorted(os.listdir(plugins_dir)):
            if not os.path.isdir(os.path.join(plugins_dir, slug)):
                continue  # plugins/README.md and friends are not plugin folders
            manifest = os.path.join(plugins_dir, slug, "plugin.json")
            if not os.path.isfile(manifest):
                manifest_problems.append(f"plugins/{slug}: no plugin.json")
                continue
            try:
                data = json.loads(read_text(manifest))
            except (OSError, ValueError) as exc:
                manifest_problems.append(f"plugins/{slug}/plugin.json: {exc}")
                continue
            for key in MANIFEST_REQUIRED:
                if not data.get(key):
                    manifest_problems.append(f"plugins/{slug}/plugin.json: missing '{key}'")
            if data.get("slug") and data["slug"] != slug:
                manifest_problems.append(f"plugins/{slug}/plugin.json: slug is '{data['slug']}'")
            manifest_problems += manifest_detail_problems(slug, data)
            manifest_problems += manifest_author_problems(slug, data)
            by_slug[slug] = manifest_settings_keys(data.get("settings_schema"))
            if isinstance(data.get("author"), str):
                authors[slug] = data["author"]
            if isinstance(data.get("author_url"), str):
                author_urls[slug] = data["author_url"]

    # One publisher: a catalog page listing five plugins from five names is a
    # data-entry accident waiting to happen, so disagreement is a manifest bug.
    for label, values in (("author", authors), ("author_url", author_urls)):
        if len(set(values.values())) > 1:
            listed = ", ".join(f"{slug}={value!r}" for slug, value in sorted(values.items()))
            manifest_problems.append(
                f"plugins/: manifests disagree on {label} ({listed}) - one publisher, one value"
            )

    # Code against manifest: anything a plugin reads must be a declared setting.
    for path in files:
        if not path.endswith(".php"):
            continue
        try:
            text = read_text(os.path.join(ROOT, path), errors="replace")
        except OSError:
            continue
        reads: list[tuple[str, str]] = list(SETTINGS_CHAINED.findall(text))
        for var, slug in SETTINGS_LOAD.findall(text):
            reads += [(slug, key) for key in re.findall(r"\$" + var + r"\s*\[\s*'(\w+)'\s*\]", text)]
        for slug, key in reads:
            known = by_slug.get(slug)
            if known is None:
                continue  # that plugin is not scaffolded here yet
            if key not in known:
                manifest_problems.append(
                    f"{path}: reads settings['{key}'] from '{slug}' but "
                    f"plugins/{slug}/plugin.json does not declare it"
                )

    total = sum(len(v) for v in misplaced.values())
    for slug in sorted(misplaced):
        print(f"{slug}: {len(misplaced[slug])} file(s) still in core paths")
        for path in misplaced[slug]:
            print(f"    {path}")
    for problem in manifest_problems:
        print(f"manifest: {problem}")

    # Worth saying even while the punch list is still open: the publisher is a
    # decision that has already been made, not a consequence of the move.
    if authors and not manifest_problems:
        print(f"ok every manifest is published by {next(iter(set(authors.values())))}")

    if not misplaced and not manifest_problems:
        print(f"ok every plugin file lives under plugins/ ({len(OWNERSHIP)} plugins declared)")
        return 0

    print(f"\n{total} misplaced file(s), {len(manifest_problems)} manifest problem(s).")
    if total:
        print("Moving them needs the plugin loader (web/src/core/PluginManager.php in")
        print("Mahir's 1.34+ core); without it, a plugin directory is inert and moving")
        print("the files would break the running portal. Until then this is the punch list.")

    if args.strict:
        return 1
    return 1 if (manifest_problems and args.strict_manifests) else 0


if __name__ == "__main__":
    sys.exit(main())
