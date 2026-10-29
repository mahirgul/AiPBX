# AiPBX in Docker

Two deployments, one codebase:

| | | |
|---|---|---|
| **[`compose.yaml`](../compose.yaml)** | One container per tier: `db`, `asterisk`, `web`, `signaling`, `ai` | The production shape. A portal update never restarts Asterisk. |
| **[`compose.allinone.yaml`](../compose.allinone.yaml)** | One container running the real `install.sh` | The quick testbed. Everything a host install has, in one `docker compose up`. |

Both run the same application code and generate their own secrets. Neither
ships a pre-built image with baked-in passwords.

```
                       ┌──────────────────── this host ────────────────────┐
  HTTP :80 ───────────▶│  web        PHP 8.4 + Apache, the portal          │
                       │             │ PDO                │ asterisk -rx    │
  SIP/RTP/WSS ────────▶│  asterisk   ◀── /var/run/asterisk (shared) ───────┘
  (host network)       │   Asterisk 22, host networking                     │
                       │             │ res_odbc          │ res_hep        │
                       │  db ◀───────┘      signaling ◀───┘  (127.0.0.1)   │
                       │  MariaDB, own     HEPv3 capture → same database    │
                       │  volume, loopback only                             │
                       │  ai         Whisper/Vosk STT, Piper/Kokoro TTS     │
                       │             (profile `ai`, loopback 8790/8791)     │
                       └────────────────────────────────────────────────────┘
```

## Why this split, and why host networking

The split exists for one reason first: **a deployed PBX must not drop calls
because a CSS file changed.** In the all-in-one container, pulling and
restarting the image kills Asterisk and every conversation on it. Here, `web`
is a different container from `asterisk`, so:

```sh
docker compose up -d --no-deps --build web   # portal updated, calls untouched
```

Second, the tiers have different needs: Asterisk wants the host's network and
real time, the AI runtime wants CPU (or a GPU host), MariaDB wants its own
durable volume, and the capture daemon writes to the database and nothing else.

`db` is the only service on a bridge network, published to `127.0.0.1` so that
nothing off the machine can open the database. `asterisk`, `web`, `signaling`
and `ai` use `network_mode: host`, and that is a deliberate, documented
compromise rather than an oversight:

- **RTP.** Media is thousands of UDP ports. Bridged, Docker proxies each
  published port in user space, which is latency and jitter on the one traffic
  a PBX cannot afford to delay.
- **The application talks to itself over loopback.** The portal calls
  `asterisk -rx` (a UNIX socket in `/var/run/asterisk`), Asterisk's dialplan
  calls `http://127.0.0.1/api/…`, the AI runtime listens on `127.0.0.1:8790`
  with AudioSocket on `8791`, and HEP arrives on `127.0.0.1:9061`. Those
  addresses are in the code and in the generated dialplan; bridging them means
  rewriting the application, which this PR does not pretend to do.

The result is a stack of separate images with separate lifecycles that still
share one host network namespace — isolation of *deployment and restart*, not
of *network*. If you need network isolation as well, the next step is the
multi-tenant shape in the issue: a SIP edge (Kamailio) and per-tenant Asterisk
containers, which is a different project from this one.

## Requirements

| | |
|---|---|
| Docker | 24 or newer with Compose v2, and permission to run host networking |
| Memory | 2 GB minimum for the four core services; +1 GB if you enable `ai` |
| Disk | ~4 GB for images (`ai` adds ~1.5 GB), plus volumes |
| Host ports | 80, 5060/udp+tcp, 5061/tcp, 8089/tcp, the RTP window, 9061/udp (loopback) |
| Host | Linux. `privileged` is **not** needed by this stack |

## Quick start

```sh
git clone https://github.com/mahirgul/AiPBX.git
cd AiPBX

./docker/gen-secrets.sh          # writes .env, docker/secrets/db.env, docker/secrets/init.env
docker compose up -d --build     # db → init → asterisk, web, signaling → cfg
```

The first `up` builds the images (a few minutes) and then runs `init` once: it
generates the deployment's secrets, writes `/etc/ai-pbx.env`, creates the schema,
loads the seed data, creates the admin user and records the RTP window. It stops
there on purpose — `asterisk_sync.php` reloads Asterisk after each file it writes
and rolls that file back when the reload fails, so a configuration written before
the daemon exists rolls itself back. That job is `cfg`, which runs once Asterisk
is healthy. Follow both with:

```sh
docker compose logs -f init      # schema, seed, admin, the RTP window
docker compose logs cfg          # the Asterisk config, once Asterisk is up
```

When `init` prints `aipbx-init: done` — it does not wait for `cfg` — open
**`http://<host>/`** and sign in as `admin`. The password is either
`ADMIN_PASSWORD` from `docker/secrets/init.env` or the one written to the state
volume:

```sh
docker compose exec web cat /var/lib/aipbx/credentials.txt
```

### Then, as on any install

1. **Server address** (PBX → Network settings): the host's LAN or public IP, so
   the SDP Asterisk sends carries an address clients can reach.
2. **Extensions, trunks, phones** — [docs](../docs/README.md). Every Apply
   rewrites `/etc/asterisk/pbx/*.conf` in the shared volume and reloads
   Asterisk through AMI.
3. **RTP**: `init` stores `AIPBX_RTP_RANGE` as the portal's `rtp_start`/`rtp_end`
   settings, and `rtp.conf` is generated from those settings — by `cfg` on a
   fresh deployment, by the portal on every Apply — so the range Asterisk opens
   is the range compose asked for. A wider window needs nothing else changed:
   there is no port publishing to keep in step.

## What each service is

| Service | Image | Why it is separate |
|---|---|---|
| `db` | `mariadb:11` (official) | The database's own volume and lifecycle; upgraded on its own schedule. Three users, as on a host install: runtime (DML), migrator (DDL), ODBC (CDRs). |
| `init` | `aipbx-web` | Runs once, after `db`. Idempotent: a failed run can be repeated, and a later run only fills gaps. Ends before the Asterisk configuration, which needs a live daemon. |
| `asterisk` | `aipbx-asterisk` | The tier that must never restart because of a portal change. Asterisk 22, the same package `install.sh` installs. |
| `web` | `aipbx-web` | PHP 8.4 + Apache with the portal, plus the `asterisk` CLI so the existing `asterisk -rx` code paths reach the daemon through the shared socket. |
| `cfg` | `aipbx-web` | Runs once, after `asterisk` is healthy. The `Sync*` classes write `/etc/asterisk/pbx/*.conf` and reload Asterisk to check each file, so a fresh deployment has a dialplan, transports and endpoint templates instead of an empty Asterisk. |
| `signaling` | `aipbx-signaling` | Writes `pbx_signaling_events`; if it stops, calls are unaffected. |
| `ai` | `aipbx-ai` | Large, CPU-hungry, optional (`--profile ai`). Movable to a GPU host. |

The volumes they share:

| Volume | Holds |
|---|---|
| `aipbx-conf` | `/etc/aipbx-env.d/ai-pbx.env` — every secret, written once by `init` |
| `aipbx-ai-conf` | `/etc/aipbx/ai.token` — the portal↔AI bearer token |
| `aipbx-asterisk-etc` | `/etc/asterisk` — seeded by the Asterisk image, owned afterwards by the portal |
| `aipbx-asterisk-run` | `/var/run/asterisk` — the CLI/AMI socket the portal uses |
| `aipbx-asterisk-lib`, `-spool` | Sounds, music on hold, voicemail, recordings, the fax spool |
| `aipbx-faxes`, `aipbx-state`, `aipbx-logs` | Faxes, `/var/lib/aipbx`, `/var/log/aipbx` |
| `aipbx-db` | The MariaDB data directory |
| `aipbx-ai` | Downloaded models |

Group **gid 2000** (`aipbx`) exists in both the Asterisk and the portal image on
purpose: group names do not cross containers, group ids do, and the
configuration volume is written by `www-data` and read by `asterisk`.

## Updating

```sh
# the portal only — the common case, and calls are not affected
docker compose up -d --no-deps --build web

# Asterisk (module update, config that is read only at start)
docker compose up -d --no-deps --build asterisk

# the schema, after pulling new code
docker compose run --rm init
```

`init` is safe to run again: it keeps an existing `/etc/ai-pbx.env`, applies
only new migrations, and does not touch an existing admin user.

## Ports

Host networking means these are the ports the containers bind on the machine,
not a mapping. Change them in `.env` — the vhost and the Asterisk configs are
rendered from those variables at start-up.

| Port | What | Variable |
|---|---|---|
| 80/tcp | The portal | `AIPBX_HTTP_PORT` |
| 5060/udp, 5060/tcp | SIP | portal settings (`pjsip_transports.conf`) |
| 5061/tcp | SIP over TLS | portal settings |
| 8089/tcp | WebSocket for the browser phone | portal settings |
| 10000–10199/udp | RTP | `AIPBX_RTP_RANGE` |
| 9061/udp | HEPv3 capture (loopback) | `AIPBX_SIGNALING_LISTEN` |
| 8790, 8791 | AI HTTP and AudioSocket (loopback) | `AIPBX_AI_PORT`, `AIPBX_AI_AUDIOSOCKET_PORT` |
| 127.0.0.1:3306 | MariaDB | `AIPBX_DB_PORT` |

## What is not here yet

Said plainly, because the shape is a roadmap and not a finished product:

- **No 443 edge.** `install.sh` sets up an nginx ALPN multiplexer (HTTPS + WSS +
  TURNS on 443) and coturn. This stack serves HTTP and expects a reverse proxy
  in front for TLS. The portal's certificate pages and the TURN credentials
  page therefore do not have a backend here — the all-in-one container does,
  and the edge is the next piece of this roadmap.
- **No host-level admin actions.** Certificate renewal, firewall rules, the
  service control pages, the update page: they drive systemd units that a
  split deployment does not have. They fail with their own error messages
  rather than pretending. `aipbx-update` belongs to the host install (or the
  all-in-one container); here you update by rebuilding the `web` image.
- **No cron inside `web`.** The scheduled jobs (02:30 backup, phone discovery,
  queue-log sync, retention) still need a scheduler service; on a host install
  that is `/etc/cron.d/aipbx`. Enabling `cron` in the web image and running it
  is the intended follow-up; it is deliberately not half-done here.
- **One Asterisk per host.** Tenancy is the other half of the issue's roadmap,
  not part of this change.

## All-in-one container (testbed)

```sh
docker compose -f compose.allinone.yaml up -d --build
docker compose -f compose.allinone.yaml exec aipbx journalctl -u aipbx-bootstrap -f
```

`install.sh` runs inside the container on first boot, so you get the complete
product — 443 edge, coturn, mDNS, phone DHCP/TFTP, the lot — in one command. It
needs `privileged: true` and `cgroup: host` (MariaDB wants `CAP_SYS_ADMIN`,
systemd wants the host's cgroup hierarchy), which is why it is a testbed and not
the recommended deployment: a privileged container is close to root on the host.

Everything from the previous version of this document still applies to it: the
first boot takes as long as apt does (6 to 30 minutes), the credentials are in
`journalctl -u aipbx-bootstrap` and `/var/lib/aipbx/credentials.txt`, the cron
daemon and the `/var/www/html` link are re-applied on every boot because the
installer runs only once, and `docker compose down -v` is the reset.

## Secrets and rotation

Credentials are split by scope and `compose.yaml` contains none of them. Each
file below is written once by `./docker/gen-secrets.sh`, owned by the user who
ran it, `0600`, inside a `0700` directory that `.gitignore` excludes:

| File | Compose gives it to | Holds |
|---|---|---|
| `.env` | compose itself | names, ports, domain, timezone — no secrets |
| `docker/secrets/db.env` | `db` | the MariaDB root password |
| `docker/secrets/init.env` | `init` | root password, the three users, AMI, first admin |

Compose hands a service exactly the variables in its `env_file`, which is why
there are three files and not one: `asterisk`, `web`, `signaling` and `ai` read
`DB_PASS`, `MIGRATOR_DB_PASS`, `ODBC_DB_PASS` and `AMI_PASS` from
`/etc/ai-pbx.env` on the shared volume, so passing those through the
environment would only spread secrets to containers that never read them.

Rotating one is two changes, because two things now hold it — what the container
is given, and the account MariaDB already created:

1. Put the new value in the file in the table above. The root password is in
   **both** `db.env` and `init.env`; they have to agree or `init` cannot create
   anything.
2. Change it in MariaDB: `docker compose exec db mariadb -uroot -p`, then
   `ALTER USER ... IDENTIFIED BY '...'` for the root, runtime, migrator and ODBC
   accounts as needed.
3. Change the matching line in `/etc/aipbx.env` on the `aipbx-conf` volume —
   that is the copy the portal, Asterisk and signaling actually read. `init`
   leaves an existing file alone, so edit it rather than deleting it: a fresh
   one would also re-roll `TURN_SECRET` and `CHAT_JWT_SECRET` and sign everyone
   out.
4. `docker compose up -d`. `db` restarts with the new root password, `asterisk`
   re-renders `res_odbc.conf` from the env file on start, and the portal reads
   it on every request.

## Security

- **Secrets are generated per deployment**, by `./docker/gen-secrets.sh` and
  `aipbx-init`. No credential is written in `compose.yaml`, so a checkout holds
  none; the generated files are `0600` in a `0700` directory, and
  `/etc/ai-pbx.env` inside the volume is `0640 root:aipbx`.
- **A secret reaches only the container that reads it.** `db` is given the root
  password and `init` the accounts it creates; `asterisk`, `web`, `signaling`
  and `ai` are given none, because they read `/etc/ai-pbx.env` from the shared
  volume (see "Secrets and rotation").
- **The database is loopback-only.** No port is published off the machine.
- **No `privileged` in the split stack.** The only elevated capability in use is
  host networking for Asterisk, which is what SIP and RTP need.
- **The volumes are the deployment.** Anyone who can read them can read every
  secret — back them up like `/etc`.
- `aipbx-asterisk` and `aipbx-web` both carry an Asterisk binary, because the
  portal's CLI calls and the daemon must be the same version. Pin
  `AIPBX_ASTERISK_VERSION` in both images when that matters to you.

## Troubleshooting

| Symptom | Look at |
|---|---|
| `init` exited non-zero | `docker compose logs init`. It is idempotent: fix the cause, `docker compose run --rm init` again. |
| `env file ...db.env: no such file` at `up` | `./docker/gen-secrets.sh` has not run, or `docker/secrets/` was removed. It only writes files that are missing, so running it again is safe. |
| Portal 500s after start | `docker compose logs web`; usually `/etc/ai-pbx.env` missing (init has not run) or MariaDB unreachable (`docker compose logs db`). |
| Asterisk will not start | `docker compose logs asterisk`. A config the portal generated is the first suspect: `docker compose exec asterisk asterisk -rx 'core show uptime'`, and check `/etc/asterisk/pbx/`. |
| Portal says Asterisk does not answer | The socket: `docker compose exec web ls -l /var/run/asterisk/`, and the AMI port in `/etc/ai-pbx.env` against `manager.conf`. |
| Calls connect, no audio | RTP: `docker compose exec asterisk grep rtp /etc/asterisk/rtp.conf` must equal `AIPBX_RTP_RANGE`, and the server address (PBX → Network settings) must be the host's. |
| Everything is wrong, start over | `docker compose down -v` — **deletes the database and every volume** — then `./docker/gen-secrets.sh --force` and `up -d --build`. |
