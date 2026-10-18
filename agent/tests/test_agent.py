"""Tests for the AiPBX connector agent.

The cloud is a REAL local HTTP server, not a mock: the whole point of this
agent is a wire contract (header, three endpoints, body keys), and a mock would
happily accept the wrong header.

The ops bridge is a small stand-in PHP script that behaves like
web/bin/aipbx_ops.php: same argv contract, same JSON-on-stdout, same exit
codes. That keeps these tests about the AGENT while still failing if the agent
starts sending a different argument shape.
"""

import json
import os
import shutil
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

AGENT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if AGENT_DIR not in sys.path:
    sys.path.insert(0, AGENT_DIR)

import aipbx_agent as agent  # noqa: E402

PHP = shutil.which("php")

FAKE_OPS = """<?php
$marker = getenv('AIPBX_TEST_OPS_MARKER');
if ($marker) { file_put_contents($marker, ($argv[1] ?? '') . "\\n", FILE_APPEND); }
$op = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];
switch ($op) {
    case 'snapshot':
        fwrite(STDOUT, json_encode(['platform' => 'aipbx', 'aipbx_version' => '1.33.0']));
        exit(0);
    case 'reload':
        fwrite(STDOUT, json_encode(['applied' => ['udptl'], 'nothing_pending' => false]));
        exit(0);
    case 'module_reload':
        $module = $args['module'] ?? '';
        if ($module === 'core' || $module === 'pjsip') {
            fwrite(STDOUT, json_encode(['module' => $module, 'success' => true]));
            exit(0);
        }
        fwrite(STDOUT, json_encode(['error' => 'unsupported_module', 'module' => $module]));
        exit(64);
    default:
        fwrite(STDOUT, json_encode(['error' => 'unknown_op', 'op' => $op]));
        exit(64);
}
"""


class FakeCloud:
    """A faithful stand-in for the /api/fpbx/agent endpoints."""

    def __init__(self, node_id="7", key="secret-key"):
        self.node_id = node_id
        self.key = key
        self.commands = []
        self.requests = []          # [{'path','headers','body'}]
        self.fail_results = False
        self.server = None

    def start(self):
        cloud = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):  # quiet
                pass

            def do_POST(self):
                length = int(self.headers.get("Content-Length") or 0)
                raw = self.rfile.read(length).decode() or "{}"
                try:
                    body = json.loads(raw)
                except ValueError:
                    body = {}
                cloud.requests.append(
                    {
                        "path": self.path,
                        "headers": dict(self.headers),
                        "body": body,
                    }
                )
                if self.headers.get("X-Echoguard-Node") != f"{cloud.node_id}:{cloud.key}":
                    return self._json({"error": "bad_node_key"}, 401)
                if self.path.endswith("/claim"):
                    return self._json({"ok": True, "node_id": int(cloud.node_id)})
                if self.path.endswith("/poll"):
                    commands = cloud.commands
                    cloud.commands = []
                    return self._json({"commands": commands})
                if self.path.endswith("/results"):
                    if cloud.fail_results:
                        return self._json({"error": "result_failed"}, 500)
                    return self._json({"ok": True})
                return self._json({"error": "not_found"}, 404)

            def _json(self, payload, status=200):
                data = json.dumps(payload).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

        self.server = HTTPServer(("127.0.0.1", 0), Handler)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        return f"http://127.0.0.1:{self.server.server_port}"

    def stop(self):
        if self.server:
            self.server.shutdown()
            self.server.server_close()

    def path(self, suffix):
        for req in self.requests:
            if req["path"].endswith(suffix):
                return req
        return None

    def posts(self, suffix):
        return [r for r in self.requests if r["path"].endswith(suffix)]


@unittest.skipUnless(PHP, "php is required for the ops-bridge stand-in")
class AgentProtocolTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="aipbx-agent-test-")
        self.marker = os.path.join(self.tmp, "ops-calls.txt")
        self.ops_php = os.path.join(self.tmp, "fake_ops.php")
        with open(self.ops_php, "w") as fh:
            fh.write(FAKE_OPS)

        self.cloud = FakeCloud()
        endpoint = self.cloud.start()

        self.conf_path = os.path.join(self.tmp, "agent.conf")
        with open(self.conf_path, "w") as fh:
            fh.write(
                "[aipbx]\n"
                f"endpoint = {endpoint}\n"
                "node_id = 7\n"
                "key = secret-key\n"
                f"ops_php = {self.ops_php}\n"
            )

        # Redirect the agent's fixed paths at the temp dir.
        self._saved = (agent.CONF, agent.STATE, agent.LAST_STATUS)
        agent.CONF = self.conf_path
        agent.STATE = os.path.join(self.tmp, "state.json")
        agent.LAST_STATUS = os.path.join(self.tmp, "last-status.json")
        os.environ["AIPBX_TEST_OPS_MARKER"] = self.marker
        self.conf = agent.load_conf()

    def tearDown(self):
        agent.CONF, agent.STATE, agent.LAST_STATUS = self._saved
        os.environ.pop("AIPBX_TEST_OPS_MARKER", None)
        self.cloud.stop()
        shutil.rmtree(self.tmp, ignore_errors=True)

    def ops_calls(self):
        if not os.path.exists(self.marker):
            return []
        with open(self.marker) as fh:
            return [line.strip() for line in fh if line.strip()]

    def test_claim_sends_node_key_and_an_honest_platform(self):
        agent.claim(self.conf)
        req = self.cloud.path("/claim")
        self.assertIsNotNone(req)
        self.assertEqual(req["headers"]["X-Echoguard-Node"], "7:secret-key")
        self.assertEqual(req["headers"]["User-Agent"], f"aipbx-agent/{agent.VERSION}")
        self.assertEqual(req["body"]["platform"], "aipbx")
        self.assertIn("agent_version", req["body"])
        self.assertIn("platform_version", req["body"])
        # Never claim to be a FreePBX box: metadata must match the content.
        self.assertNotIn("freepbx_version", req["body"])

    def test_missing_node_key_is_rejected_not_ignored(self):
        agent.CONF = self.conf_path
        with open(self.conf_path, "w") as fh:
            fh.write(
                "[aipbx]\n"
                f"endpoint = http://127.0.0.1:{self.cloud.server.server_port}\n"
                "node_id = 7\n"
                "key = wrong-key\n"
                f"ops_php = {self.ops_php}\n"
            )
        conf = agent.load_conf()
        with self.assertRaises(agent.CloudError) as ctx:
            agent.claim(conf)
        self.assertEqual(ctx.exception.status, 401)

    def test_poll_runs_allowlisted_op_and_posts_the_result(self):
        self.cloud.commands = [{"id": 11, "op": "snapshot", "args": {}}]
        state = {}
        agent.poll_once(self.conf, state)

        self.assertEqual(self.ops_calls(), ["snapshot"])
        result = self.cloud.path("/results")
        self.assertIsNotNone(result)
        self.assertEqual(result["body"]["command_id"], 11)
        self.assertTrue(result["body"]["ok"])
        self.assertEqual(result["body"]["exit_code"], 0)
        self.assertEqual(result["body"]["result"]["platform"], "aipbx")
        self.assertEqual(state["last_command"], {"id": 11, "op": "snapshot", "ok": True})

    def test_op_outside_the_shared_vocabulary_never_reaches_php(self):
        self.cloud.commands = [{"id": 12, "op": "shell", "args": {"cmd": "id"}}]
        agent.poll_once(self.conf, {})

        self.assertEqual(self.ops_calls(), [])
        body = self.cloud.path("/results")["body"]
        self.assertFalse(body["ok"])
        self.assertEqual(body["result"]["error"], "op_not_allowed")
        self.assertIn("snapshot", body["result"]["allowed"])

    def test_freepbx_only_module_reports_failure_not_success(self):
        self.cloud.commands = [{"id": 13, "op": "module_reload", "args": {"module": "ivr"}}]
        agent.poll_once(self.conf, {})

        body = self.cloud.path("/results")["body"]
        self.assertFalse(body["ok"])
        self.assertEqual(body["exit_code"], 64)
        self.assertEqual(body["result"]["error"], "unsupported_module")

    def test_supported_module_reload_succeeds(self):
        self.cloud.commands = [{"id": 14, "op": "module_reload", "args": {"module": "core"}}]
        agent.poll_once(self.conf, {})

        body = self.cloud.path("/results")["body"]
        self.assertTrue(body["ok"])
        self.assertEqual(body["result"]["module"], "core")

    def test_check_in_writes_a_status_file(self):
        agent.claim(self.conf)
        with open(agent.LAST_STATUS) as fh:
            status = json.load(fh)
        self.assertTrue(status["ok"])
        self.assertEqual(status["platform"], "aipbx")
        self.assertEqual(status["node_id"], "7")

    def test_result_post_failure_is_survivable_and_recorded(self):
        self.cloud.fail_results = True
        self.cloud.commands = [{"id": 15, "op": "reload", "args": {}}]
        state = {}
        agent.poll_once(self.conf, state)  # must not raise
        with open(agent.STATE) as fh:
            saved = json.load(fh)
        self.assertEqual(saved["last_command"]["id"], 15)
        self.assertEqual(saved["seen"]["15"], True)

    def test_self_test_passes_with_a_working_node(self):
        self.assertEqual(agent.cmd_self_test(None), 0)


if __name__ == "__main__":
    unittest.main()
