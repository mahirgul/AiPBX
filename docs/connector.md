# Cloud connector (remote monitoring and operations)

AiPBX can register itself as a **node** of a cloud portal that speaks the
EchoGuard node protocol (app.echoguard.ca), so an administrator can see this
box in the portal and run a small, fixed set of operations on it from there.

The connector is **off until you configure it**, and it is **outbound-only**:
the agent polls the cloud, receives commands there and posts results back. It
opens no port, needs no port forwarding and works behind NAT. Nothing in the
portal can reach the portal itself, the database or a shell.

| | |
|---|---|
| Agent | `aipbx-agent` (`/usr/local/lib/aipbx/aipbx_agent.py`) |
| Op executor | `aipbx_ops.php` — the **only** thing the agent may run |
| Configuration | `/etc/aipbx/agent.conf` (0640 root:root) |
| State / last status | `/var/lib/aipbx/agent-state.json`, `/var/lib/aipbx/last-status.json` |
| Service | `aipbx-agent.service` (installed disabled) |

## Setting it up

1. In the cloud portal, add a node and copy the **endpoint**, **node id** and
   **node key** from the setup command. The key is displayed once — the portal
   stores only its SHA-256 hash, so if you lose it, the node is re-issued
   rather than recovered.
2. Create the configuration:

   ```bash
   sudo install -d -m 0750 /etc/aipbx
   sudo install -m 0640 /dev/null /etc/aipbx/agent.conf
   sudo editor /etc/aipbx/agent.conf
   ```

   ```ini
   [aipbx]
   endpoint = https://app.echoguard.ca
   node_id  = 123
   key      = <one-time node key>
   ```

3. Check it, then start it:

   ```bash
   sudo aipbx-agent self-test     # op bridge, allowlist and cloud check-in
   sudo systemctl enable --now aipbx-agent
   sudo systemctl status aipbx-agent
   ```

   `self-test` exits non-zero and names the failing part, so it is safe to run
   from a script after an install. The other subcommands are `status` (print
   the configuration and check in once) and `run` (`--once` for a single poll
   cycle from cron).

The unit stays inert while `/etc/aipbx/agent.conf` is missing
(`ConditionPathExists`), so an unconfigured installation does not restart in a
loop.

## The operations the cloud may run

Four, and only four. Names are the cloud's own, so an operation can never mean
one thing here and another there.

| Op | What happens on AiPBX |
|---|---|
| `snapshot` | Read-only report: AiPBX version, Asterisk version, uptime and channel count, disk usage, how many changes are waiting on **Apply** |
| `diagnose` | PJSIP endpoint states (idle / busy / down), trunk registrations, the same **Health checks** the portal runs, and a short list of hints |
| `reload` | Runs `applyPendingSync()` — identical to pressing **Apply** on */pending-sync*: regenerate the changed configurations, reload per domain, roll back a configuration Asterisk rejects, write the audit log |
| `module_reload` | `core` or `pjsip` only |

Everything the agent reports is what the portal shows; there is no second
opinion to reconcile.

### What is refused, and why

- Any op outside the four above: refused by the agent **and** by the op
  executor (the list is checked in both places on purpose).
- `module_reload` with any module other than `core` and `pjsip`: refused with
  `unsupported_module`. The cloud's module list (`ivr`, `timeconditions`,
  `ringgroups`, `queues`, `sipsettings`, …) is **FreePBX's GUI module layer**;
  AiPBX has no such modules — its IVRs, ring groups and queues are database
  records, and the way to push an edit to Asterisk is `reload` (Apply), not a
  module reload. Translating one name silently into the other would mean
  reporting success for an action nobody asked for, so it is refused instead.
- Refusals come back as failures with a reason, so an operator sees *why* in
  the portal rather than a command stuck at "pending".

> **Integration note.** Because that module list is FreePBX-shaped, the cloud
> currently assumes every node is FreePBX. An AiPBX node reports
> `platform: aipbx` and its own version (never a fabricated `freepbx_version`),
> but the portal has no vendor field to validate against yet. Until the portal
> carries one, treat `module_reload` from a tenant as AiPBX-unavailable and use
> `reload` for changes.

## Security

- The agent can run exactly one program — `aipbx_ops.php` — which maps each op
  to one fixed call inside AiPBX (`AsteriskHelper`, `applyPendingSync()`).
  There is **no free-form shell**, no argument ever reaches a shell, and no
  schema write.
- `reload` reuses the portal's own apply path, so a cloud-initiated change goes
  through the same config generation, the same rollback on failure and the same
  `sys_audit_log` record as a change made in the portal.
- The connector adds **no** sudoers entry and **no** privileged helper; the
  existing `aipbx-priv` root gateway is untouched.
- The node key sits in `/etc/aipbx/agent.conf` (0640 root:root) and is sent
  outbound only, in the `X-Echoguard-Node` header. If a key is exposed,
  **revoke the node in the portal**: the next poll returns 401 and the agent
  stops with a message telling you to re-issue it.
- The agent runs with `NoNewPrivileges`, `PrivateTmp` and `ProtectHome`.

## When it does not work

| Symptom | Check |
|---|---|
| Service inactive with "condition failed" | `/etc/aipbx/agent.conf` does not exist — the unit is deliberately inert |
| Node shows as offline in the portal | `sudo aipbx-agent self-test`, then `journalctl -u aipbx-agent -n 50` |
| `claim failed … HTTP 401` | The key was revoked or mistyped — re-issue the node in the portal |
| A command stays "pending" | `journalctl -u aipbx-agent -f` while re-running it; a result post failure is logged and retried on the next poll |
| `reload` returned a failure | The domain's error text is in the result **and** in the audit log; the configuration was rolled back |

## Related

- [Health checks](health.md) — what `diagnose` reports; the same checks the portal shows.
- [Security](security.md) — firewall, fail2ban and the `aipbx-priv` root helper.
- SIP signalling capture (HEP) and forwarding to the same portal is a separate
  component (`signaling/`), documented with the reporting contract.
