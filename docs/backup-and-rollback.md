# Backups, Disaster Recovery & Upgrade Rollback

AiPBX is engineered for high-availability enterprise environments where telecommunications downtime is costly. The platform ships with:
- **Reproducible daily automated backups** (`aipbx-backup`)
- **Automated atomic rollback during upgrades** (`aipbx-update`)
- **Deterministic disaster recovery restoration procedures**

---

## 1. Automated Daily Backups (`aipbx-backup`)

AiPBX automatically configures a daily cron job (`/etc/cron.d/aipbx`) that executes `/usr/local/sbin/aipbx-backup` at **02:30** local time.

### 1.1. Archive Structure & Contents

Each backup execution produces a timestamped directory under `/var/backups/aipbx-daily/`:

```
/var/backups/aipbx-daily/aipbx-YYYYmmdd-HHMM/
├── db.sql.gz      # Complete mysqldump with triggers, routines, events & hex-blobs
└── config.tgz     # System configuration, certificates, sounds, and cryptographic keys
```

The configuration archive (`config.tgz`) preserves the full appliance state:
- `/etc/ai-pbx.env` (system settings, database credentials, domain name)
- `/etc/asterisk/` (complete telephony dialplan, PJSIP, queues, voicemail configs)
- `/var/lib/asterisk/sounds/custom/` & `/var/lib/asterisk/moh/custom/` (uploaded custom prompts and music-on-hold)
- `/etc/letsencrypt/` & `/etc/ssl/aipbx/` (TLS certificates and private keys)
- `/var/lib/aipbx/settings.key` (the master key required to decrypt database secrets)
- `/var/lib/aipbx/plugins/` (custom or installed plugins)
- `/etc/aipbx/` (AI service tokens and localized models metadata)

> [!IMPORTANT]
> The encryption master key (`/var/lib/aipbx/settings.key`) is included in `config.tgz`. Restoring a database dump without this key makes encrypted passwords (SMTP, S3 buckets, trunk secrets) unreadable. Keep these backup archives secured with root-only permissions (`0700` / `umask 077`).

### 1.2. Configuration & Off-Site Replication

Backup behavior is configured in `/etc/ai-pbx.env`:

```bash
# Destination directory on local disk (default: /var/backups/aipbx-daily)
AIPBX_BACKUP_DIR="/var/backups/aipbx-daily"

# Days to retain on disk before automatic pruning (default: 14)
AIPBX_BACKUP_KEEP_DAYS=14

# Optional: Off-site replication via SSH rsync (using root's SSH key)
AIPBX_BACKUP_COPY_TO="backup@nas.example.com:/srv/aipbx-backups"
```

When `AIPBX_BACKUP_COPY_TO` is specified, `aipbx-backup` automatically syncs the archives over SSH via `rsync -a --delete` upon successful completion.

### 1.3. Health Check Monitoring

AiPBX's internal [health monitoring engine](health.md#checks) monitors backup freshness every hour. If the newest backup directory in `/var/backups/aipbx-daily` is older than **26 hours**, the system immediately raises an alarm via the portal bell and sends an automated email notification to administrators.

---

## 2. Automated Atomic Upgrade Rollback (`aipbx-update`)

Upgrading production communications software can introduce risks (database migration errors, syntax changes, upstream package conflicts). To eliminate upgrade failure risk, `aipbx-update` executes an **atomic rollback safeguard**:

```
[ aipbx-update STARTED ]
       │
       ▼
1. Pre-Upgrade Snapshot ──► Creates full database dump + /etc/asterisk archive in /var/backups/aipbx/
       │
       ▼
2. Checkout & Apply     ──► git checkout $TARGET && bash install.sh --upgrade
       │
       ▼
3. Multi-Stage Health Verification:
   ├─ Check systemd services: mariadb, apache2, nginx, asterisk, aipbx-webhook-worker
   ├─ Portal HTTP verification: curl -k https://127.0.0.1/login == HTTP 200
   └─ Page integration tests: php bin/smoke.php (all routes, views, database queries)
       │
       ├─────────────────────────────────┐
 [ ALL CHECKS PASS ]             [ ANY CHECK FAILS ]
       │                                 │
       ▼                                 ▼
Upgrade Marked Success           AUTOMATIC ROLLBACK TRIGGERED:
                                 ├─ git checkout --force $CURRENT_COMMIT
                                 ├─ Restores previous database from SQL dump
                                 ├─ Restores /etc/asterisk and .env configurations
                                 ├─ Re-runs previous release install.sh --upgrade
                                 └─ Restarts services; calls remain uninterrupted!
```

### 2.1. Verification Checks

Before an upgrade is declared successful, three distinct layers must pass:
1. **Core Daemons:** `mariadb`, `apache2`, `nginx`, `asterisk`, and `aipbx-webhook-worker` must all be reported `active (running)` by systemd.
2. **Web Portal Health:** The web ingress must return an HTTP 200 OK on `/login` via local loopback.
3. **End-to-End Smoke Tests:** The test runner (`web/bin/smoke.php`) executes end-to-end rendering checks across administrative controllers and database tables.

If **any** stage fails, the script immediately logs the failure to `/var/log/aipbx/update.log`, reverts the code and database, and leaves the previous working version active.

---

## 3. The Backup & Restore Page

**Administration → Backup & Restore** is for administrators only. It lists the backups, and from there you can:

- **Back up now.** The nightly backup runs as well.
- **Download** a backup as a `.tar.gz` file. It holds every password and secret key of the system, so keep it safe. The download is written to the audit log.
- **Upload** a backup from this or another AiPBX server. It is checked and added to the list; nothing is restored until you choose to.
- **Restore** everything, or the database only. You type the name of the backup and your password. On a system with SSO-only sign-in, only the name is asked.
- **Set how many days** backups are kept.

A restore:

1. Refuses a backup from a newer release than this system. Update first.
2. Stops if calls are in progress, unless you tick the box. With the box ticked, Asterisk is reloaded rather than restarted.
3. Makes a backup of the present state (`aipbx-…-before-restore`).
4. Replaces the database and, unless you chose database only, the configuration.
5. Runs `install.sh --upgrade`, which applies migrations for an older backup, then regenerates the Asterisk configuration and restarts Asterisk.
6. Checks that the services run and the portal answers. If any step fails, it puts the state from step 3 back.

The portal may ask you to sign in again afterwards: the users are now those in the backup. A backup restored on another server brings the settings of the old server, including its portal address (`PORTAL_DOMAIN`), so check them afterwards.

**Uploaded backups are not trusted.** Only the files `aipbx-backup` writes are accepted. The database dump runs as the migration user, which has rights on this one database only; a dump asking for more stops the restore, and the safety backup goes back. Links out of their folder, device files, `#exec` in `/etc/asterisk`, and values in the environment file that could break into a generated configuration are refused. `asterisk.conf` is not taken from an uploaded backup, and the database dump cannot run client commands.

The page does not cover call recordings and faxes, which are not in the backup. From the command line: `sudo aipbx-restore NAME [--db-only] [--allow-calls]`. Progress is in `/var/log/aipbx/restore.log`.

---

## 4. Disaster Recovery by Hand

If the portal cannot be used, or you restore on a clean server without it:

### Step 1: Install a Clean Base System
Install AiPBX on a fresh Linux server using the standard turnkey installer:
```bash
curl -fsSL aipbx.bid/i | sudo bash
```

### Step 2: Transfer Your Backup Archive
Transfer the latest backup archive from your off-site storage to `/tmp/` on the new server:
```bash
scp -r backup@nas.example.com:/srv/aipbx-backups/aipbx-20261031-0230 /tmp/
```

### Step 3: Restore Configurations and Certificates
Extract the configuration archive directly to the root filesystem:
```bash
tar xzf /tmp/aipbx-20261031-0230/config.tgz -C /
```

### Step 4: Restore the MariaDB Database
Load database credentials from `/etc/ai-pbx.env` and restore the SQL dump:
```bash
DB_NAME=$(grep -m1 '^DB_NAME=' /etc/ai-pbx.env | cut -d= -f2- | tr -d "\"'")
zcat /tmp/aipbx-20261031-0230/db.sql.gz | mysql "$DB_NAME"
```

### Step 5: Reload Telephony and Web Services
Restart the core services to load the restored configurations:
```bash
systemctl restart mariadb asterisk nginx apache2 aipbx-webhook-worker
aipbx-selftest
```

The system is now fully restored with all extensions, trunks, call routing, audio prompts, TLS certificates, and historical CDR data intact.
