# AI PBX — Installation & Setup Guide

This guide covers installing AI PBX on **Ubuntu 22.04 / 24.04 / 26.04 LTS**.

---

## Prerequisites

- **Clean Ubuntu Server** (Ubuntu 22.04, 24.04, or 26.04 LTS x86_64)
- Minimum: **2 GB RAM**, **2 vCPU**, **10 GB disk**
- Static IP address or configured domain name (FQDN)
- Root (sudo) access

---

## 🚀 Quick Automated Install (Recommended)

The automated script configures Asterisk 22, MariaDB, Apache2, coturn, the Go Chat service, and security tools in one command.

```bash
# 1. Clone the repository
git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
cd /opt/aipbx

# 2. Run the installer as root
sudo bash install.sh
```

### What the installer does:
1. **Prompts for FQDN**: Enter your domain (e.g. `pbx.example.com`). If you have a domain with DNS pointing to your server, you can choose free **Let's Encrypt TLS**. If not, a valid **self-signed certificate** is created automatically.
2. **Generates Strong Passwords**: Creates cryptographically secure random passwords for:
   - MariaDB application runtime user (`aipbx_portal`)
   - MariaDB migration user (`aipbx_migrator`)
   - Asterisk AMI management interface
   - coturn WebRTC TURN secret
   - Initial web portal `admin` user
3. **Installs Packages**: MariaDB 11, Asterisk 22, Apache 2.4, PHP 8 with required extensions, Go, coturn, fail2ban, Ghostscript.
4. **Initializes Database**: Creates tables from `db/schema.sql` and populates base data from `db/seed.sql`.
5. **Configures Web Server & Reverse Proxy**: Sets up HTTPS with automatic HTTP→HTTPS redirect and WSS proxies for WebRTC and Chat.
6. **Saves Credentials**: Displays all login information in a terminal summary box and saves a protected copy to `/root/aipbx-credentials.txt` (`chmod 600`).

---

## 🔒 Post-Installation

### 1. Access the Web Portal
Open your web browser and navigate to:
```
https://<your-server-ip-or-domain>
```
Log in using:
- **Username**: `admin`
- **Password**: *(The generated password shown at the end of install.sh or found in `/root/aipbx-credentials.txt`)*

### 2. Verify Services
Check that all backend daemons are active:
```bash
systemctl status asterisk mariadb apache2 coturn aipbx-chat
```

### 3. (Optional) Obtain Let's Encrypt Certificate Later
If you used a self-signed certificate during install and want to switch to a trusted Let's Encrypt certificate after setting up DNS:
```bash
sudo certbot --apache -d pbx.yourdomain.com
```

---

## 📁 File Locations Reference

| Purpose | File Path |
|---------|-----------|
| Credentials File | `/root/aipbx-credentials.txt` |
| Environment Secrets | `/etc/ai-pbx.env` |
| Web Portal Root | `/var/www/html` → `/opt/aipbx/web` |
| Asterisk PBX Config | `/etc/asterisk/pbx/` |
| Apache VirtualHost | `/etc/apache2/sites-available/aipbx.conf` |
| TURN Config | `/etc/turnserver.conf` |
| Chat Service | `/etc/systemd/system/aipbx-chat.service` |
