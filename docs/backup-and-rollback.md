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

## 2. Updates and automatic rollback (`aipbx-update`)

Update from **System → System Update** or on the server:

```
sudo aipbx-update                 # the latest release (asks first)
sudo aipbx-update --to v1.72.2    # a given release
sudo aipbx-update --check         # is a newer release out?
sudo aipbx-update --yes           # no question (automation); add one of the two below
sudo aipbx-update --wait-calls    # if Asterisk must restart: wait until the calls end
sudo aipbx-update --allow-calls   # if Asterisk must restart: end the calls
```

**Calls.** Asterisk restarts only when the release needs it (the release notes say so); otherwise it
is reloaded and calls stay up. When a restart is needed, you choose: wait for the calls to end or end
them. While it waits, the System Update page offers *End the calls now* and *Cancel the update*.
At a terminal `aipbx-update` asks; with `--yes` and neither option it stops instead.

**The steps:**

1. **Backup**: the database, `/etc/ai-pbx.env` and `/etc/asterisk` into `/var/backups/aipbx/`
   (the last few are kept). If the database backup fails, nothing is changed.
2. **Check out** the release tag and run `install.sh --upgrade` (migrations, permissions, services;
   secrets are never made again).
3. **Check**: `mariadb`, `apache2`, `nginx`, `asterisk` and `aipbx-webhook-worker` must be running
   (checked twice, so a service that keeps crashing is caught), and the portal must answer `/login`
   with HTTP 200.
4. **Page test afterwards**: `web/bin/smoke.php` tests every page in the background (minutes on a
   one-CPU server). It decides nothing: if it finds a problem, the update stays and the System Update
   page says so; the details are in `/var/log/aipbx/update.log`.

**If step 2 or 3 fails**, the update rolls back by itself: the previous code, database and Asterisk
configuration come back, and the previous version keeps running.

**Files changed by hand** do not stop an update. They go into the backup and stay in place, unless
the new release changes the same file: then the new version is used and the log names the file.

Questions to Asterisk and restarts have a time limit, so a stuck service cannot hang the update, and
an update that died (in a reboot, for example) does not block the next one.

---

## 3. The Backup & Restore Page

**System → Backup & Restore** is for administrators only. It lists the backups, and from there you can:

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
