# AiPBX connector agent

Turns this AiPBX into a **node** of an EchoGuard-compatible cloud portal
(app.echoguard.ca). The portal can then see the box and run four allowlisted
operations on it — nothing else.

Outbound-only, stdlib-only Python 3: it opens no ports, needs no inbound
firewall rule, and talks to the cloud from behind NAT exactly like a desk
phone does. Operator guide: [docs/connector.md](../docs/connector.md).

## Why it exists

EchoGuard already ships a node protocol and a FreePBX agent
(`echoguard-freepbx/bin/echoguard-agent.py`). AiPBX had no agent, so an AiPBX
box could not be a node at all. This is that agent, for AiPBX — the same wire
contract, with AiPBX's own operations behind it:

| Cloud op | What AiPBX actually does |
|---|---|
| `snapshot` | AiPBX version, Asterisk version/uptime/channels, disk, pending change count |
| `diagnose` | PJSIP endpoint states, trunk registrations, AiPBX's own health checks, hints |
| `reload` | `applyPendingSync()` — the same code path as the portal's **Apply** button |
| `module_reload` | `core` and `pjsip` only (AiPBX has no FreePBX module layer) |

## Files

| Path | Installed to | Role |
|---|---|---|
| `agent/aipbx_agent.py` | `/usr/local/lib/aipbx/aipbx_agent.py` | the agent (importable, unit-tested) |
| `conf/sbin/aipbx-agent` | `/usr/local/sbin/aipbx-agent` | launcher (`run`, `status`, `self-test`) |
| `web/bin/aipbx_ops.php` | `/usr/local/bin/aipbx_ops.php` | the **sealed** op executor |
| `agent/agent.conf.example` | `/etc/aipbx/agent.conf` (yours) | endpoint, node id, node key |
| `agent/tests/test_agent.py` | — | protocol tests against a real local HTTP cloud |

## Install

1. In the portal: **Nodes → Add node**, and copy the setup command's
   `endpoint`, `node_id` and `key`. The key is shown once; only its SHA-256 is
   stored, so a lost key is re-issued, not recovered.
2. Write `/etc/aipbx/agent.conf` (0640 root:root) — see
   `agent/agent.conf.example`.
3. Enable it:

```bash
sudo aipbx-agent self-test      # ops bridge, allowlist, cloud reachability
sudo systemctl enable --now aipbx-agent
sudo journalctl -u aipbx-agent -f
```

The unit is installed but **left off** and carries
`ConditionPathExists=/etc/aipbx/agent.conf`, so an unconfigured box stays
inert instead of crash-looping.

## Security model

- **One sealed executor.** The agent can only run `web/bin/aipbx_ops.php`, and
  that script maps each op to one fixed call into AiPBX's own code. There is no
  free-form shell, no `proc_open($userString)`, no schema write.
- **The allowlist is checked twice**: as an op name by the agent, and again —
  including the module argument — by the op executor. A cloud that is stale or
  compromised cannot widen what this box will do.
- **Refusals are reported, never hidden.** An op outside the vocabulary, an
  unknown module, or a module that exists only in FreePBX comes back as a
  failure with a reason. A cloud command never gets "ok" for work that was not
  done.
- **Reloads reuse the hardened path.** `reload` runs AiPBX's own
  `applyPendingSync()` (config rollback + audit log included). Nothing gets
  root through a new door: the connector adds no sudoers entry and no
  privileged helper.
- **Every applied change is audited** in `sys_audit_log` like any portal edit.
- **Key handling:** the node key lives only in `/etc/aipbx/agent.conf` (0640
  root:root) and travels outbound in the `X-Echoguard-Node` header. Revoking
  the node in the portal makes the next poll a 401, and the agent then exits
  with a message instead of retrying forever.

## Tests

```bash
python3 -m unittest discover -s agent/tests -t agent/tests -v   # protocol (fake cloud)
cd web && php vendor/bin/phpunit --filter OpsBridgeTest          # op contract (real subprocess)
```

The ops-bridge tests run the real script against the isolated test database
and the `AIPBX_NO_ASTERISK` lock — no command ever reaches a live Asterisk.
