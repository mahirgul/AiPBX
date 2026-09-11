# AI PBX — System Architecture & Design

AI PBX is a modern, enterprise-grade IP PBX system integrating Asterisk 22, a strictly layered PHP 8 Web Portal, Go real-time messaging, and a native Kotlin Android application with dual-engine PJSIP/WebRTC communication.

---

## 1. High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              CLIENT LAYER                                   │
│  ┌───────────────────────────┐         ┌─────────────────────────────────┐  │
│  │   Android Mobile App      │         │   Web Browser Client            │  │
│  │   (Kotlin + WebRTC/SIP)   │         │   (WebRTC Softphone + Admin)    │  │
│  └─────────────┬─────────────┘         └────────────────┬────────────────┘  │
└────────────────┼────────────────────────────────────────┼───────────────────┘
                 │ HTTPS / WSS / TLS                      │ HTTPS / WSS
                 ▼                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            ENTRY & PROXY LAYER                              │
│  Apache 2.4 (SSL/TLS Termination, WebSocket Proxy, HTTP Routing)             │
│   ├── /                 → /var/www/html (PHP 8 MVC Portal)                  │
│   ├── /ws               → ws://127.0.0.1:8088/ws (Asterisk WebRTC SIP WS)    │
│   └── /chat/ws          → ws://127.0.0.1:9090/ws (Go Chat WebSocket)        │
└─────────────────────────────────────────────────────────────────────────────┘
                 │                                        │
        ┌────────┴────────┐                      ┌────────┴────────┐
        ▼                 ▼                      ▼                 ▼
┌──────────────┐   ┌──────────────┐       ┌──────────────┐   ┌──────────────┐
│  PHP 8 Web   │   │ Asterisk 22  │       │  Go Chat     │   │ coturn       │
│  Management  │   │ VoIP Engine  │       │  Service     │   │ TURN/STUN    │
│  Portal      │   │ (PJSIP, Fax) │       │  (Port 9090) │   │ (Port 5349)  │
└───────┬──────┘   └──────┬───────┘       └──────┬───────┘   └──────────────┘
        │                 │                      │
        │ AMI (Port 5038) │                      │
        ├─────────────────┘                      │
        │                                        │
        ▼                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              DATA LAYER                                     │
│  MariaDB 11 (utf8mb4_unicode_ci)                                            │
│   ├── aipbx_portal   (Runtime DML: SELECT, INSERT, UPDATE, DELETE)          │
│   └── aipbx_migrator (Phinx DDL: Schema migrations)                         │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Core Components

### 2.1 VoIP Engine (Asterisk 22)
- **SIP Stack**: `res_pjsip` with modular configuration under `/etc/asterisk/pbx/`.
- **Dual-Endpoint Architecture**: Each extension has two paired endpoints:
  - Standard SIP endpoint (`{ext}-sip` / port 5060 UDP) for desk IP phones.
  - WebRTC endpoint (`{ext}-webrtc` / port 8088 WSS) for browser softphone & mobile.
- **Audio Codecs**: Opus (WebRTC), G.722 (HD Voice), G.711 A-law / U-law.
- **Fax Engine**: `res_fax` + SpanDSP for T.38 and G.711 fax transmission.
- **Management**: Asterisk Manager Interface (AMI) on 127.0.0.1:5038 for live call control and queue monitoring.

### 2.2 Web Management Portal (PHP 8 MVC)
- **Design Pattern**: Strict MVC (Controller → Service → Repository → Model).
- **Security**:
  - CSRF validation on all state-changing requests.
  - Rate limiting & brute-force lockout.
  - Nonce-based authentication for SIP credentials (never embedded in HTML).
  - RBAC (Role-Based Access Control) for Admins, Operators, and Standard Users.
- **Sync Engine**: Auto-generates Asterisk `.conf` files on change and performs reload with automatic rollback if reload fails.

### 2.3 Android Mobile App (Kotlin)
- **Engine**: Headless WebRTC WebView running JsSIP with DTLS-SRTP and Opus audio.
- **Background Persistence**: Foreground Service with wake/wifi locks, compatible with Android 14/15 Doze modes.
- **Push Notification**: FCM (Firebase Cloud Messaging) high-priority wake-up for incoming calls when app is idle.
- **Features**: Dialpad, Call History, Corporate & Device Contacts, In-App Chat, Diagnostics & Log Viewer.

### 2.4 Instant Messaging Service (Go)
- Standalone high-performance Go WebSocket hub listening on `127.0.0.1:9090`.
- Proxied through Apache `/chat/ws`.
- Supports 1-on-1 direct messaging, read receipts, typing indicators, and presence status.

### 2.5 NAT Traversal (coturn)
- Secure STUN/TURN server running on port 3478 (STUN) and port 5349 (TURNS).
- Short-term credentials generated dynamically by the web portal using HMAC-SHA1.

---

## 3. Network Ports & Firewall Rules

| Port | Protocol | Service | Access |
|------|----------|---------|--------|
| 80 | TCP | Apache HTTP (Redirects to HTTPS) | Public |
| 443 | TCP | Apache HTTPS (Web Portal + WSS) | Public |
| 5060 | UDP | Asterisk SIP Signaling | Internal / Trusted IP |
| 10000-20000 | UDP | Asterisk RTP Media Streams | Public |
| 3478 | UDP/TCP | coturn STUN/TURN | Public |
| 5349 | TCP | coturn TLS TURNS | Public |
| 49152-65535 | UDP | coturn Relay Media | Public |
| 5038 | TCP | Asterisk AMI | Localhost only |
| 9090 | TCP | Go Chat Service | Localhost only |
| 3306 | TCP | MariaDB Server | Localhost only |

---

## 4. Security Highlights

- **Principle of Least Privilege**: The web application connects via `aipbx_portal` (restricted to DML). DDL commands are isolated to database migration scripts using `aipbx_migrator`.
- **Environment Segregation**: All secrets (DB passwords, AMI keys, TURN secret) are kept in `/etc/ai-pbx.env` (permissions: `640 root:www-data`), never committed to version control.
- **fail2ban Integration**: Monitors Asterisk registration failures and Web Portal login brute-force attempts.
