<p align="center">
  <a href="https://mahirgul.github.io/AiPBX/">
    <img src="docs/logo.png" alt="AI PBX Logo" width="130">
  </a>
</p>

<h1 align="center">AI PBX</h1>

<p align="center">
  <strong>Open-Source Enterprise IP PBX Management Portal & Unified Communications</strong><br>
  Asterisk 22 · PHP 8 · MariaDB · WebRTC · Instant Messaging · Android App
</p>

<p align="center">
  <a href="https://mahirgul.github.io/AiPBX/">🌐 <strong>Official Website & Documentation: mahirgul.github.io/AiPBX</strong></a>
</p>

<p align="center">
  <a href="https://github.com/mahirgul/AiPBX/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License"></a>
  <img src="https://img.shields.io/badge/Ubuntu-22.04%20%7C%2024.04%20%7C%2026.04-E95420?logo=ubuntu&logoColor=white" alt="Ubuntu LTS">
  <img src="https://img.shields.io/badge/Asterisk-22-green" alt="Asterisk 22">
  <img src="https://img.shields.io/badge/PHP-8.x-blue?logo=php" alt="PHP 8">
  <img src="https://img.shields.io/badge/MariaDB-11-blue?logo=mariadb" alt="MariaDB">
  <a href="https://mahirgul.github.io/AiPBX/"><img src="https://img.shields.io/badge/GitHub%20Pages-Live-success?logo=github" alt="GitHub Pages"></a>
</p>

<p align="center">
  <a href="#quick-install">Quick Install</a> •
  <a href="#features">Features</a> •
  <a href="#architecture">Architecture</a> •
  <a href="https://mahirgul.github.io/AiPBX/">Website</a> •
  <a href="#contributing">Contributing</a>
</p>

---

## Quick Install

```bash
# Clone the repository
git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
cd /opt/aipbx

# Run the installer as root
sudo bash install.sh
```

The installer will:
1. Ask for your **FQDN** (domain name) — or use your IP with a self-signed cert
2. Generate **strong random passwords** for all services automatically
3. Install and configure everything (Asterisk, MariaDB, Apache2, coturn, Chat service)
4. Display all credentials at the end and save them to `/root/aipbx-credentials.txt`

> **Requirements**: Ubuntu 22.04 / 24.04 / 26.04 LTS · 2 GB RAM · 10 GB disk · root access

After install, open `https://<your-server>` in your browser and log in with the credentials shown.

---

## Features

### 📞 PBX Management
- **Extension management** — PJSIP-based, dual-endpoint (SIP + WebRTC)
- **Trunk management** — dynamic PJSIP trunk configuration
- **Call routing** — DID mapping, outbound routes, time conditions
- **IVR** — multi-level voice menus with time-based routing
- **Queue management** — call queues, agent login/logout, hold music
- **Feature codes** — *72 call forward, *60 DND, *43 intercom, etc.
- **Auto-rollback** — failed Asterisk reloads are automatically reverted

### 📠 Fax System
- **Inbound/outbound fax** — T.38 and G.711 (res_fax + SpanDSP)
- **WYSIWYG fax editor** — compose rich-text faxes directly in the browser
- **PDF upload & send**
- **Fax retry**
- **Email notification** — incoming faxes forwarded by email automatically

### 📊 Call Center
- **Real-time agent panel** — live queue status, active calls
- **Agent login/logout/break** — web-controlled
- **CDR reporting** — detailed call records, filtering, export
- **Call recording playback** — listen to recordings in the browser

### 🌐 WebRTC Softphone
- **In-browser SIP phone** — no additional software required
- **TURN/STUN support** — works reliably behind NAT (coturn)
- **Opus + DTLS-SRTP** — high-quality, encrypted audio

### 📱 Android App
- **Native Kotlin** application
- **PJSIP + WebRTC** dual engine
- **FCM push notifications** — wake device for incoming calls
- **Instant messaging (Chat)** — Go-based WebSocket backend

### 🔒 Security
- **RBAC** — role-based access control
- **Math CAPTCHA** + brute-force lockout (5 failures → 15-min IP ban)
- **CSRF protection** — token on every POST form
- **fail2ban integration**
- **Firewall management** — control firewalld/fail2ban from the web UI
- **Credentials served via API** — never embedded in page source

### 🌍 Multi-language
- Turkish 🇹🇷 and English 🇬🇧 (1,300+ translation keys)
- Easy to extend with the `t()` function

---

## Architecture

```
AiPBX/
├── web/                    # PHP MVC Web Portal
│   ├── src/
│   │   ├── controllers/    # 35 page controllers
│   │   ├── services/       # 24 business logic services
│   │   ├── repositories/   # 28 database repositories
│   │   └── sync/           # 13 Asterisk config generators
│   ├── templates/views/    # 34 PHP view templates
│   ├── api/                # REST API layer
│   ├── assets/             # CSS, JS, fonts
│   ├── lang/               # Language files (tr/en)
│   └── db/migrations/      # Phinx database migrations
│
├── android/                # Kotlin Android App
│   └── app/src/main/
│       └── java/com/mhrgl/aipbx/
│
├── chat/                   # Go WebSocket Chat Service
│   ├── main.go
│   ├── hub.go              # WebSocket hub
│   ├── handlers.go         # HTTP/WS handlers
│   └── db.go               # Database layer
│
├── asterisk-config/        # Asterisk reference configuration
│   └── pbx/                # Modular dialplan, PJSIP, queue files
│
├── db/                     # Database schema
│   ├── schema.sql          # Table definitions
│   └── seed.sql            # Initial seed data
│
├── install.sh              # One-command installer
└── README.md
```

### Technology Stack

| Layer | Technology |
|-------|-----------|
| PBX | Asterisk 22 (PJSIP, res_fax, AMI, ODBC) |
| Web Backend | PHP 8.x, strict MVC, Composer |
| Web Frontend | Vanilla JS + CSS (no framework) |
| Database | MariaDB (Phinx migrations) |
| Chat | Go + gorilla/websocket |
| Android | Kotlin, PJSIP, WebRTC, FCM |
| WebRTC | coturn TURN/STUN, DTLS-SRTP, Opus |
| Security | fail2ban, RBAC, CSRF, CAPTCHA |

---

## Post-Install

All credentials (admin password, DB passwords, AMI key, TURN secret) are generated randomly during install and saved to `/root/aipbx-credentials.txt`.

You can change all passwords later from **Admin Panel → Settings → System**.

To add a real TLS certificate after install (if you skipped Let's Encrypt):
```bash
certbot --apache -d your-domain.com
```

---

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'Add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

---

## License

This project is licensed under the [MIT License](LICENSE).

---

## Author

**Mahir Gül** · [@mahirgul](https://github.com/mahirgul)
