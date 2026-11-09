# AiPBX — Server Command-Line Interface (CLI)

AiPBX provides dedicated command-line utilities in `/usr/local/sbin` and helper scripts in `/opt/aipbx/web/bin` for system administration, lifecycle management, disaster recovery, network configuration, and automated diagnostics.

All management commands are designed with **failsafe rollback mechanisms** and safe execution practices.

---

## 📋 Overview of Commands

| Command | Purpose | Typical Usage | Requires Root? |
|---------|---------|---------------|----------------|
| [`aipbx-update`](#aipbx-update) | Upgrades, checks, or rolls back AiPBX releases | `sudo aipbx-update` | Yes |
| [`aipbx-backup`](#aipbx-backup) | Creates full DB and configuration backups | `sudo aipbx-backup --label pre-change` | Yes |
| [`aipbx-restore`](#aipbx-restore) | Restores system state from backup archives | `sudo aipbx-restore <backup-name>` | Yes |
| [`aipbx-selftest`](#aipbx-selftest) | Immediate diagnostic check of all subsystems | `sudo aipbx-selftest` | Yes |
| [`aipbx-cert`](#aipbx-cert) | SSL/TLS certificate issuance, renewal & sync | `sudo aipbx-cert renew` | Yes |
| [`aipbx-readdress`](#aipbx-readdress) | Updates PBX configuration when server IP changes | `sudo aipbx-readdress <old-ip> <new-ip> --yes` | Yes |
| [`aipbx-netcfg`](#aipbx-netcfg) | Manages network cards, IP, gateway & routing | `sudo aipbx-netcfg status` | Yes |
| [`aipbx-vpn`](#aipbx-vpn) | WireGuard & OpenVPN server and tunnel management | `sudo aipbx-vpn status` | Yes |
| [`aipbx-ai-setup`](#aipbx-ai-setup) | Installs or removes local AI model Python venv | `sudo aipbx-ai-setup install` | Yes |
| [`aipbx-sounds`](#aipbx-sounds) | Installs official voice prompts & MOH packs | `sudo aipbx-sounds install core tr g722` | Yes |
| [`aipbx-phone-discovery`](#aipbx-phone-discovery) | Scans LAN for desk phones and provisions them | `sudo aipbx-phone-discovery` | Yes (or www-data) |
| [`aipbx-dhcp-probe`](#aipbx-dhcp-probe) | Detects competing DHCP servers on an interface | `sudo aipbx-dhcp-probe eth0` | Yes |
| [`aipbx_sso_recovery.php`](#sso-recovery) | Emergency login link when SSO provider fails | `sudo php /opt/aipbx/web/bin/aipbx_sso_recovery.php` | Yes |

---

## 🚀 `aipbx-update` — System Updating & Rollback

Updates the PBX to the latest (or a specific) published GitHub release (`git tag vX.Y.Z`).

```bash
# Check if a new release is available (prints status, updates portal status file)
sudo aipbx-update --check

# Interactive update to the latest release (asks for confirmation)
sudo aipbx-update

# Non-interactive update (used by automated pipelines and web portal)
sudo aipbx-update --yes

# Update (or rollback) to a specific release tag
sudo aipbx-update --to v1.72.10

# When an Asterisk restart is required:
sudo aipbx-update --wait-calls    # Waits until active calls finish before restarting
sudo aipbx-update --allow-calls   # Drops active calls immediately to apply update
```

### Safety & Zero-Downtime Guarantee:
- **Asterisk Reload vs Restart:** Before applying an update, `scripts/asterisk-restart-needed.sh` evaluates the diff between versions. If only web portal, dialplan generators, translations, or database migrations changed, Asterisk is **reloaded** rather than restarted. Calls in progress stay connected.
- **Automated Rollback:** If any database migration or post-install verification step fails, code, database, and system configs are restored to the previous release automatically.

---

## 💾 `aipbx-backup` — Database & Configuration Backups

Creates a point-in-time backup containing the full MariaDB database dump, Asterisk configuration files, environment variables, SSL certificates, custom audio prompts, MOH, and VPN keys.

```bash
# Standard backup (stored in /var/backups/aipbx-daily/aipbx-YYYYmmdd-HHMM/)
sudo aipbx-backup

# Labelled backup (e.g. before major maintenance or migration)
sudo aipbx-backup --label pre-cutover
# Creates: /var/backups/aipbx-daily/aipbx-20261109-1430-pre-cutover/
```

- **Backup Content:**
  - `db.sql.gz`: Compressed full MariaDB dump of `asterisk` database.
  - `config.tgz`: `/etc/ai-pbx.env`, `/etc/asterisk`, `/etc/ssl/aipbx`, uploaded audio files, VPN keys, and secret keys.
- **Retention:** Automatically purges backups older than the retention setting configured in the portal (**System → Backup**) or `AIPBX_BACKUP_KEEP_DAYS`.

---

## 🔄 `aipbx-restore` — Disaster Recovery & Restore

Restores an existing backup or imports an external backup archive.

```bash
# List available backups
ls -la /var/backups/aipbx-daily/

# Restore a specific backup
sudo aipbx-restore aipbx-20261109-1430-pre-cutover

# Restore only the database (keeps current config files intact)
sudo aipbx-restore aipbx-20261109-1430-pre-cutover --db-only

# Import and restore an uploaded backup file from another server
sudo aipbx-restore --import /tmp/aipbx-backup-transfer.tar.gz
```

> [!NOTE]
> `aipbx-restore` automatically takes a safety backup of the current state before restoring. If the restore encounters any error, it rolls back to the safety backup immediately.

---

## 🩺 `aipbx-selftest` — Instant System Health Check

Runs all health checks and prints the current status of all PBX subsystems directly to the terminal.

```bash
sudo aipbx-selftest
```

- **What it checks:** Service statuses (Asterisk, MariaDB, Chat, AI, Webhook worker), trunk registration states, disk usage, memory pressure, SSL certificate expiry, backup age, and firewall jail status.
- **Exit Code:** Returns `0` if all checks pass; returns `1` if any warning or error is detected (ideal for external monitoring scripts like Zabbix, Nagios, or Prometheus Node Exporter).
- **Silent:** Does not send notification emails or alter portal alert acknowledgment states.

---

## 🔒 `aipbx-cert` — SSL/TLS Certificate Management

Manages certificates for Apache (web portal), Asterisk (SIP-TLS, WebRTC WSS), and coturn (TURNS). The active certificate and private key are stored in `/etc/ssl/aipbx/active.{crt,key}` and synchronized with the respective system services.

```bash
# Request a Let's Encrypt certificate for the server domain
sudo aipbx-cert letsencrypt --email admin@example.com

# Test Let's Encrypt issuance with dry-run
sudo aipbx-cert letsencrypt --dry-run

# Renew existing Let's Encrypt certificate and reload Asterisk/coturn/Apache
sudo aipbx-cert renew

# Generate and install self-signed certificates (useful for lab / intranet setups)
sudo aipbx-cert selfsigned
```

---

## 🌐 `aipbx-readdress` — Server IP Migration Tool

When the physical or virtual IP address of the AiPBX server changes, this tool updates all references across Asterisk transports, PJSIP endpoints, portal configurations, and phone provisioning files.

```bash
# Dry run: preview what files and configurations would change without applying
sudo aipbx-readdress 192.168.1.100 192.168.1.200

# Apply IP change system-wide
sudo aipbx-readdress 192.168.1.100 192.168.1.200 --yes
```

---

## 🖧 `aipbx-netcfg` — Network Interface & Static Routing

Manages Linux network interfaces, Netplan configurations, secondary routing tables, and default gateways with safety timeouts.

```bash
# Show current interfaces, IP assignments, and modes
sudo aipbx-netcfg status

# Configure static IP on an interface with fallback safety
sudo aipbx-netcfg apply iface=eth0 mode=static address=192.168.1.50/24 gateway=192.168.1.1 dns=1.1.1.1,8.8.8.8 default=1

# Confirm configuration (if connectivity works)
sudo aipbx-netcfg confirm

# Revert immediately if something is wrong
sudo aipbx-netcfg revert
```

---

## 🔐 `aipbx-vpn` — VPN Server and Client Management

Manages WireGuard and OpenVPN site-to-site tunnels and client connections.

```bash
# Check status of VPN interfaces (aipbx-wg0, aipbx-tun0)
sudo aipbx-vpn status
```

- Operates with strict isolation: client connections never override the PBX default gateway, preventing external traffic hijacking while keeping trunk media intact.

---

## 🧠 `aipbx-ai-setup` — On-Device AI Engine Management

Installs or purges the dedicated Python virtual environment and ONNX Runtime for local AI models (such as EMA Lightning TTS or Whisper speech recognition).

```bash
# Check current AI runtime status
sudo aipbx-ai-setup status

# Install / update the local AI runtime environment in /opt/aipbx-ai/venv
sudo aipbx-ai-setup install

# Remove AI virtual environment and downloaded models to reclaim disk space
sudo aipbx-ai-setup remove
```

---

## 🔊 `aipbx-sounds` — Voice Prompts & Music On Hold Packs

Installs or removes official voice sound packages from downloads.asterisk.org and AiPBX Turkish voice prompt packs.

```bash
# Install Turkish high-definition prompts (G.722)
sudo aipbx-sounds install core tr g722

# Install English standard prompts (alaw/ulaw)
sudo aipbx-sounds install core en alaw

# Install Opsound Music on Hold
sudo aipbx-sounds install moh opsound wav

# Remove a sound package
sudo aipbx-sounds remove core tr g722
```

---

## ☎️ `aipbx-phone-discovery` & `aipbx-dhcp-probe`

### Desk Phone Discovery:
```bash
# Run one discovery cycle to detect phones on LAN (LLDP/ARP/DHCP)
sudo aipbx-phone-discovery
```

### Rogue DHCP Detection:
```bash
# Send a DHCPDISCOVER on an interface and list any responding DHCP servers
sudo aipbx-dhcp-probe eth1
```
Use this before enabling AiPBX's built-in DHCP server (**Network Services**) to verify no conflicting DHCP server is active on the voice VLAN.

---

## 🚨 Emergency Recovery & Helper Scripts

### Single Sign-On (SSO) Emergency Recovery:
If SSO is enforced (`auth_sso_only = 1`) and your identity provider (Google, Microsoft, Keycloak) is down:

```bash
# Generate a one-time 15-minute emergency admin login URL:
sudo php /opt/aipbx/web/bin/aipbx_sso_recovery.php

# Turn off SSO enforcement so administrators can log in with local username/password:
sudo php /opt/aipbx/web/bin/aipbx_sso_recovery.php --off
```

### Asterisk Management:
```bash
# Connect to interactive Asterisk console with verbose output
sudo asterisk -rvvv

# Reload dialplan without affecting active calls
sudo asterisk -rx "dialplan reload"

# Reload PJSIP endpoints and trunks
sudo asterisk -rx "pjsip reload"
```

### Service Control & Logs:
```bash
# View status of AiPBX services
sudo systemctl status aipbx-chat
sudo systemctl status aipbx-ai
sudo systemctl status aipbx-signaling
sudo systemctl status aipbx-webhook-worker

# Live log inspection
sudo journalctl -u aipbx-chat -f
sudo journalctl -u aipbx-ai -f
sudo journalctl -u asterisk -f
```
