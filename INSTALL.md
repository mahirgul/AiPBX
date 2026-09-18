# AI PBX — Installation & Deployment Guide

This guide covers installing and configuring **AI PBX** on **Ubuntu 26.04 LTS**.

---

## System Requirements

- **Operating System**: Clean Ubuntu Server (26.04 LTS x86_64)
- **Minimum Specs**: 2 GB RAM, 2 vCPU, 15 GB SSD storage
- **Recommended Specs (Production)**: 4 GB+ RAM, 4 vCPU, 40 GB+ NVMe SSD
- **Network**: Static Public IPv4 and/or configured FQDN (e.g. `pbx.company.com`)
- **Privileges**: Root access (`sudo -i`)

---

## 🚀 1. Quick Automated Install (Recommended)

The automated script configures Asterisk 22, MariaDB 11, the Web Portal, WebRTC WSS reverse proxy, coturn, Go Chat service, and security tools in one command.

```bash
# 1. Clone the repository
git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
cd /opt/aipbx

# 2. Run the automated installer as root
sudo bash install.sh
```

### What the installer automates:
1. **Interactive FQDN & TLS Provisioning**: Prompts for your domain. If your DNS is pointed to the server, it obtains a free **Let's Encrypt TLS** certificate via Certbot. If you do not have an active domain yet, it automatically provisions a secure **self-signed TLS certificate** with SAN support.
2. **Dynamic Cryptographic Credentials**: Automatically generates unique, random secrets for:
   - MariaDB application runtime user (`aipbx_portal`)
   - MariaDB schema migration user (`aipbx_migrator`)
   - Asterisk Manager Interface (`admin` AMI secret)
   - coturn WebRTC TURN secret key
   - Web portal initial `admin` user
3. **Software Stack Setup**: Installs Asterisk 22, MariaDB 11, Web Server, PHP 8 with all necessary extensions, Go, coturn, and fail2ban.
4. **Database Migration**: Creates the schema from `db/schema.sql` and loads initial tables from `db/seed.sql`.
5. **Reverse Proxy Configuration**: Deploys reverse proxy rules for `/ws` (Asterisk WebRTC SIP) and `/chat/ws` (Go Chat).
6. **Credential Safe**: Prints full credentials in a formatted terminal summary and writes a protected file to `/root/aipbx-credentials.txt` (`chmod 600`).

---

## 🌐 2. Web Server Configuration

AI PBX supports both **Apache 2.4** and **Nginx**. The default automated installer configures Apache, but you can switch to or deploy with high-concurrency Nginx at any time.

### Option A: Apache 2.4 (Default)
Apache comes pre-configured with `mod_proxy_wstunnel` and `mod_ssl`.
- VirtualHost config: `/etc/apache2/sites-available/aipbx.conf`
- Restart / Status:
  ```bash
  sudo systemctl restart apache2
  ```

### Option B: Nginx + PHP-FPM (High-Concurrency Alternative)
For environments handling large volumes of concurrent WebRTC sessions, Nginx provides superior event-driven WebSocket scaling.

#### Step 1: Install Nginx & PHP-FPM
```bash
sudo apt-get update
sudo apt-get install -y nginx php-fpm
```

#### Step 2: Deploy the AI PBX Nginx Configuration
A production-ready template is included in the repository at [`conf/nginx/aipbx.conf.example`](file:///home/pbx/conf/nginx/aipbx.conf.example):
```bash
# Copy template
sudo cp /opt/aipbx/conf/nginx/aipbx.conf.example /etc/nginx/sites-available/aipbx.conf

# Edit server_name and SSL certificate paths to match your FQDN
sudo nano /etc/nginx/sites-available/aipbx.conf

# Enable site
sudo ln -sf /etc/nginx/sites-available/aipbx.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
```

#### Step 3: Stop Apache and Start Nginx
```bash
# Disable Apache to free port 80/443
sudo systemctl stop apache2
sudo systemctl disable apache2

# Test and start Nginx
sudo nginx -t
sudo systemctl enable nginx
sudo systemctl start nginx
```

---

## 🔒 3. Post-Installation & Verification

### 3.1 Access the Management Portal
Open your browser and navigate to:
```
https://<your-server-ip-or-domain>
```
Log in using:
- **Username**: `admin`
- **Password**: *(Found in `/root/aipbx-credentials.txt`)*

### 3.2 Service Health Check
Run the following command to verify that all system services are active and running:
```bash
systemctl status asterisk mariadb coturn aipbx-chat
# plus whichever web server you are using:
systemctl status nginx || systemctl status apache2
```

### 3.3 Firewall Configuration (UFW)
If using UFW (Uncomplicated Firewall), open the necessary ports:
```bash
# Web & Ingress
sudo ufw allow 80/tcp comment 'HTTP (ACME redirect)'
sudo ufw allow 443/tcp comment 'HTTPS Portal & WebRTC WSS'

# Asterisk SIP Signaling & Media
sudo ufw allow 5060/udp comment 'SIP UDP'
sudo ufw allow 5060/tcp comment 'SIP TCP'
sudo ufw allow 10000:20000/udp comment 'Asterisk RTP Media'

# coturn NAT Traversal
sudo ufw allow 3478/udp comment 'STUN/TURN UDP'
sudo ufw allow 3478/tcp comment 'STUN/TURN TCP'
sudo ufw allow 5349/tcp comment 'TURNS TLS'
sudo ufw allow 49152:65535/udp comment 'TURN Relay Media'

# Enable firewall
sudo ufw enable
```

### 3.4 (Optional) Let's Encrypt Certificate Renewal / Setup
If you installed with a self-signed certificate and point a domain later:
- **With Nginx**:
  ```bash
  sudo certbot --nginx -d pbx.yourdomain.com
  ```
- **With Apache**:
  ```bash
  sudo certbot --apache -d pbx.yourdomain.com
  ```

---

## 📁 Key File Locations

| Purpose | File Path |
|---------|-----------|
| Generated Credentials | `/root/aipbx-credentials.txt` |
| System Environment Secrets | `/etc/ai-pbx.env` (`chmod 640 root:www-data`) |
| Web Portal Document Root | `/var/www/html` |
| Nginx Configuration Template | `/opt/aipbx/conf/nginx/aipbx.conf.example` |
| Active Nginx VirtualHost | `/etc/nginx/sites-available/aipbx.conf` |
| Active Apache VirtualHost | `/etc/apache2/sites-available/aipbx.conf` |
| Asterisk Configuration Base | `/etc/asterisk/pbx/` |
| coturn TURN Configuration | `/etc/turnserver.conf` |
| Go Chat Systemd Unit | `/etc/systemd/system/aipbx-chat.service` |
