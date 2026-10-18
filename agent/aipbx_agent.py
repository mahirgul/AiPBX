#!/usr/bin/python3 -I
"""AiPBX connector agent — outbound-only, stdlib only.

AiPBX as a node of an EchoGuard-compatible cloud (app.echoguard.ca):
polls for allowlisted commands, runs them through aipbx_ops.php and posts the
results back. Opens no inbound ports and needs no cloud-side credentials
beyond the one-time node key.

The protocol is EchoGuard's, unchanged — same `X-Echoguard-Node` header, same
/api/fpbx/agent/{claim,poll,results} endpoints, same op vocabulary — so an
AiPBX box shows up next to a FreePBX box in the tenant's node list instead of
requiring a second, parallel integration.

WHERE THIS DIFFERS FROM THE FREEPBX AGENT, ON PURPOSE:

  * It never claims to be something it is not. The FreePBX agent reports
    `freepbx_version`; AiPBX reports `platform: aipbx` and its own version.
    Sending a fabricated FreePBX version would be the "metadata lied about the
    content" failure EchoGuard already hit once with RTCP arriving as SIP.
  * Ops the node is not asked to do are NOT translated into something similar.
    AiPBX has no FreePBX module layer, so `module_reload` accepts only the
    modules AiPBX really has; anything else comes back as a refusal with the
    reason (see web/bin/aipbx_ops.php). A cloud command answers honestly or
    not at all — never "ok" for work that was not done.
  * Reloads do not invent a privileged path. The `reload` op calls AiPBX's own
    applyPendingSync(), which is the same code path, the same config rollback
    and the same audit log the portal's Apply button uses.
"""

import argparse
import configparser
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

CONF = "/etc/aipbx/agent.conf"
STATE = "/var/lib/aipbx/agent-state.json"
LAST_STATUS = "/var/lib/aipbx/last-status.json"
OPS_PHP = "/usr/local/bin/aipbx_ops.php"
MODULE_DIR = os.path.dirname(os.path.abspath(__file__))
VERSION = "0.1.0"

# The shared op vocabulary — identical to the cloud's ALLOWED_OPS and to
# aipbx_ops.php. Re-checked here so a compromised or stale cloud cannot talk
# this node into running anything else; the vendor-specific argument checks
# stay in aipbx_ops.php, which is the single place that knows what AiPBX can
# actually do.
AGENT_OPS = ("snapshot", "diagnose", "reload", "module_reload")

DEFAULT_INTERVAL = 10
OP_TIMEOUT = 120


def load_conf(path=None):
    # Resolved at call time (not as a default argument) so the config location
    # can be redirected — that is what the tests do.
    path = path or CONF
    cp = configparser.ConfigParser()
    if not cp.read(path):
        raise SystemExit(f"missing config {path} — see agent/agent.conf.example")
    sect = cp["aipbx"]
    conf = {
        "endpoint": sect["endpoint"].rstrip("/"),
        "node_id": sect["node_id"],
        "key": sect["key"],
        "ops_php": sect.get("ops_php", OPS_PHP),
    }
    if "platform_version" in sect:
        conf["platform_version"] = sect["platform_version"]
    return conf


def load_state():
    try:
        with open(STATE) as fh:
            state = json.load(fh)
    except (OSError, ValueError):
        return {}
    return state if isinstance(state, dict) else {}


def save_state(state):
    parent = os.path.dirname(STATE)
    if parent:
        os.makedirs(parent, exist_ok=True)
    tmp = STATE + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(state, fh)
    os.replace(tmp, STATE)


def write_status(payload):
    try:
        parent = os.path.dirname(LAST_STATUS)
        if parent:
            os.makedirs(parent, exist_ok=True)
        with open(LAST_STATUS, "w") as fh:
            json.dump(payload, fh)
    except OSError:
        pass


def platform_version(conf):
    """The AiPBX version this node runs — never a stand-in for a FreePBX one."""
    if conf.get("platform_version"):
        return str(conf["platform_version"])[:40]
    for candidate in (
        os.path.join(MODULE_DIR, "VERSION"),
        "/usr/local/lib/aipbx/VERSION",
        "/opt/aipbx/VERSION",
    ):
        try:
            with open(candidate) as fh:
                value = fh.read().strip()
            if value:
                return value[:40]
        except OSError:
            continue
    return "unknown"


class CloudError(Exception):
    def __init__(self, message, status=None):
        super().__init__(message)
        self.status = status


def post(path, body, conf, timeout=40):
    request = urllib.request.Request(
        conf["endpoint"] + path,
        data=json.dumps(body).encode(),
        headers={
            "Content-Type": "application/json",
            # EchoGuard's node-key header, unchanged: <node_id>:<key>.
            "X-Echoguard-Node": f"{conf['node_id']}:{conf['key']}",
            "User-Agent": f"aipbx-agent/{VERSION}",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            return json.loads(resp.read().decode() or "{}")
    except urllib.error.HTTPError as exc:
        raise CloudError(f"HTTP {exc.code}", status=exc.code) from exc


def run_op(op, args, conf):
    """One bounded op through AiPBX's own executor. No shell: argv only."""
    argv = ["php", conf["ops_php"], op, json.dumps(args)]
    proc = subprocess.run(argv, capture_output=True, text=True, timeout=OP_TIMEOUT)
    try:
        result = json.loads(proc.stdout or "{}")
    except ValueError:
        result = {"raw": proc.stdout[-4000:]}
    if proc.stderr:
        result["stderr"] = proc.stderr[-2000:]
    return proc.returncode, result


def execute(cmd, conf):
    """Run one cloud command, or record the refusal. Never raises."""
    op = str(cmd.get("op") or "")
    args = cmd.get("args") or {}
    if not isinstance(args, dict):
        args = {}
    if op not in AGENT_OPS:
        # The shared vocabulary, re-checked locally: not an AiPBX capability
        # question, just "this node never agreed to that".
        return -1, {"error": "op_not_allowed", "op": op, "allowed": list(AGENT_OPS)}
    try:
        return run_op(op, args, conf)
    except subprocess.TimeoutExpired:
        return -1, {"error": f"op timed out after {OP_TIMEOUT}s"}
    except Exception as exc:  # noqa: BLE001 — report, never crash the agent
        return -1, {"error": str(exc)}


def claim(conf):
    body = post(
        "/api/fpbx/agent/claim",
        {
            "agent_version": VERSION,
            "platform": "aipbx",
            "platform_version": platform_version(conf),
        },
        conf,
        timeout=20,
    )
    write_status(
        {
            "ok": True,
            "node_id": conf["node_id"],
            "platform": "aipbx",
            "checked_in": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        }
    )
    return body


def poll_once(conf, state):
    body = post("/api/fpbx/agent/poll", {}, conf, timeout=60)
    commands = body.get("commands") or []
    for cmd in commands:
        cmd_id = cmd.get("id")
        code, result = execute(cmd, conf)
        ok = code == 0
        seen = state.setdefault("seen", {})
        seen[str(cmd_id)] = True
        if len(seen) > 200:
            for old in list(seen)[:100]:
                seen.pop(old, None)
        state["last_command"] = {"id": cmd_id, "op": cmd.get("op"), "ok": ok}
        save_state(state)
        try:
            post(
                "/api/fpbx/agent/results",
                {
                    "command_id": cmd_id,
                    "ok": ok,
                    "exit_code": code,
                    "result": result,
                },
                conf,
                timeout=20,
            )
        except Exception as exc:  # noqa: BLE001 — the next poll inherits the state
            print(f"result post failed for {cmd_id}: {exc}", file=sys.stderr)
    return body


def cmd_run(args):
    conf = load_conf()
    state = load_state()
    waits = 0
    while True:
        try:
            claim(conf)
            break
        except (CloudError, urllib.error.URLError, OSError) as exc:
            waits += 1
            print(f"claim failed ({exc}); retrying in 30s", file=sys.stderr)
            if args.once:
                return 1
            time.sleep(30)
            if waits > 2880:  # one day of failures: stop hammering, systemd restarts us
                raise SystemExit("claim never succeeded")
    if args.once:
        poll_once(conf, state)
        return 0
    while True:
        try:
            poll_once(conf, state)
        except CloudError as exc:
            if exc.status in (401, 403):
                write_status({"ok": False, "error": "bad or revoked node key"})
                raise SystemExit(
                    "node key rejected — issue a new setup command in the portal and re-run install"
                ) from exc
            write_status({"ok": False, "error": str(exc)})
            print(f"poll failed: {exc}", file=sys.stderr)
        except Exception as exc:  # noqa: BLE001
            write_status({"ok": False, "error": str(exc)})
            print(f"poll failed: {exc}", file=sys.stderr)
        time.sleep(args.interval)


def cmd_status(args):
    conf = load_conf()
    print(f"endpoint : {conf['endpoint']}")
    print(f"node_id  : {conf['node_id']}")
    print(f"platform : aipbx {platform_version(conf)}")
    print(f"ops.php  : {conf['ops_php']}")
    try:
        claim(conf)
        print("check-in : ok")
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"check-in : FAILED ({exc})")
        return 1


def cmd_self_test(args):
    """What an operator runs after install: prove the node can do its job."""
    conf = load_conf()
    failures = 0
    ops_php = conf["ops_php"]
    if os.access(ops_php, os.R_OK):
        print(f"ops.php     : readable ({ops_php})")
    else:
        print(f"ops.php     : MISSING ({ops_php})")
        failures += 1
    try:
        code, result = run_op("snapshot", {}, conf)
        print(f"snapshot    : exit={code} keys={list(result)[:6]}")
        if code != 0:
            failures += 1
    except Exception as exc:  # noqa: BLE001
        print(f"snapshot    : FAILED ({exc})")
        failures += 1
    try:
        code, result = run_op("module_reload", {"module": "__probe__"}, conf)
        print(f"allowlist   : exit={code} ({result.get('error', 'no error')})")
        if code == 0:
            print("allowlist   : FAILED — a module outside the allowlist was accepted")
            failures += 1
    except Exception as exc:  # noqa: BLE001
        print(f"allowlist   : FAILED ({exc})")
        failures += 1
    try:
        claim(conf)
        print("cloud claim : ok")
    except Exception as exc:  # noqa: BLE001
        print(f"cloud claim : FAILED ({exc})")
        failures += 1
    return 1 if failures else 0


def main(argv=None):
    parser = argparse.ArgumentParser(description="AiPBX connector agent")
    parser.add_argument("--interval", type=int, default=DEFAULT_INTERVAL,
                        help="seconds between polls")
    sub = parser.add_subparsers(dest="cmd", required=True)
    run = sub.add_parser("run", help="claim, then poll forever")
    run.add_argument("--once", action="store_true", help="one poll cycle, for cron/testing")
    sub.add_parser("status", help="print config and check in once")
    sub.add_parser("self-test", help="verify ops bridge, allowlist and cloud reachability")
    args = parser.parse_args(argv)
    if args.cmd == "run":
        return cmd_run(args)
    if args.cmd == "status":
        return cmd_status(args)
    if args.cmd == "self-test":
        return cmd_self_test(args)
    return 0


if __name__ == "__main__":
    sys.exit(main())
