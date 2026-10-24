# Scheduled tasks

AiPBX can run background shell, PHP, or Python scripts on a cron schedule: for example to export CDR
records nightly to an SFTP server, synchronize contacts with an external CRM, run custom maintenance,
or poll an API.

**System → Scheduled tasks** (administrators only, off by default).

## Feature status

Scheduled tasks are disabled globally by default. When switched off, no tasks run, and **Run now** is
refused. Enable scheduled execution with the toggle at the top of the page.

## Configuration

Each task defines:

| Field | Description | Defaults / limits |
|---|---|---|
| **Task Name** | Identifying name | 1–100 characters |
| **Description** | Optional explanation of the task's purpose | |
| **Active** | Whether the schedule runs automatically | On / Off |
| **Cron Schedule** | Standard 5-field cron expression (`min hour day month weekday`) | Presets available |
| **Interpreter** | Script language: shell (`/bin/bash`), PHP (`/usr/bin/php`), or Python (`/usr/bin/python3`) | Shell |
| **Script Code** | The executable script code | |
| **Timeout** | Maximum execution time before termination | 5–3600 s (default 300 s) |
| **Memory Limit** | Maximum RAM allocated to the task cgroup | 32–4096 MB (default 256 MB) |
| **CPU Limit** | Maximum CPU quota percentage | 5–100 % (default 50 %) |
| **Network Access** | Allow outgoing network traffic from the sandbox | Off by default |

### Schedule presets

The cron schedule can be entered directly or selected from presets:
- **Every 5 minutes** (`*/5 * * * *`)
- **Every 15 minutes** (`*/15 * * * *`)
- **Every hour** (`0 * * * *`)
- **Every night at 03:00** (`0 3 * * *`)
- **Every Sunday at 03:00** (`0 3 * * 0`)

The task list shows when each task runs next.

## Sandbox security

Tasks run in a hardened `systemd-run` transient service unit:

- **Unprivileged user:** Tasks run strictly as the system user `aipbx-task` (created during
  installation). They **never run as root** and **never as the web server (`www-data`)**.
- **No unsandboxed fallback:** Tasks are dispatched only by the root cron runner
  (`/etc/cron.d/aipbx`). If `systemd-run` or the `aipbx-task` user is unavailable, the task is not run
  at all.
- **Isolated filesystem:** `ProtectSystem=strict` mounts the operating system read-only. Home
  directories (`ProtectHome=yes`) and devices (`PrivateDevices=yes`) are completely hidden. The task
  has a private `/tmp` directory (`PrivateTmp=yes`).
- **Writable directory:** The only directory writable by the script is its own dedicated working
  directory: `/var/lib/aipbx/tasks/<id>/`.
- **Network isolation:** When network access is disabled (default), the task runs in its own network
  namespace (`PrivateNetwork=yes`) containing only loopback. It cannot access the internet, local
  subnets, or internal PBX services.
- **Resource controls:** `RuntimeMaxSec`, `MemoryMax`, and `CPUQuota` enforce strict limits.
  `NoNewPrivileges=yes` and `RestrictSUIDSGID=yes` prevent privilege escalation.
- **Concurrency brake:** Only one instance of a task can run at a time. If a task is still active when
  its next scheduled run arrives, the overlapping run is skipped.

## Run now

Clicking **Run now** requests an immediate execution. To maintain security boundaries, the web portal
(`www-data`) does not execute the script directly; instead, it queues the request in the database. The
root dispatcher picks up the request and executes the task in the sandbox within a minute.

## History and output

Clicking **History & Logs** shows:
- **Recent runs:** Start time, duration, trigger source (`schedule` or `manual`), exit code, and error
  messages.
- **Output capture:** Captures up to the last 64 KB of standard output and standard error from the
  process.
- **Script versions:** AiPBX automatically records a new version in the version history whenever the
  script content is changed.

## Alarms and audit log

- **Alert bell:** If a task exits with a non-zero exit code or times out, an alert is raised in the
  administrator [alert bell](health.md#alert-bell) and included in the [health check](health.md#checks)
  *Other alerts* e-mail. A successful run resolves the alert automatically.
- **Audit trail:** Creating, editing, toggling, deleting, and requesting immediate execution of a task
  are recorded in the system audit log.

> Direct data access through curated database views (`aipbx_export`), per-task database credentials,
> and per-task REST API tokens is planned for a future update (see [Roadmap](roadmap.md)). Currently,
> scripts access external resources or APIs using their own configured credentials.
